<?php
/**
 * register.php — New user registration page.
 *
 * Handles display and processing of the registration form. On successful
 * submission, creates a new user with is_active = 0 pending admin approval.
 * New institutions and departments submitted via the "Other" option are
 * inserted with is_approved = 0 and require separate admin review.
 */

require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/mailer.php';
require_once __DIR__ . '/../vendor/autoload.php';

session_start_secure();

if (is_logged_in()) {
    header('Location: /dashboard.php');
    exit;
}

$errors  = [];
$success = false;
$old     = [];

// Load approved institutions for dropdown
$db           = get_db();
$institutions = $db->query(
    'SELECT DISTINCT institution_id, institution, institution_abbr
     FROM institutions
     WHERE institution IS NOT NULL
       AND is_approved = 1
     ORDER BY institution'
)->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old = $_POST;

    $first_name    = trim($_POST['first_name']        ?? '');
    $last_name     = trim($_POST['last_name']         ?? '');
    $username      = trim($_POST['username']          ?? '');
    $email         = trim($_POST['email']             ?? '');
    $password      = $_POST['password']               ?? '';
    $password2     = $_POST['password2']              ?? '';
    $inst_id       = (int)($_POST['institution_id']   ?? 0);
    $inst_other    = trim($_POST['institution_other'] ?? '');
    $dept_id       = (int)($_POST['department_id']    ?? 0);
    $dept_other    = trim($_POST['department_other']  ?? '');
    $access_reason = trim($_POST['access_reason']     ?? '');

    // ── Validation ────────────────────────────────────────────────────────
    if (empty($first_name))
        $errors['first_name']  = 'First name is required.';
    if (empty($last_name))
        $errors['last_name']   = 'Last name is required.';
    if (empty($username))
        $errors['username']    = 'Username is required.';
    if (empty($email))
        $errors['email']       = 'Email address is required.';
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL))
        $errors['email']       = 'Please enter a valid email address.';
    if (strlen($password) < 8)
        $errors['password']    = 'Password must be at least 8 characters.';
    if ($password !== $password2)
        $errors['password2']   = 'Passwords do not match.';
    if ($inst_id === 0 && empty($inst_other))
        $errors['institution'] = 'Please select or enter an institution.';
    if (empty($access_reason))
        $errors['access_reason'] = 'Please briefly describe why you are requesting access.';

    // Check username and email uniqueness
    if (empty($errors['username'])) {
        $stmt = $db->prepare('SELECT user_id FROM users WHERE username = ?');
        $stmt->execute([$username]);
        if ($stmt->fetch())
            $errors['username'] = 'This username is already taken.';
    }
    if (empty($errors['email'])) {
        $stmt = $db->prepare('SELECT user_id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch())
            $errors['email'] = 'An account with this email address already exists.';
    }

    // ── Insert ────────────────────────────────────────────────────────────
    if (empty($errors)) {

        // Handle "Other" institution — insert pending row
        if ($inst_id === -1 && !empty($inst_other)) {
            $stmt = $db->prepare(
                'INSERT INTO institutions (institution, is_approved, created_by)
                 VALUES (?, 0, NULL)'
            );
            $stmt->execute([$inst_other]);
            $inst_id = (int)$db->lastInsertId();
        }

        // Handle "Other" department — insert pending row into departments table
        $final_dept_id = null;
        if ($dept_id === -1 && !empty($dept_other)) {
            $stmt = $db->prepare(
                'INSERT INTO departments
                    (institution_id, department, is_approved, created_by)
                 VALUES (?, ?, 0, NULL)'
            );
            $stmt->execute([$inst_id, $dept_other]);
            $final_dept_id = (int)$db->lastInsertId();
        } elseif ($dept_id > 0) {
            $final_dept_id = $dept_id;
        }

        // Generate email verification token
        $raw_token    = bin2hex(random_bytes(32));
        $hashed_token = hash('sha256', $raw_token);
        $expires      = date('Y-m-d H:i:s', time() + 86400); // 24 hours

        $stmt = $db->prepare(
            'INSERT INTO users
                (first_name, last_name, username, email, password_hash,
                 institution_id, department_id, access_reason,
                 role, is_active, email_verified,
                 email_verify_token, email_verify_expires)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'user\', 0, 0, ?, ?)'
        );
        $stmt->execute([
            $first_name,
            $last_name,
            $username,
            $email,
            password_hash($password, PASSWORD_BCRYPT),
            $inst_id ?: null,
            $final_dept_id,
            $access_reason,
            $hashed_token,
            $expires,
        ]);

        // Send verification email
        $name = trim($first_name . ' ' . $last_name);
        send_email_verification($email, $name, $raw_token);

        $success = true;
        $old     = [];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PORPASS — Create Account</title>
    <link href="/resources/css/bootstrap.min.css" rel="stylesheet">
    <link href="/resources/css/porpass.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
</head>
<body>

<!-- ── Navbar ─────────────────────────────────────────────────────────────── -->
<nav class="pp-navbar">
    <div class="container">
        <a class="pp-nav-brand" href="/index.php">
            <svg width="44" height="24" viewBox="0 0 44 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <polyline points="0,12 6,12 9,4 13,20 17,8 21,14 24,12 30,12" stroke="#1D9E75" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                <polyline points="4,15 8,15 10,19 14,11 18,16 22,13 25,15 30,15" stroke="#EF9F27" stroke-width="1.1" stroke-linecap="round" stroke-linejoin="round" opacity="0.8"/>
            </svg>
            <span class="pp-nav-wordmark">PORPASS</span>
        </a>
        <a class="pp-nav-signin" href="/login.php">Sign In</a>
    </div>
</nav>

<main class="container">
    <section class="pp-section">
        <div class="pp-container-narrow">

            <p class="pp-section-label">Register</p>
            <h1 class="pp-section-title">Create an account</h1>
            <p class="pp-lead" style="margin-bottom: 2rem;">
                Account requests are reviewed and approved by a PORPASS administrator.
            </p>

            <?php if ($success): ?>

                <!-- ── Success state ──────────────────────────────────────── -->
                <div class="pp-panel">
                    <h2 class="pp-panel-title">Request submitted</h2>
                    <p style="font-size: 0.95rem; line-height: 1.7; color: var(--text-muted);">
                        Thank you for registering. We have sent a verification email
                        to your address — please click the link in that email to verify
                        your account.
                    </p>
                    <p style="font-size: 0.95rem; line-height: 1.7; color: var(--text-muted);">
                        Once your email is verified, your account will be reviewed and
                        approved by a PORPASS administrator before you can sign in.
                    </p>
                    <?php if (isset($inst_id) && $inst_id > 0): ?>
                    <p style="font-size: 0.85rem; line-height: 1.7; color: var(--text-muted);">
                        If you submitted a new institution or department, these will also
                        be reviewed by an administrator.
                    </p>
                    <?php endif; ?>
                    <a href="/login.php" class="pp-btn pp-btn-primary" style="margin-top: 1rem;">Go to Sign In</a>
                </div>

            <?php else: ?>

                <?php if (!empty($errors)): ?>
                    <div class="pp-alert pp-alert-danger">
                        Please correct the errors below before submitting.
                    </div>
                <?php endif; ?>

                <!-- ── Registration form ──────────────────────────────────── -->
                <div class="pp-panel">
                    <form method="POST" action="/register.php" novalidate>

                        <!-- Name -->
                        <div class="pp-field-row" style="margin-bottom: 1.1rem;">
                            <div class="pp-field">
                                <label for="first_name" class="pp-label">
                                    First name <span class="pp-required">*</span>
                                </label>
                                <input type="text"
                                       class="pp-input <?= isset($errors['first_name']) ? 'is-invalid' : '' ?>"
                                       id="first_name" name="first_name"
                                       value="<?= htmlspecialchars($old['first_name'] ?? '') ?>"
                                       required>
                                <?php if (isset($errors['first_name'])): ?>
                                    <div class="pp-field-error">
                                        <?= htmlspecialchars($errors['first_name']) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="pp-field">
                                <label for="last_name" class="pp-label">
                                    Last name <span class="pp-required">*</span>
                                </label>
                                <input type="text"
                                       class="pp-input <?= isset($errors['last_name']) ? 'is-invalid' : '' ?>"
                                       id="last_name" name="last_name"
                                       value="<?= htmlspecialchars($old['last_name'] ?? '') ?>"
                                       required>
                                <?php if (isset($errors['last_name'])): ?>
                                    <div class="pp-field-error">
                                        <?= htmlspecialchars($errors['last_name']) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Username -->
                        <div class="pp-field">
                            <label for="username" class="pp-label">
                                Username <span class="pp-required">*</span>
                            </label>
                            <input type="text"
                                   class="pp-input <?= isset($errors['username']) ? 'is-invalid' : '' ?>"
                                   id="username" name="username"
                                   value="<?= htmlspecialchars($old['username'] ?? '') ?>"
                                   required>
                            <?php if (isset($errors['username'])): ?>
                                <div class="pp-field-error">
                                    <?= htmlspecialchars($errors['username']) ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Email -->
                        <div class="pp-field">
                            <label for="email" class="pp-label">
                                Email address <span class="pp-required">*</span>
                            </label>
                            <input type="email"
                                   class="pp-input <?= isset($errors['email']) ? 'is-invalid' : '' ?>"
                                   id="email" name="email"
                                   value="<?= htmlspecialchars($old['email'] ?? '') ?>"
                                   required>
                            <?php if (isset($errors['email'])): ?>
                                <div class="pp-field-error">
                                    <?= htmlspecialchars($errors['email']) ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Password -->
                        <div class="pp-field-row" style="margin-bottom: 1.1rem;">
                            <div class="pp-field">
                                <label for="password" class="pp-label">
                                    Password <span class="pp-required">*</span>
                                </label>
                                <input type="password"
                                       class="pp-input <?= isset($errors['password']) ? 'is-invalid' : '' ?>"
                                       id="password" name="password"
                                       required>
                                <div class="pp-field-hint">Minimum 8 characters.</div>
                                <?php if (isset($errors['password'])): ?>
                                    <div class="pp-field-error">
                                        <?= htmlspecialchars($errors['password']) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="pp-field">
                                <label for="password2" class="pp-label">
                                    Confirm password <span class="pp-required">*</span>
                                </label>
                                <input type="password"
                                       class="pp-input <?= isset($errors['password2']) ? 'is-invalid' : '' ?>"
                                       id="password2" name="password2"
                                       required>
                                <?php if (isset($errors['password2'])): ?>
                                    <div class="pp-field-error">
                                        <?= htmlspecialchars($errors['password2']) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Institution -->
                        <div class="pp-field">
                            <label for="institution_id" class="pp-label">
                                Institution <span class="pp-required">*</span>
                            </label>
                            <select class="pp-select <?= isset($errors['institution']) ? 'is-invalid' : '' ?>"
                                    id="institution_id" name="institution_id">
                                <option value="0">— Select institution —</option>
                                <?php foreach ($institutions as $inst): ?>
                                    <option value="<?= $inst['institution_id'] ?>"
                                        <?= (int)($old['institution_id'] ?? 0) === (int)$inst['institution_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($inst['institution']) ?>
                                        <?= $inst['institution_abbr'] ? '(' . htmlspecialchars($inst['institution_abbr']) . ')' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                                <option value="-1" <?= ($old['institution_id'] ?? '') === '-1' ? 'selected' : '' ?>>
                                    Other — not listed
                                </option>
                            </select>
                            <?php if (isset($errors['institution'])): ?>
                                <div class="pp-field-error">
                                    <?= htmlspecialchars($errors['institution']) ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Institution other -->
                        <div class="pp-field" id="institution_other_wrap" style="display:none;">
                            <input type="text"
                                   class="pp-input"
                                   id="institution_other"
                                   name="institution_other"
                                   placeholder="Enter your institution name"
                                   value="<?= htmlspecialchars($old['institution_other'] ?? '') ?>">
                            <div class="pp-field-hint">
                                New institutions are reviewed and approved by an administrator.
                            </div>
                        </div>

                        <!-- Department -->
                        <div class="pp-field">
                            <label for="department_id" class="pp-label">Department</label>
                            <select class="pp-select" id="department_id" name="department_id">
                                <option value="0">— Select department (optional) —</option>
                                <option value="-1">Other — not listed</option>
                            </select>
                        </div>

                        <!-- Department other -->
                        <div class="pp-field" id="department_other_wrap" style="display:none;">
                            <input type="text"
                                   class="pp-input"
                                   id="department_other"
                                   name="department_other"
                                   placeholder="Enter your department name"
                                   value="<?= htmlspecialchars($old['department_other'] ?? '') ?>">
                            <div class="pp-field-hint">
                                New departments are reviewed and approved by an administrator.
                            </div>
                        </div>

                        <!-- Access reason -->
                        <div class="pp-field">
                            <label for="access_reason" class="pp-label">
                                Reason for requesting access <span class="pp-required">*</span>
                            </label>
                            <textarea class="pp-textarea <?= isset($errors['access_reason']) ? 'is-invalid' : '' ?>"
                                      id="access_reason" name="access_reason"
                                      rows="3"
                                      placeholder="Briefly describe your research interests and intended use of PORPASS."
                                      required><?= htmlspecialchars($old['access_reason'] ?? '') ?></textarea>
                            <?php if (isset($errors['access_reason'])): ?>
                                <div class="pp-field-error">
                                    <?= htmlspecialchars($errors['access_reason']) ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <button type="submit" class="pp-btn-submit">Submit request</button>

                        <p class="pp-form-links" style="justify-content: center; margin-top: 1.5rem;">
                            <span style="color: var(--text-muted); font-size: 0.85rem;">
                                Already have an account?&nbsp;
                                <a href="/login.php" style="color: var(--teal-500); font-weight: 500;">Sign in</a>
                            </span>
                        </p>

                    </form>
                </div>

            <?php endif; ?>

        </div>
    </section>
</main>

<!-- ── Footer ─────────────────────────────────────────────────────────────── -->
<footer class="pp-footer">
    <div class="container">
        <div class="pp-footer-inner">
            <div class="pp-footer-logo">
                <img src="/resources/img/PSI_Logo.png" alt="Planetary Science Institute">
            </div>
            <div class="pp-footer-text">
                <p><strong style="color:#E1F5EE;">The Planetary Science Institute</strong></p>
                <p>1700 East Fort Lowell, Suite 106, Tucson, AZ 85719-2395 &mdash; (520) 622-6300</p>
                <p class="pp-footer-small">
                    Development funded by the NASA Planetary Data Archival, Restoration, and Tools
                    (PDART) Program, grant number 80NSSC20K1057.
                </p>
            </div>
        </div>
        <hr class="pp-footer-divider">
        <p class="pp-footer-bottom text-center">
            PORPASS &mdash; Planetary Orbital Radar Processing and Simulation System
        </p>
    </div>
</footer>

<script src="/resources/js/bootstrap.bundle.min.js"></script>
<script>
// ── Institution / Department dynamic behaviour ────────────────────────────

const instSelect     = document.getElementById('institution_id');
const instOtherWrap  = document.getElementById('institution_other_wrap');
const instOtherInput = document.getElementById('institution_other');
const deptSelect     = document.getElementById('department_id');
const deptOtherWrap  = document.getElementById('department_other_wrap');

instSelect.addEventListener('change', function () {
    const isOther = this.value === '-1';
    instOtherWrap.style.display = isOther ? 'block' : 'none';
    instOtherInput.required     = isOther;
    resetDepartments();
    if (this.value > 0) {
        fetchDepartments(this.value);
    }
});

deptSelect.addEventListener('change', function () {
    deptOtherWrap.style.display = this.value === '-1' ? 'block' : 'none';
});

function resetDepartments() {
    deptSelect.innerHTML =
        '<option value="0">— Select department (optional) —</option>' +
        '<option value="-1">Other — not listed</option>';
    deptOtherWrap.style.display = 'none';
}

function fetchDepartments(institutionId) {
    fetch('/api/departments.php?institution_id=' + institutionId)
        .then(r => r.json())
        .then(data => {
            resetDepartments();
            data.forEach(dept => {
                if (dept.department) {
                    const opt       = document.createElement('option');
                    opt.value       = dept.department_id;
                    opt.textContent = dept.department +
                        (dept.department_abbr ? ' (' + dept.department_abbr + ')' : '');
                    deptSelect.insertBefore(opt, deptSelect.lastElementChild);
                }
            });
        })
        .catch(() => { /* silently ignore fetch errors */ });
}

// Restore "Other" fields if form was resubmitted with errors
(function () {
    if (instSelect.value === '-1') {
        instOtherWrap.style.display = 'block';
        instOtherInput.required     = true;
    } else if (instSelect.value > 0) {
        fetchDepartments(instSelect.value);
    }
})();
</script>

</body>
</html>