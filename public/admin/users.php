<?php
/**
 * users.php — Admin: Manage Users.
 *
 * Lists all registered users. Clicking a row expands an inline accordion
 * panel allowing the admin to edit the user's name, email, institution,
 * department, role, and account status, or send a password reset email.
 * Only one accordion panel may be open at a time.
 *
 * POST actions handled:
 *   approve       — Activate a pending user account.
 *   reject        — Delete a pending (unactivated) user account.
 *   edit_user     — Save edits to a user's account fields.
 *   reset_password — Generate a reset token and send a password reset email.
 */

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/mailer.php';
require_once __DIR__ . '/../../vendor/autoload.php';

session_start_secure();
require_admin();

$db = get_db();

// ── POST handler ─────────────────────────────────────────────────────────────

$flash = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action  = $_POST['action']  ?? '';
    $user_id = (int)($_POST['user_id'] ?? 0);

    if ($user_id > 0) {

        // ── Approve pending user ──────────────────────────────────────────
        if ($action === 'approve') {
            $db->prepare(
                'UPDATE users SET is_active = 1, approved_by = ?, approved_at = NOW()
                 WHERE user_id = ?'
            )->execute([$_SESSION['user_id'], $user_id]);
            $flash = ['type' => 'success', 'msg' => 'User approved successfully.'];

        // ── Reject / delete pending user ──────────────────────────────────
        } elseif ($action === 'reject') {
            $db->prepare('DELETE FROM users WHERE user_id = ? AND is_active = 0')
               ->execute([$user_id]);
            $flash = ['type' => 'success', 'msg' => 'User rejected and removed.'];

        // ── Edit user account fields ──────────────────────────────────────
        } elseif ($action === 'edit_user') {
            $first_name     = trim($_POST['first_name']     ?? '');
            $last_name      = trim($_POST['last_name']      ?? '');
            $email          = trim($_POST['email']          ?? '');
            $institution_id = (int)($_POST['institution_id'] ?? 0) ?: null;
            $dept_raw       = $_POST['department_id'] ?? '';
            $new_dept_name  = trim($_POST['new_department'] ?? '');
            $department_id  = ($dept_raw !== '__new__') ? ((int)$dept_raw ?: null) : null;
            $role           = $_POST['role'] === 'admin' ? 'admin' : 'user';
            $is_active      = isset($_POST['is_active']) ? 1 : 0;

            $errors = [];

            if (empty($first_name)) $errors[] = 'First name is required.';
            if (empty($last_name))  $errors[] = 'Last name is required.';
            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'A valid email address is required.';
            }

            // Fetch current user record for comparison
            $current = null;
            if (empty($errors)) {
                $stmt = $db->prepare(
                    'SELECT email, first_name, last_name FROM users WHERE user_id = ?'
                );
                $stmt->execute([$user_id]);
                $current = $stmt->fetch();
            }

            // Check email uniqueness against other accounts (exclude this user)
            if (empty($errors)) {
                $stmt = $db->prepare(
                    'SELECT user_id FROM users WHERE email = ? AND user_id != ?'
                );
                $stmt->execute([$email, $user_id]);
                if ($stmt->fetch()) {
                    $errors[] = 'That email address is already in use by another account.';
                }
            }

            // Validate new department name if sentinel was selected
            if ($dept_raw === '__new__') {
                if (empty($new_dept_name)) {
                    $errors[] = 'Please enter a name for the new department.';
                } elseif (!$institution_id) {
                    $errors[] = 'An institution must be selected before adding a new department.';
                }
            }

            if ($errors) {
                $flash = ['type' => 'danger', 'msg' => implode(' ', $errors)];
            } else {
                // ── Create new department if requested ────────────────────
                if ($dept_raw === '__new__' && $institution_id && $new_dept_name !== '') {
                    // Check it doesn't already exist for this institution
                    $stmt = $db->prepare(
                        'SELECT department_id FROM departments
                         WHERE institution_id = ? AND LOWER(department) = LOWER(?)'
                    );
                    $stmt->execute([$institution_id, $new_dept_name]);
                    $existing_dept = $stmt->fetch();

                    if ($existing_dept) {
                        // Re-use the existing one rather than creating a duplicate
                        $department_id = (int)$existing_dept['department_id'];
                    } else {
                        $db->prepare(
                            'INSERT INTO departments
                                 (institution_id, department, is_approved,
                                  approved_by, approved_at, created_by, created_at)
                             VALUES (?, ?, 1, ?, NOW(), ?, NOW())'
                        )->execute([
                            $institution_id,
                            $new_dept_name,
                            $_SESSION['user_id'],
                            $_SESSION['user_id'],
                        ]);
                        $department_id = (int)$db->lastInsertId();
                    }
                }

                $email_changed = $current && strtolower($email) !== strtolower($current['email']);

                // ── Save all non-email fields directly ────────────────────
                $db->prepare(
                    'UPDATE users
                     SET first_name      = ?,
                         last_name       = ?,
                         institution_id  = ?,
                         department_id   = ?,
                         role            = ?,
                         is_active       = ?,
                         updated_at      = NOW()
                     WHERE user_id = ?'
                )->execute([
                    $first_name,
                    $last_name,
                    $institution_id,
                    $department_id,
                    $role,
                    $is_active,
                    $user_id,
                ]);

                // ── Route email change through verification ────────────────
                if ($email_changed) {
                    // Cancel any existing pending email change for this user
                    $db->prepare(
                        "UPDATE user_change_requests
                         SET status = 'expired'
                         WHERE user_id = ? AND field = 'email' AND status = 'pending'"
                    )->execute([$user_id]);

                    // Create a new change request
                    $raw_token    = bin2hex(random_bytes(32));
                    $hashed_token = hash('sha256', $raw_token);
                    $expires      = date('Y-m-d H:i:s', time() + 86400); // 24 hours

                    $db->prepare(
                        "INSERT INTO user_change_requests
                             (user_id, field, old_value, new_value, status,
                              verification_token, token_expires, requested_at,
                              reviewed_by)
                         VALUES (?, 'email', ?, ?, 'pending', ?, ?, NOW(), ?)"
                    )->execute([
                        $user_id,
                        $current['email'],
                        $email,
                        $hashed_token,
                        $expires,
                        $_SESSION['user_id'],   // admin who initiated the change
                    ]);

                    $name = trim($first_name . ' ' . $last_name);
                    $sent = send_email_change_verification($email, $name, $raw_token);

                    $flash = $sent
                        ? ['type' => 'success', 'msg' =>
                            'Account updated. A verification email has been sent to '
                            . htmlspecialchars($email)
                            . '. The email address will not change until the user verifies it.']
                        : ['type' => 'warning', 'msg' =>
                            'Account updated, but the verification email could not be sent to '
                            . htmlspecialchars($email)
                            . '. Check mail configuration.'];
                } else {
                    $flash = ['type' => 'success', 'msg' => 'User account updated successfully.'];
                }
            }

        // ── Send password reset email ─────────────────────────────────────
        } elseif ($action === 'reset_password') {
            $stmt = $db->prepare(
                'SELECT email, first_name, last_name FROM users WHERE user_id = ?'
            );
            $stmt->execute([$user_id]);
            $target = $stmt->fetch();

            if ($target) {
                $raw_token    = bin2hex(random_bytes(32));
                $hashed_token = hash('sha256', $raw_token);
                $expires      = date('Y-m-d H:i:s', time() + 900); // 15 minutes

                $db->prepare(
                    'UPDATE users
                     SET password_reset_token   = ?,
                         password_reset_expires = ?
                     WHERE user_id = ?'
                )->execute([$hashed_token, $expires, $user_id]);

                $name = trim($target['first_name'] . ' ' . $target['last_name']);
                $sent = send_password_reset($target['email'], $name, $raw_token);

                $flash = $sent
                    ? ['type' => 'success', 'msg' => 'Password reset email sent to ' . htmlspecialchars($target['email']) . '.']
                    : ['type' => 'danger',  'msg' => 'Failed to send password reset email. Check mail configuration.'];
            }
        }
    }

    // Preserve which accordion row was open across the redirect so the
    // admin doesn't lose their place after saving.
    $open = (int)($_POST['open_user_id'] ?? 0);
    header('Location: /admin/users.php' . ($open ? '?open=' . $open : ''));
    exit;
}

// ── Fetch users ───────────────────────────────────────────────────────────────

$users = $db->query(
    'SELECT u.user_id, u.username, u.first_name, u.last_name,
            u.email, u.role, u.is_active, u.email_verified,
            u.created_at, u.last_login_at,
            u.institution_id, u.department_id,
            i.institution_abbr
     FROM users u
     LEFT JOIN institutions i ON u.institution_id = i.institution_id
     ORDER BY u.is_active ASC, u.created_at DESC'
)->fetchAll();

// Fetch all approved institutions for the edit dropdowns
$institutions = $db->query(
    'SELECT institution_id, institution, institution_abbr
     FROM institutions
     WHERE is_approved = 1
     ORDER BY institution ASC'
)->fetchAll();

// Fetch flash message stored in session (survives the redirect)
if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

// Which row should be open on page load (preserved across POST redirect)
$open_user_id = (int)($_GET['open'] ?? 0);

open_layout('Manage Users');
?>

<div class="pp-page-title-row">
    <div>
        <p class="pp-section-label" style="margin-bottom: 0.25rem;">Admin</p>
        <h1 class="pp-page-title-large">Manage users</h1>
        <p class="pp-lead" style="margin-top: 0.5rem; margin-bottom: 0;">
            Review registrations, edit accounts, and manage roles and access.
        </p>
    </div>
</div>

<?php if ($flash): ?>
<div class="pp-alert pp-alert-<?= htmlspecialchars($flash['type']) ?>" style="margin-bottom: 1.5rem;">
    <?= htmlspecialchars($flash['msg']) ?>
</div>
<?php endif; ?>

<div class="pp-panel pp-panel--flush">
    <div class="pp-panel-header">
        <h2 class="pp-panel-header-title">
            <?= count($users) ?> user<?= count($users) !== 1 ? 's' : '' ?>
        </h2>
    </div>

<?php if (empty($users)): ?>
    <div class="pp-empty">No users found.</div>
<?php else: ?>

<div class="pp-table-wrap">
    <table class="pp-table" id="users-table">
        <thead>
            <tr>
                <th>Username</th>
                <th>Name</th>
                <th>Email</th>
                <th>Institution</th>
                <th>Role</th>
                <th>Verified</th>
                <th>Account</th>
                <th>Registered</th>
                <th>Last Login</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($users as $u):
            $row_open = ($open_user_id === (int)$u['user_id']);
        ?>

            <!-- ── Summary row ──────────────────────────────────────────── -->
            <tr class="user-row <?= $row_open ? 'table-active' : '' ?>"
                data-user-id="<?= $u['user_id'] ?>"
                role="button"
                title="Click to expand">
                <td><?= htmlspecialchars($u['username']) ?></td>
                <td><?= htmlspecialchars(trim($u['first_name'] . ' ' . $u['last_name'])) ?></td>
                <td><?= htmlspecialchars($u['email']) ?></td>
                <td><?= htmlspecialchars($u['institution_abbr'] ?? '—') ?></td>
                <td>
                    <span class="pp-badge <?= $u['role'] === 'admin' ? 'pp-badge-danger' : 'pp-badge-muted' ?>">
                        <?= htmlspecialchars($u['role']) ?>
                    </span>
                </td>
                <td>
                    <?php if ($u['email_verified']): ?>
                        <span class="pp-badge pp-badge-success">Verified</span>
                    <?php else: ?>
                        <span class="pp-badge pp-badge-muted">Unverified</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($u['is_active']): ?>
                        <span class="pp-badge pp-badge-success">Active</span>
                    <?php else: ?>
                        <span class="pp-badge pp-badge-warning">Pending</span>
                    <?php endif; ?>
                </td>
                <td class="pp-status-detail"><?= htmlspecialchars($u['created_at']) ?></td>
                <td class="pp-status-detail">
                    <?= $u['last_login_at'] ? htmlspecialchars($u['last_login_at']) : '—' ?>
                </td>
                <td style="text-align: right;">
                    <span class="pp-status-detail accordion-chevron">
                        <?= $row_open ? '▲' : '▼' ?>
                    </span>
                </td>
            </tr>

            <!-- ── Accordion detail row ─────────────────────────────────── -->
            <tr class="accordion-row" id="accordion-<?= $u['user_id'] ?>"
                style="<?= $row_open ? '' : 'display:none;' ?>">
                <td colspan="10" style="padding: 0;">
                    <div class="pp-accordion-panel">

                        <?php if (!$u['is_active']): ?>
                        <!-- Pending approval banner -->
                        <div class="pp-alert pp-alert-warning">
                            <strong>Pending approval.</strong>
                            This account is awaiting administrator approval.
                        </div>
                        <?php endif; ?>

                        <form method="POST" action="/admin/users.php" novalidate>
                            <input type="hidden" name="action"       value="edit_user">
                            <input type="hidden" name="user_id"      value="<?= $u['user_id'] ?>">
                            <input type="hidden" name="open_user_id" value="<?= $u['user_id'] ?>">

                            <div class="row g-3">

                                <!-- First name -->
                                <div class="col-md-3">
                                    <label class="pp-label">First Name</label>
                                    <input type="text" name="first_name"
                                           class="pp-input tracked"
                                           value="<?= htmlspecialchars($u['first_name']) ?>"
                                           required>
                                </div>

                                <!-- Last name -->
                                <div class="col-md-3">
                                    <label class="pp-label">Last Name</label>
                                    <input type="text" name="last_name"
                                           class="pp-input tracked"
                                           value="<?= htmlspecialchars($u['last_name']) ?>"
                                           required>
                                </div>

                                <!-- Email -->
                                <div class="col-md-4">
                                    <label class="pp-label">Email Address</label>
                                    <input type="email" name="email"
                                           class="pp-input tracked"
                                           value="<?= htmlspecialchars($u['email']) ?>"
                                           required>
                                </div>

                                <!-- Role -->
                                <div class="col-md-2">
                                    <label class="pp-label">Role</label>
                                    <select name="role" class="pp-select tracked"
                                            data-user-id="<?= $u['user_id'] ?>">
                                        <option value="user"  <?= $u['role'] === 'user'  ? 'selected' : '' ?>>User</option>
                                        <option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                                    </select>
                                </div>

                                <!-- Institution -->
                                <div class="col-md-4">
                                    <label class="pp-label">Institution</label>
                                    <select name="institution_id"
                                            class="pp-select tracked"
                                            id="inst-select-<?= $u['user_id'] ?>"
                                            data-user-id="<?= $u['user_id'] ?>">
                                        <option value="">— None —</option>
                                        <?php foreach ($institutions as $inst): ?>
                                        <option value="<?= $inst['institution_id'] ?>"
                                            <?= (int)$u['institution_id'] === (int)$inst['institution_id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($inst['institution_abbr']
                                                . ' — ' . $inst['institution']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <!-- Department (populated via JS) -->
                                <div class="col-md-4">
                                    <label class="pp-label">Department</label>
                                    <select name="department_id"
                                            class="pp-select tracked"
                                            id="dept-select-<?= $u['user_id'] ?>"
                                            data-user-id="<?= $u['user_id'] ?>">
                                        <option value="">— None —</option>
                                    </select>
                                    <div id="new-dept-wrap-<?= $u['user_id'] ?>"
                                         class="mt-1" style="display:none;">
                                        <input type="text"
                                               name="new_department"
                                               id="new-dept-input-<?= $u['user_id'] ?>"
                                               class="pp-input tracked"
                                               data-user-id="<?= $u['user_id'] ?>"
                                               placeholder="New department name"
                                               autocomplete="off">
                                        <div class="pp-field-hint">
                                            This department will be created and auto-approved.
                                        </div>
                                    </div>
                                </div>

                                <!-- Account status -->
                                <div class="col-md-2 d-flex align-items-end pb-1">
                                    <div class="form-check">
                                        <input class="form-check-input tracked" type="checkbox"
                                               name="is_active" id="active-<?= $u['user_id'] ?>"
                                               data-user-id="<?= $u['user_id'] ?>"
                                               <?= $u['is_active'] ? 'checked' : '' ?>>
                                        <label class="form-check-label small fw-semibold"
                                               for="active-<?= $u['user_id'] ?>">
                                            Active
                                        </label>
                                    </div>
                                </div>

                            </div><!-- /.row -->

                            <!-- Action buttons -->
                            <div class="d-flex gap-2 mt-3 flex-wrap">
                                <button type="submit"
                                        class="pp-btn pp-btn-primary pp-btn-sm"
                                        id="save-btn-<?= $u['user_id'] ?>"
                                        disabled>
                                    Save Changes
                                </button>

                                <?php if (!$u['is_active']): ?>
                                <!-- Approve shortcut -->
                                <button type="submit"
                                        form="approve-form-<?= $u['user_id'] ?>"
                                        class="pp-btn pp-btn-success pp-btn-sm">
                                    Approve Account
                                </button>
                                <button type="submit"
                                        form="reject-form-<?= $u['user_id'] ?>"
                                        class="pp-btn pp-btn-danger pp-btn-sm"
                                        onclick="return confirm('Delete this pending user?')">
                                    Reject &amp; Delete
                                </button>
                                <?php endif; ?>
                            </div>

                        </form><!-- /edit form -->

                        <!-- Approve / reject standalone forms (outside the edit form) -->
                        <?php if (!$u['is_active']): ?>
                        <form id="approve-form-<?= $u['user_id'] ?>" method="POST"
                              action="/admin/users.php" class="d-none">
                            <input type="hidden" name="action"  value="approve">
                            <input type="hidden" name="user_id" value="<?= $u['user_id'] ?>">
                        </form>
                        <form id="reject-form-<?= $u['user_id'] ?>" method="POST"
                              action="/admin/users.php" class="d-none">
                            <input type="hidden" name="action"  value="reject">
                            <input type="hidden" name="user_id" value="<?= $u['user_id'] ?>">
                        </form>
                        <?php endif; ?>

                        <!-- Password reset (separate form, separate action) -->
                        <hr class="pp-accordion-divider">
                        <form method="POST" action="/admin/users.php"
                              onsubmit="return confirm('Send a password reset email to this user?')">
                            <input type="hidden" name="action"       value="reset_password">
                            <input type="hidden" name="user_id"      value="<?= $u['user_id'] ?>">
                            <input type="hidden" name="open_user_id" value="<?= $u['user_id'] ?>">
                            <button type="submit" class="pp-btn pp-btn-outline pp-btn-sm">
                                Send Password Reset Email
                            </button>
                            <span class="pp-status-detail" style="margin-left: 0.75rem;">
                                Sends a reset link to <?= htmlspecialchars($u['email']) ?>
                                (expires in 15 minutes).
                            </span>
                        </form>

                    </div><!-- /.pp-accordion-panel -->
                </td>
            </tr><!-- /accordion row -->

        <?php endforeach; ?>
        </tbody>
    </table>
</div><!-- /.pp-table-wrap -->

<?php endif; ?>
</div><!-- /.pp-panel -->

<script>
// ── Dirty-state tracking ──────────────────────────────────────────────────────
//
// On accordion open, snapshot all .tracked field values as data-original.
// On every input/change, compare current values to snapshots — enable Save
// only when something differs. After a successful POST (page reloads with
// ?open=N), snapshot again immediately so the button starts disabled.

function snapshotForm(userId) {
    const form = document.querySelector(
        `form input[name="user_id"][value="${userId}"]`
    )?.closest('form');
    if (!form) return;
    form.querySelectorAll('.tracked').forEach(el => {
        el.dataset.original = el.type === 'checkbox'
            ? String(el.checked)
            : el.value;
    });
    updateSaveButton(userId);
}

function updateSaveButton(userId) {
    const form = document.querySelector(
        `form input[name="user_id"][value="${userId}"]`
    )?.closest('form');
    const btn = document.getElementById('save-btn-' + userId);
    if (!form || !btn) return;

    const isDirty = Array.from(form.querySelectorAll('.tracked')).some(el => {
        // New dept text field has no meaningful original — any non-empty value is dirty
        if (el.name === 'new_department') return el.value.trim() !== '';
        const current  = el.type === 'checkbox' ? String(el.checked) : el.value;
        return current !== String(el.dataset.original ?? '');
    });

    btn.disabled = !isDirty;
}

document.addEventListener('input',  e => {
    if (!e.target.matches('.tracked')) return;
    const userId = e.target.closest('form')
        ?.querySelector('input[name="user_id"]')?.value;
    if (userId) updateSaveButton(userId);
});
document.addEventListener('change', e => {
    if (!e.target.matches('.tracked')) return;
    const userId = e.target.closest('form')
        ?.querySelector('input[name="user_id"]')?.value;
    if (userId) updateSaveButton(userId);
});

// ── Accordion toggle ──────────────────────────────────────────────────────────
document.querySelectorAll('.user-row').forEach(row => {
    row.addEventListener('click', function (e) {
        if (e.target.closest('button, a, input, select, textarea, label')) return;

        const userId    = this.dataset.userId;
        const accordion = document.getElementById('accordion-' + userId);
        const isOpen    = accordion.style.display !== 'none';
        const chevron   = this.querySelector('.accordion-chevron');

        // Close all rows
        document.querySelectorAll('.accordion-row').forEach(r => r.style.display = 'none');
        document.querySelectorAll('.user-row').forEach(r => {
            r.classList.remove('table-active');
            const c = r.querySelector('.accordion-chevron');
            if (c) c.textContent = '▼';
        });

        if (!isOpen) {
            accordion.style.display = '';
            this.classList.add('table-active');
            if (chevron) chevron.textContent = '▲';

            const instSelect = document.getElementById('inst-select-' + userId);
            if (instSelect && instSelect.value) {
                loadDepartments(
                    userId,
                    instSelect.value,
                    <?= json_encode(array_column($users, 'department_id', 'user_id')) ?>[userId],
                    () => snapshotForm(userId)
                );
            } else {
                snapshotForm(userId);
            }
        }
    });
});

// ── Institution change → reload departments ───────────────────────────────────
document.querySelectorAll('[id^="inst-select-"]').forEach(sel => {
    sel.addEventListener('change', function () {
        loadDepartments(this.dataset.userId, this.value, null);
    });
});

// ── Department loader ─────────────────────────────────────────────────────────
function loadDepartments(userId, institutionId, selectedDeptId, callback) {
    const deptSelect = document.getElementById('dept-select-' + userId);
    const newWrap    = document.getElementById('new-dept-wrap-' + userId);
    const newInput   = document.getElementById('new-dept-input-' + userId);
    if (!deptSelect) return;

    if (newWrap)  newWrap.style.display = 'none';
    if (newInput) newInput.value = '';

    if (!institutionId) {
        deptSelect.innerHTML = '<option value="">— None —</option>';
        if (callback) callback();
        return;
    }

    fetch('/api/departments.php?institution_id=' + encodeURIComponent(institutionId))
        .then(r => r.json())
        .then(departments => {
            deptSelect.innerHTML = '<option value="">— None —</option>';
            departments.forEach(d => {
                const opt = document.createElement('option');
                opt.value       = d.department_id;
                opt.textContent = d.department;
                if (String(d.department_id) === String(selectedDeptId)) opt.selected = true;
                deptSelect.appendChild(opt);
            });
            const addOpt = document.createElement('option');
            addOpt.value       = '__new__';
            addOpt.textContent = '+ Add new department';
            deptSelect.appendChild(addOpt);
            if (callback) callback();
        })
        .catch(() => {
            deptSelect.innerHTML = '<option value="">— None —</option>';
            if (callback) callback();
        });
}

// ── Show/hide new department input on sentinel selection ──────────────────────
document.addEventListener('change', function (e) {
    if (!e.target.matches('[id^="dept-select-"]')) return;
    const userId  = e.target.dataset.userId;
    const newWrap = document.getElementById('new-dept-wrap-' + userId);
    const newInput= document.getElementById('new-dept-input-' + userId);
    if (!newWrap) return;
    const isNew = e.target.value === '__new__';
    newWrap.style.display = isNew ? '' : 'none';
    if (!isNew && newInput) newInput.value = '';
    if (isNew  && newInput) newInput.focus();
});

// ── Pre-load & snapshot for row preserved open after POST redirect ────────────
<?php if ($open_user_id):
    $open_user = null;
    foreach ($users as $u) {
        if ((int)$u['user_id'] === $open_user_id) { $open_user = $u; break; }
    }
?>
window.addEventListener('DOMContentLoaded', () => {
    <?php if ($open_user && $open_user['institution_id']): ?>
    loadDepartments(
        <?= (int)$open_user_id ?>,
        <?= (int)$open_user['institution_id'] ?>,
        <?= (int)($open_user['department_id'] ?? 0) ?>,
        () => snapshotForm(<?= (int)$open_user_id ?>)
    );
    <?php else: ?>
    snapshotForm(<?= (int)$open_user_id ?>);
    <?php endif; ?>
});
<?php endif; ?>
</script>

<?php close_layout(); ?>