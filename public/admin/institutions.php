<?php
/**
 * institutions.php — Admin page for managing institutions and departments.
 *
 * Provides four sections for each type (institution and department):
 *   1. Pending — review and approve/reject user-submitted entries
 *   2. Add New — add a new institution or department directly as approved
 *   3. Edit    — update an existing approved institution or department
 *   4. Approved — table with edit and hard-delete actions
 *
 * Hard deleting an institution or department sets affected users'
 * institution_id or department_id to NULL via ON DELETE SET NULL.
 */

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/layout.php';

session_start_secure();
require_admin();

$db = get_db();

// ── Handle POST actions ───────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action  = $_POST['action']  ?? '';
    $type    = $_POST['type']    ?? '';
    $item_id = (int)($_POST['item_id'] ?? 0);

    // ── Institution actions ───────────────────────────────────────────────
    if ($type === 'institution') {

        if ($action === 'approve' && $item_id > 0) {
            $db->prepare(
                'UPDATE institutions
                 SET institution      = ?,
                     institution_abbr = ?,
                     country_code     = ?,
                     is_approved      = 1,
                     approved_by      = ?,
                     approved_at      = NOW()
                 WHERE institution_id = ?'
            )->execute([
                trim($_POST['institution']      ?? ''),
                trim($_POST['institution_abbr'] ?? '') ?: null,
                trim($_POST['country_code']     ?? '') ?: null,
                $_SESSION['user_id'],
                $item_id,
            ]);

        } elseif ($action === 'reject' && $item_id > 0) {
            $db->prepare(
                'DELETE FROM institutions WHERE institution_id = ? AND is_approved = 0'
            )->execute([$item_id]);

        } elseif ($action === 'add') {
            $institution = trim($_POST['institution'] ?? '');
            if (!empty($institution)) {
                $db->prepare(
                    'INSERT INTO institutions
                        (institution, institution_abbr, country_code,
                         is_approved, approved_by, approved_at, created_by)
                     VALUES (?, ?, ?, 1, ?, NOW(), ?)'
                )->execute([
                    $institution,
                    trim($_POST['institution_abbr'] ?? '') ?: null,
                    trim($_POST['country_code']     ?? '') ?: null,
                    $_SESSION['user_id'],
                    $_SESSION['user_id'],
                ]);
            }

        } elseif ($action === 'edit' && $item_id > 0) {
            $db->prepare(
                'UPDATE institutions
                 SET institution      = ?,
                     institution_abbr = ?,
                     country_code     = ?
                 WHERE institution_id = ?'
            )->execute([
                trim($_POST['institution']      ?? ''),
                trim($_POST['institution_abbr'] ?? '') ?: null,
                trim($_POST['country_code']     ?? '') ?: null,
                $item_id,
            ]);

        } elseif ($action === 'delete' && $item_id > 0) {
            $db->prepare(
                'DELETE FROM institutions WHERE institution_id = ?'
            )->execute([$item_id]);
        }
    }

    // ── Department actions ────────────────────────────────────────────────
    if ($type === 'department') {

        if ($action === 'approve' && $item_id > 0) {
            $db->prepare(
                'UPDATE departments
                 SET department      = ?,
                     department_abbr = ?,
                     is_approved     = 1,
                     approved_by     = ?,
                     approved_at     = NOW()
                 WHERE department_id = ?'
            )->execute([
                trim($_POST['department']      ?? ''),
                trim($_POST['department_abbr'] ?? '') ?: null,
                $_SESSION['user_id'],
                $item_id,
            ]);

        } elseif ($action === 'reject' && $item_id > 0) {
            $db->prepare(
                'DELETE FROM departments WHERE department_id = ? AND is_approved = 0'
            )->execute([$item_id]);

        } elseif ($action === 'add') {
            $department     = trim($_POST['department']      ?? '');
            $institution_id = (int)($_POST['institution_id'] ?? 0);
            if (!empty($department) && $institution_id > 0) {
                $db->prepare(
                    'INSERT INTO departments
                        (institution_id, department, department_abbr,
                         is_approved, approved_by, approved_at, created_by)
                     VALUES (?, ?, ?, 1, ?, NOW(), ?)'
                )->execute([
                    $institution_id,
                    $department,
                    trim($_POST['department_abbr'] ?? '') ?: null,
                    $_SESSION['user_id'],
                    $_SESSION['user_id'],
                ]);
            }

        } elseif ($action === 'edit' && $item_id > 0) {
            $db->prepare(
                'UPDATE departments
                 SET institution_id  = ?,
                     department      = ?,
                     department_abbr = ?
                 WHERE department_id = ?'
            )->execute([
                (int)($_POST['institution_id']  ?? 0),
                trim($_POST['department']       ?? ''),
                trim($_POST['department_abbr']  ?? '') ?: null,
                $item_id,
            ]);

        } elseif ($action === 'delete' && $item_id > 0) {
            $db->prepare(
                'DELETE FROM departments WHERE department_id = ?'
            )->execute([$item_id]);
        }
    }

    header('Location: /admin/institutions.php');
    exit;
}

// ── Fetch data ────────────────────────────────────────────────────────────

$pending_institutions = $db->query(
    'SELECT i.institution_id, i.institution, i.institution_abbr,
            i.country_code, i.created_at,
            u.username AS submitted_by
     FROM institutions i
     LEFT JOIN users u ON i.created_by = u.user_id
     WHERE i.is_approved = 0
     ORDER BY i.created_at ASC'
)->fetchAll();

$pending_departments = $db->query(
    'SELECT d.department_id, d.department, d.department_abbr,
            d.institution_id, d.created_at,
            i.institution, i.institution_abbr AS inst_abbr,
            u.username AS submitted_by
     FROM departments d
     JOIN institutions i ON d.institution_id = i.institution_id
     LEFT JOIN users u ON d.created_by = u.user_id
     WHERE d.is_approved = 0
     ORDER BY d.created_at ASC'
)->fetchAll();

$approved_institutions = $db->query(
    'SELECT i.institution_id, i.institution, i.institution_abbr,
            i.country_code, i.approved_at,
            a.username AS approved_by,
            COUNT(u.user_id) AS user_count
     FROM institutions i
     LEFT JOIN users a ON i.approved_by = a.user_id
     LEFT JOIN users u ON u.institution_id = i.institution_id
     WHERE i.is_approved = 1
     GROUP BY i.institution_id
     ORDER BY i.institution'
)->fetchAll();

$approved_departments = $db->query(
    'SELECT d.department_id, d.department, d.department_abbr,
            d.institution_id, d.approved_at,
            i.institution, i.institution_abbr AS inst_abbr,
            a.username AS approved_by,
            COUNT(u.user_id) AS user_count
     FROM departments d
     JOIN institutions i ON d.institution_id = i.institution_id
     LEFT JOIN users a ON d.approved_by = a.user_id
     LEFT JOIN users u ON u.department_id = d.department_id
     WHERE d.is_approved = 1
     GROUP BY d.department_id
     ORDER BY i.institution, d.department'
)->fetchAll();

// Approved institutions for department dropdowns
$inst_options = $db->query(
    'SELECT institution_id, institution, institution_abbr
     FROM institutions WHERE is_approved = 1 ORDER BY institution'
)->fetchAll();

$total_pending = count($pending_institutions) + count($pending_departments);

open_layout('Manage Institutions & Departments');
?>

<div class="pp-page-title-row">
    <div>
        <p class="pp-section-label" style="margin-bottom: 0.25rem;">Admin</p>
        <h1 class="pp-page-title-large">
            Manage institutions &amp; departments
            <?php if ($total_pending > 0): ?>
                <span class="pp-badge pp-badge-warning" style="margin-left: 0.5rem; vertical-align: middle; font-size: 0.7rem;"><?= $total_pending ?></span>
            <?php endif; ?>
        </h1>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════════════════════ -->
<!-- INSTITUTIONS                                                            -->
<!-- ════════════════════════════════════════════════════════════════════════ -->
<h2 class="pp-docs-section-heading" style="margin-top: 0; margin-bottom: 2rem;">Institutions</h2>

<!-- ── Pending Institutions ───────────────────────────────────────────────── -->
<p class="pp-section-label" style="margin-bottom: 1rem;">
    Pending review
    <?php if (!empty($pending_institutions)): ?>
        <span class="pp-badge pp-badge-warning" style="margin-left: 0.5rem;"><?= count($pending_institutions) ?></span>
    <?php endif; ?>
</p>

<?php if (empty($pending_institutions)): ?>
    <div class="pp-alert pp-alert-info" style="margin-bottom: 2rem;">
        No institutions pending review.
    </div>
<?php else: ?>
<div style="margin-bottom: 2rem;">
<?php foreach ($pending_institutions as $inst): ?>
<div class="pp-panel pp-panel--flush pp-panel--warning" style="margin-bottom: 1rem;">
    <div class="pp-panel-header">
        <span class="pp-status-detail">
            Submitted <?= htmlspecialchars($inst['created_at']) ?>
            <?= $inst['submitted_by']
                ? ' by <strong>' . htmlspecialchars($inst['submitted_by']) . '</strong>'
                : '' ?>
        </span>
        <span class="pp-badge pp-badge-warning">Pending</span>
    </div>
    <div class="pp-panel-body">
        <form method="POST" action="/admin/institutions.php">
            <input type="hidden" name="type"    value="institution">
            <input type="hidden" name="item_id" value="<?= $inst['institution_id'] ?>">
            <div class="pp-filter-row" style="margin-bottom: 1rem;">
                <div class="pp-filter-cell" style="grid-column: span 2;">
                    <label class="pp-filter-label">Institution name</label>
                    <input type="text" class="pp-input" name="institution"
                           value="<?= htmlspecialchars($inst['institution'] ?? '') ?>" required>
                </div>
                <div class="pp-filter-cell">
                    <label class="pp-filter-label">Abbreviation</label>
                    <input type="text" class="pp-input" name="institution_abbr"
                           value="<?= htmlspecialchars($inst['institution_abbr'] ?? '') ?>">
                </div>
                <div class="pp-filter-cell">
                    <label class="pp-filter-label">Country code</label>
                    <input type="text" class="pp-input" name="country_code"
                           maxlength="2" placeholder="e.g. US"
                           value="<?= htmlspecialchars($inst['country_code'] ?? '') ?>">
                </div>
            </div>
            <div style="display: flex; gap: 0.5rem;">
                <button type="submit" name="action" value="approve"
                        class="pp-btn pp-btn-sm pp-btn-success">Approve</button>
                <button type="submit" name="action" value="reject"
                        class="pp-btn pp-btn-sm pp-btn-danger"
                        onclick="return confirm('Permanently delete this pending institution?')">
                    Reject
                </button>
            </div>
        </form>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── Add Institution ────────────────────────────────────────────────────── -->
<p class="pp-section-label" style="margin-bottom: 1rem;">Add institution</p>
<div class="pp-panel pp-panel--flush" style="margin-bottom: 2.5rem;">
    <div class="pp-panel-body">
        <form method="POST" action="/admin/institutions.php">
            <input type="hidden" name="type"   value="institution">
            <input type="hidden" name="action" value="add">
            <div class="pp-filter-row" style="margin-bottom: 1rem;">
                <div class="pp-filter-cell" style="grid-column: span 2;">
                    <label class="pp-filter-label">Institution name <span class="pp-required">*</span></label>
                    <input type="text" class="pp-input" name="institution"
                           placeholder="e.g. University of Arizona" required>
                </div>
                <div class="pp-filter-cell">
                    <label class="pp-filter-label">Abbreviation</label>
                    <input type="text" class="pp-input" name="institution_abbr"
                           placeholder="e.g. UA">
                </div>
                <div class="pp-filter-cell">
                    <label class="pp-filter-label">Country code</label>
                    <input type="text" class="pp-input" name="country_code"
                           maxlength="2" placeholder="e.g. US">
                </div>
            </div>
            <button type="submit" class="pp-btn pp-btn-sm pp-btn-primary">Add institution</button>
        </form>
    </div>
</div>

<!-- ── Approved Institutions ──────────────────────────────────────────────── -->
<p class="pp-section-label" style="margin-bottom: 1rem;">Approved institutions</p>

<?php if (empty($approved_institutions)): ?>
    <div class="pp-alert pp-alert-info" style="margin-bottom: 2.5rem;">
        No approved institutions yet.
    </div>
<?php else: ?>
<div class="pp-table-wrap" style="margin-bottom: 2.5rem;">
    <table class="pp-table">
        <thead>
            <tr>
                <th>Institution</th>
                <th>Abbr.</th>
                <th>Country</th>
                <th>Users</th>
                <th>Approved by</th>
                <th>Approved at</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($approved_institutions as $inst):
            $edit_form_id = 'edit-inst-' . (int)$inst['institution_id'];
        ?>
            <tr>
                <td>
                    <input form="<?= $edit_form_id ?>" type="text"
                           class="pp-input pp-input--sm"
                           name="institution"
                           value="<?= htmlspecialchars($inst['institution']) ?>"
                           required>
                </td>
                <td>
                    <input form="<?= $edit_form_id ?>" type="text"
                           class="pp-input pp-input--sm"
                           name="institution_abbr"
                           value="<?= htmlspecialchars($inst['institution_abbr'] ?? '') ?>">
                </td>
                <td>
                    <input form="<?= $edit_form_id ?>" type="text"
                           class="pp-input pp-input--sm"
                           name="country_code" maxlength="2"
                           value="<?= htmlspecialchars($inst['country_code'] ?? '') ?>">
                </td>
                <td><?= (int)$inst['user_count'] ?></td>
                <td><?= htmlspecialchars($inst['approved_by'] ?? '—') ?></td>
                <td><?= htmlspecialchars($inst['approved_at'] ?? '—') ?></td>
                <td>
                    <div style="display: flex; gap: 0.35rem;">
                        <form id="<?= $edit_form_id ?>" method="POST"
                              action="/admin/institutions.php"
                              style="display: inline;">
                            <input type="hidden" name="type"    value="institution">
                            <input type="hidden" name="action"  value="edit">
                            <input type="hidden" name="item_id" value="<?= $inst['institution_id'] ?>">
                            <button type="submit" class="pp-btn pp-btn-sm pp-btn-primary">Save</button>
                        </form>
                        <form method="POST" action="/admin/institutions.php"
                              style="display: inline;">
                            <input type="hidden" name="type"    value="institution">
                            <input type="hidden" name="action"  value="delete">
                            <input type="hidden" name="item_id" value="<?= $inst['institution_id'] ?>">
                            <button type="submit" class="pp-btn pp-btn-sm pp-btn-danger"
                                    onclick="return confirm('Permanently delete this institution? <?= (int)$inst['user_count'] ?> user(s) will have their institution cleared.')">
                                Delete
                            </button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>


<!-- ════════════════════════════════════════════════════════════════════════ -->
<!-- DEPARTMENTS                                                             -->
<!-- ════════════════════════════════════════════════════════════════════════ -->
<h2 class="pp-docs-section-heading" style="margin-bottom: 2rem;">Departments</h2>

<!-- ── Pending Departments ────────────────────────────────────────────────── -->
<p class="pp-section-label" style="margin-bottom: 1rem;">
    Pending review
    <?php if (!empty($pending_departments)): ?>
        <span class="pp-badge pp-badge-warning" style="margin-left: 0.5rem;"><?= count($pending_departments) ?></span>
    <?php endif; ?>
</p>

<?php if (empty($pending_departments)): ?>
    <div class="pp-alert pp-alert-info" style="margin-bottom: 2rem;">
        No departments pending review.
    </div>
<?php else: ?>
<div style="margin-bottom: 2rem;">
<?php foreach ($pending_departments as $dept): ?>
<div class="pp-panel pp-panel--flush pp-panel--warning" style="margin-bottom: 1rem;">
    <div class="pp-panel-header">
        <span class="pp-status-detail">
            <span style="color: var(--text-muted);">Institution:</span>
            <strong>
                <?= htmlspecialchars($dept['institution']) ?>
                <?= $dept['inst_abbr'] ? '(' . htmlspecialchars($dept['inst_abbr']) . ')' : '' ?>
            </strong>
            &mdash;
            Submitted <?= htmlspecialchars($dept['created_at']) ?>
            <?= $dept['submitted_by']
                ? ' by <strong>' . htmlspecialchars($dept['submitted_by']) . '</strong>'
                : '' ?>
        </span>
        <span class="pp-badge pp-badge-warning">Pending</span>
    </div>
    <div class="pp-panel-body">
        <form method="POST" action="/admin/institutions.php">
            <input type="hidden" name="type"    value="department">
            <input type="hidden" name="item_id" value="<?= $dept['department_id'] ?>">
            <div class="pp-filter-row" style="margin-bottom: 1rem;">
                <div class="pp-filter-cell" style="grid-column: span 2;">
                    <label class="pp-filter-label">Department name</label>
                    <input type="text" class="pp-input" name="department"
                           value="<?= htmlspecialchars($dept['department'] ?? '') ?>" required>
                </div>
                <div class="pp-filter-cell">
                    <label class="pp-filter-label">Abbreviation</label>
                    <input type="text" class="pp-input" name="department_abbr"
                           value="<?= htmlspecialchars($dept['department_abbr'] ?? '') ?>">
                </div>
            </div>
            <div style="display: flex; gap: 0.5rem;">
                <button type="submit" name="action" value="approve"
                        class="pp-btn pp-btn-sm pp-btn-success">Approve</button>
                <button type="submit" name="action" value="reject"
                        class="pp-btn pp-btn-sm pp-btn-danger"
                        onclick="return confirm('Permanently delete this pending department?')">
                    Reject
                </button>
            </div>
        </form>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── Add Department ─────────────────────────────────────────────────────── -->
<p class="pp-section-label" style="margin-bottom: 1rem;">Add department</p>
<div class="pp-panel pp-panel--flush" style="margin-bottom: 2.5rem;">
    <div class="pp-panel-body">
        <form method="POST" action="/admin/institutions.php">
            <input type="hidden" name="type"   value="department">
            <input type="hidden" name="action" value="add">
            <div class="pp-filter-row" style="margin-bottom: 1rem;">
                <div class="pp-filter-cell">
                    <label class="pp-filter-label">Institution <span class="pp-required">*</span></label>
                    <select class="pp-select" name="institution_id" required>
                        <option value="">— Select institution —</option>
                        <?php foreach ($inst_options as $inst): ?>
                            <option value="<?= $inst['institution_id'] ?>">
                                <?= htmlspecialchars($inst['institution']) ?>
                                <?= $inst['institution_abbr']
                                    ? '(' . htmlspecialchars($inst['institution_abbr']) . ')'
                                    : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="pp-filter-cell" style="grid-column: span 2;">
                    <label class="pp-filter-label">Department name <span class="pp-required">*</span></label>
                    <input type="text" class="pp-input" name="department"
                           placeholder="e.g. Department of Planetary Sciences" required>
                </div>
                <div class="pp-filter-cell">
                    <label class="pp-filter-label">Abbreviation</label>
                    <input type="text" class="pp-input" name="department_abbr"
                           placeholder="e.g. DPS">
                </div>
            </div>
            <button type="submit" class="pp-btn pp-btn-sm pp-btn-primary">Add department</button>
        </form>
    </div>
</div>

<!-- ── Approved Departments ───────────────────────────────────────────────── -->
<p class="pp-section-label" style="margin-bottom: 1rem;">Approved departments</p>

<?php if (empty($approved_departments)): ?>
    <div class="pp-alert pp-alert-info">No approved departments yet.</div>
<?php else: ?>
<div class="pp-table-wrap">
    <table class="pp-table">
        <thead>
            <tr>
                <th>Institution</th>
                <th>Department</th>
                <th>Abbr.</th>
                <th>Users</th>
                <th>Approved by</th>
                <th>Approved at</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($approved_departments as $dept):
            $edit_form_id = 'edit-dept-' . (int)$dept['department_id'];
        ?>
            <tr>
                <td>
                    <input form="<?= $edit_form_id ?>" type="hidden"
                           name="institution_id" value="<?= $dept['institution_id'] ?>">
                    <?= htmlspecialchars($dept['institution_abbr'] ?? $dept['institution']) ?>
                </td>
                <td>
                    <input form="<?= $edit_form_id ?>" type="text"
                           class="pp-input pp-input--sm"
                           name="department"
                           value="<?= htmlspecialchars($dept['department']) ?>"
                           required>
                </td>
                <td>
                    <input form="<?= $edit_form_id ?>" type="text"
                           class="pp-input pp-input--sm"
                           name="department_abbr"
                           value="<?= htmlspecialchars($dept['department_abbr'] ?? '') ?>">
                </td>
                <td><?= (int)$dept['user_count'] ?></td>
                <td><?= htmlspecialchars($dept['approved_by'] ?? '—') ?></td>
                <td><?= htmlspecialchars($dept['approved_at'] ?? '—') ?></td>
                <td>
                    <div style="display: flex; gap: 0.35rem;">
                        <form id="<?= $edit_form_id ?>" method="POST"
                              action="/admin/institutions.php"
                              style="display: inline;">
                            <input type="hidden" name="type"    value="department">
                            <input type="hidden" name="action"  value="edit">
                            <input type="hidden" name="item_id" value="<?= $dept['department_id'] ?>">
                            <button type="submit" class="pp-btn pp-btn-sm pp-btn-primary">Save</button>
                        </form>
                        <form method="POST" action="/admin/institutions.php"
                              style="display: inline;">
                            <input type="hidden" name="type"    value="department">
                            <input type="hidden" name="action"  value="delete">
                            <input type="hidden" name="item_id" value="<?= $dept['department_id'] ?>">
                            <button type="submit" class="pp-btn pp-btn-sm pp-btn-danger"
                                    onclick="return confirm('Permanently delete this department? <?= (int)$dept['user_count'] ?> user(s) will have their department cleared.')">
                                Delete
                            </button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php close_layout(); ?>