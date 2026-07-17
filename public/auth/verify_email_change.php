<?php
/**
 * verify_email_change.php — Email change verification.
 *
 * Called when a user clicks the confirmation link sent to their new email
 * address after submitting an email change request via Account Settings.
 * Validates the token, updates the user's email address, and marks the
 * change request as approved.
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
        'SELECT request_id, user_id, old_value, new_value, token_expires
         FROM user_change_requests
         WHERE verification_token = ?
           AND field  = ?
           AND status = ?'
    );
    $stmt->execute([$hashed, 'email', 'pending']);
    $request = $stmt->fetch();

    if (!$request) {
        $state = 'invalid';
    } elseif (strtotime($request['token_expires']) < time()) {
        $db->prepare(
            'UPDATE user_change_requests SET status = ? WHERE request_id = ?'
        )->execute(['expired', $request['request_id']]);
        $state = 'expired';
    } else {
        $db->prepare(
            'UPDATE users SET email = ? WHERE user_id = ?'
        )->execute([$request['new_value'], $request['user_id']]);

        $db->prepare(
            'UPDATE user_change_requests
             SET status      = ?,
                 reviewed_at = NOW()
             WHERE request_id = ?'
        )->execute(['approved', $request['request_id']]);

        if (is_logged_in() && $_SESSION['user_id'] === (int)$request['user_id']) {
            $_SESSION['email'] = $request['new_value'];
        }

        $state = 'success';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PORPASS — Email Change Confirmation</title>
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

            <p class="pp-section-label">Email change</p>

            <?php if ($state === 'success'): ?>

                <h1 class="pp-section-title">Email address updated</h1>
                <div class="pp-panel pp-panel--success">
                    <p style="font-size: 0.95rem; line-height: 1.7; color: var(--text-muted); margin-top: 0;">
                        Your email address has been successfully updated to
                        <strong style="color: var(--text-primary);"><?= htmlspecialchars($request['new_value']) ?></strong>.
                    </p>
                    <?php if (is_logged_in()): ?>
                        <a href="/account.php" class="pp-btn pp-btn-primary" style="margin-top: 0.5rem;">
                            Back to account settings
                        </a>
                    <?php else: ?>
                        <a href="/login.php" class="pp-btn pp-btn-primary" style="margin-top: 0.5rem;">
                            Sign in
                        </a>
                    <?php endif; ?>
                </div>

            <?php elseif ($state === 'expired'): ?>

                <h1 class="pp-section-title">Link expired</h1>
                <div class="pp-panel pp-panel--warning">
                    <p style="font-size: 0.95rem; line-height: 1.7; color: var(--text-muted); margin-top: 0;">
                        This email change confirmation link has expired.
                        Please submit a new email change request from your
                        Account Settings.
                    </p>
                    <?php if (is_logged_in()): ?>
                        <a href="/account.php" class="pp-btn pp-btn-primary" style="margin-top: 0.5rem;">
                            Account settings
                        </a>
                    <?php else: ?>
                        <a href="/login.php" class="pp-btn pp-btn-primary" style="margin-top: 0.5rem;">
                            Sign in
                        </a>
                    <?php endif; ?>
                </div>

            <?php else: ?>

                <h1 class="pp-section-title">Invalid link</h1>
                <div class="pp-panel pp-panel--danger">
                    <p style="font-size: 0.95rem; line-height: 1.7; color: var(--text-muted); margin-top: 0;">
                        This confirmation link is invalid or has already been used.
                        If you need to change your email address, please submit a
                        new request from your Account Settings.
                    </p>
                    <?php if (is_logged_in()): ?>
                        <a href="/account.php" class="pp-btn pp-btn-primary" style="margin-top: 0.5rem;">
                            Account settings
                        </a>
                    <?php else: ?>
                        <a href="/login.php" class="pp-btn pp-btn-primary" style="margin-top: 0.5rem;">
                            Sign in
                        </a>
                    <?php endif; ?>
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