<?php
/**
 * reset.php — Password reset flow.
 *
 * Handles two distinct steps in a single file:
 *
 * Step 1 — Forgot password form (no token in URL):
 *   User enters their email address. If found, a hashed reset token is stored
 *   in the database and a reset link is emailed to the user. A generic success
 *   message is always shown regardless of whether the email exists, to prevent
 *   user enumeration.
 *
 * Step 2 — Reset form (valid token in URL):
 *   User arrives via the emailed link. If the token is valid and not expired,
 *   the new password form is shown. On submission the password is updated,
 *   the token is cleared, and the user is redirected to login.
 */

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/mailer.php';
require_once __DIR__ . '/../../vendor/autoload.php';

session_start_secure();

if (is_logged_in()) {
    header('Location: /dashboard.php');
    exit;
}

$db     = get_db();
$step   = 1;        // 1 = forgot form, 2 = reset form
$errors = [];
$success_message = '';

$token = trim($_GET['token'] ?? '');

// ── Determine step ────────────────────────────────────────────────────────

if (!empty($token)) {
    $stmt = $db->prepare(
        'SELECT user_id, password_reset_expires
         FROM users
         WHERE password_reset_token = ?
           AND is_active = 1'
    );
    $stmt->execute([hash('sha256', $token)]);
    $token_row = $stmt->fetch();

    if (!$token_row || strtotime($token_row['password_reset_expires']) < time()) {
        $errors['token'] = 'This password reset link is invalid or has expired.
                            Please request a new one.';
    } else {
        $step = 2;
    }
}

// ── Step 1: Process forgot password form ─────────────────────────────────

if ($step === 1 && $_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['new_password'])) {
    $email = trim($_POST['email'] ?? '');

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please enter a valid email address.';
    } else {
        $stmt = $db->prepare(
            'SELECT user_id, first_name, last_name FROM users WHERE email = ? AND is_active = 1'
        );
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            $raw_token    = bin2hex(random_bytes(32));
            $hashed_token = hash('sha256', $raw_token);
            $expires      = date('Y-m-d H:i:s', time() + 900); // 15 minutes

            $db->prepare(
                'UPDATE users
                 SET password_reset_token = ?, password_reset_expires = ?
                 WHERE user_id = ?'
            )->execute([$hashed_token, $expires, $user['user_id']]);

            $name = trim($user['first_name'] . ' ' . $user['last_name']);
            send_password_reset($email, $name, $raw_token);
        }

        // Always show success to prevent user enumeration
        $success_message = 'If an account exists for that email address, a password
                            reset link has been sent. Please check your inbox.
                            The link will expire in 15 minutes.';
    }
}

// ── Step 2: Process reset form ────────────────────────────────────────────

if ($step === 2 && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_password'])) {
    $new_password  = $_POST['new_password']  ?? '';
    $new_password2 = $_POST['new_password2'] ?? '';

    if (strlen($new_password) < 8) {
        $errors['new_password'] = 'Password must be at least 8 characters.';
    } elseif ($new_password !== $new_password2) {
        $errors['new_password2'] = 'Passwords do not match.';
    } else {
        $db->prepare(
            'UPDATE users
             SET password_hash            = ?,
                 password_reset_token     = NULL,
                 password_reset_expires   = NULL,
                 password_expires         = DATE_ADD(NOW(), INTERVAL 180 DAY)
             WHERE user_id = ?'
        )->execute([
            password_hash($new_password, PASSWORD_BCRYPT),
            $token_row['user_id'],
        ]);

        $success_message = 'Your password has been reset successfully.';
        $step = 3; // done
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PORPASS — Reset Password</title>
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

            <?php if (isset($errors['token'])): ?>

                <!-- ── Invalid / expired token ────────────────────────────── -->
                <p class="pp-section-label">Password reset</p>
                <h1 class="pp-section-title">Link expired</h1>
                <div class="pp-panel pp-panel--danger">
                    <p style="font-size: 0.95rem; line-height: 1.7; color: var(--text-muted); margin-top: 0;">
                        <?= htmlspecialchars($errors['token']) ?>
                    </p>
                    <a href="/auth/reset.php" class="pp-btn pp-btn-primary" style="margin-top: 0.5rem;">
                        Request new link
                    </a>
                </div>

            <?php elseif ($step === 3): ?>

                <!-- ── Success — password changed ─────────────────────────── -->
                <p class="pp-section-label">Password reset</p>
                <h1 class="pp-section-title">All set</h1>
                <div class="pp-panel pp-panel--success">
                    <p style="font-size: 0.95rem; line-height: 1.7; color: var(--text-muted); margin-top: 0;">
                        <?= htmlspecialchars($success_message) ?>
                        You can now sign in with your new password.
                    </p>
                    <a href="/login.php" class="pp-btn pp-btn-primary" style="margin-top: 0.5rem;">
                        Sign in
                    </a>
                </div>

            <?php elseif ($step === 2): ?>

                <!-- ── Step 2: New password form ──────────────────────────── -->
                <p class="pp-section-label">Password reset</p>
                <h1 class="pp-section-title">Choose a new password</h1>
                <p class="pp-lead" style="margin-bottom: 2rem;">
                    Enter and confirm your new password below.
                </p>

                <?php if (!empty($errors)): ?>
                    <div class="pp-alert pp-alert-danger">
                        Please correct the errors below.
                    </div>
                <?php endif; ?>

                <div class="pp-panel">
                    <form method="POST"
                          action="/auth/reset.php?token=<?= urlencode($token) ?>"
                          novalidate>

                        <div class="pp-field">
                            <label for="new_password" class="pp-label">
                                New password <span class="pp-required">*</span>
                            </label>
                            <input type="password"
                                   class="pp-input <?= isset($errors['new_password']) ? 'is-invalid' : '' ?>"
                                   id="new_password" name="new_password"
                                   required autofocus
                                   autocomplete="new-password">
                            <div class="pp-field-hint">Minimum 8 characters.</div>
                            <?php if (isset($errors['new_password'])): ?>
                                <div class="pp-field-error">
                                    <?= htmlspecialchars($errors['new_password']) ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="pp-field">
                            <label for="new_password2" class="pp-label">
                                Confirm new password <span class="pp-required">*</span>
                            </label>
                            <input type="password"
                                   class="pp-input <?= isset($errors['new_password2']) ? 'is-invalid' : '' ?>"
                                   id="new_password2" name="new_password2"
                                   required
                                   autocomplete="new-password">
                            <?php if (isset($errors['new_password2'])): ?>
                                <div class="pp-field-error">
                                    <?= htmlspecialchars($errors['new_password2']) ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <button type="submit" class="pp-btn-submit">Reset password</button>
                    </form>
                </div>

            <?php else: ?>

                <!-- ── Step 1: Forgot password form ───────────────────────── -->
                <p class="pp-section-label">Password reset</p>
                <h1 class="pp-section-title">Forgot your password?</h1>
                <p class="pp-lead" style="margin-bottom: 2rem;">
                    Enter your email address and we'll send you a link to reset it.
                </p>

                <?php if ($success_message): ?>

                    <div class="pp-panel pp-panel--success">
                        <p style="font-size: 0.95rem; line-height: 1.7; color: var(--text-muted); margin-top: 0; margin-bottom: 0;">
                            <?= htmlspecialchars($success_message) ?>
                        </p>
                        <p style="margin-top: 1.25rem; margin-bottom: 0;">
                            <a href="/login.php" style="color: var(--teal-500); font-weight: 500; text-decoration: none; font-size: 0.9rem;">
                                ← Back to sign in
                            </a>
                        </p>
                    </div>

                <?php else: ?>

                    <div class="pp-panel">
                        <form method="POST" action="/auth/reset.php" novalidate>

                            <div class="pp-field">
                                <label for="email" class="pp-label">Email address</label>
                                <input type="email"
                                       class="pp-input <?= isset($errors['email']) ? 'is-invalid' : '' ?>"
                                       id="email" name="email"
                                       required autofocus
                                       autocomplete="email">
                                <?php if (isset($errors['email'])): ?>
                                    <div class="pp-field-error">
                                        <?= htmlspecialchars($errors['email']) ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <button type="submit" class="pp-btn-submit">Send reset link</button>

                            <p class="pp-form-links" style="justify-content: center; margin-top: 1.5rem;">
                                <a href="/login.php">← Back to sign in</a>
                            </p>
                        </form>
                    </div>

                <?php endif; ?>

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
</body>
</html>