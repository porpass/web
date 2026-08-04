<?php
/**
 * verify.php — Email address verification.
 *
 * Called when a newly registered user clicks the verification link sent to
 * their email. Validates the token, marks the user's email as verified, and
 * clears the token. After verification the account still requires admin
 * approval (is_active = 1) before the user can sign in.
 */

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../vendor/autoload.php';

session_start_secure();

$db    = get_db();
$token = trim($_GET['token'] ?? '');
$state = 'invalid'; // invalid | expired | success

if (!empty($token)) {
    $hashed = hash('sha256', $token);

    $stmt = $db->prepare(
        'SELECT user_id, email, email_verified, email_verify_expires
         FROM users
         WHERE email_verify_token = ?'
    );
    $stmt->execute([$hashed]);
    $user = $stmt->fetch();

    if (!$user) {
        $state = 'invalid';
    } elseif ((int)$user['email_verified'] === 1) {
        // Token still on record but email already verified — treat as success.
        $state = 'success';
    } elseif (strtotime($user['email_verify_expires']) < time()) {
        $state = 'expired';
    } else {
        $db->prepare(
            'UPDATE users
             SET email_verified       = 1,
                 email_verify_token   = NULL,
                 email_verify_expires = NULL
             WHERE user_id = ?'
        )->execute([$user['user_id']]);
        $state = 'success';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PORPASS — Email Verification</title>
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
        <?php if (is_logged_in()): ?>
            <a class="pp-nav-signin" href="/account.php">Account Settings</a>
        <?php else: ?>
            <a class="pp-nav-signin" href="/login.php">Sign In</a>
        <?php endif; ?>
    </div>
</nav>

<main class="container">
    <section class="pp-section">
        <div class="pp-container-narrow">

            <p class="pp-section-label">Email verification</p>

            <?php if ($state === 'success'): ?>

                <h1 class="pp-section-title">Email address verified</h1>
                <div class="pp-panel pp-panel--success">
                    <p style="font-size: 0.95rem; line-height: 1.7; color: var(--text-muted); margin-top: 0;">
                        Thank you for verifying your email address. Your account is now
                        pending administrator approval &mdash; you will be notified once
                        your account has been reviewed and activated.
                    </p>
                    <a href="/login.php" class="pp-btn pp-btn-primary" style="margin-top: 0.5rem;">
                        Return to sign in
                    </a>
                </div>

            <?php elseif ($state === 'expired'): ?>

                <h1 class="pp-section-title">Link expired</h1>
                <div class="pp-panel pp-panel--warning">
                    <p style="font-size: 0.95rem; line-height: 1.7; color: var(--text-muted); margin-top: 0;">
                        This verification link has expired. Please register again
                        to request a new verification email.
                    </p>
                    <a href="/register.php" class="pp-btn pp-btn-primary" style="margin-top: 0.5rem;">
                        Register
                    </a>
                </div>

            <?php else: ?>

                <h1 class="pp-section-title">Invalid link</h1>
                <div class="pp-panel pp-panel--danger">
                    <p style="font-size: 0.95rem; line-height: 1.7; color: var(--text-muted); margin-top: 0;">
                        This verification link is invalid or has already been used.
                        If you already verified your email, you can sign in below.
                    </p>
                    <a href="/login.php" class="pp-btn pp-btn-primary" style="margin-top: 0.5rem;">
                        Sign in
                    </a>
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
</body>
</html>
