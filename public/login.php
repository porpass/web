<?php
/**
 * login.php — User login page.
 *
 * Handles both display of the login form and processing of POST submissions.
 * Checks email verification and account approval state before allowing login.
 * On success, redirects admin users to /admin/users.php and regular users
 * to /dashboard.php.
 */

require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/db.php';

session_start_secure();

// Redirect already-authenticated users
if (is_logged_in()) {
    header('Location: /dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email']    ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($email) || empty($password)) {
        $error = 'Please enter your email address and password.';
    } else {
        $db   = get_db();
        $stmt = $db->prepare(
            'SELECT user_id, username, password_hash, role,
                    is_active, email_verified
             FROM users WHERE email = ?'
        );
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $error = 'Invalid email address or password.';
        } else {
            $state_error = get_login_error($user);
            if ($state_error) {
                $error = $state_error;
            } else {
                session_regenerate_id(true);
                $_SESSION['user_id']       = $user['user_id'];
                $_SESSION['username']      = $user['username'];
                $_SESSION['role']          = $user['role'];
                $_SESSION['last_activity'] = time();

                $db->prepare(
                    'UPDATE users SET last_login_at = NOW() WHERE user_id = ?'
                )->execute([$user['user_id']]);

                if ($user['role'] === 'admin') {
                    header('Location: /admin/users.php');
                } else {
                    header('Location: /dashboard.php');
                }
                exit;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PORPASS — Sign In</title>
    <link href="/resources/css/bootstrap.min.css" rel="stylesheet">
    <link href="/resources/css/porpass.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
</head>
<body>

<div class="pp-login-wrap">

    <!-- ── Brand panel ────────────────────────────────────────────────────── -->
    <div class="pp-brand-panel">
        <div class="pp-brand-content">

            <!-- Inline SVG wordmark -->
            <svg class="pp-brand-wordmark" viewBox="0 0 680 160" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="PORPASS">
                <polyline points="52,72 72,72 79,55 87,91 95,65 102,80 109,72 129,72"
                    fill="none" stroke="#1D9E75" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
                <polyline points="66,82 74,82 80,91 88,72 96,84 103,77 110,82 124,82"
                    fill="none" stroke="#EF9F27" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" opacity="0.8"/>
                <text x="340" y="82" text-anchor="middle"
                    font-family="'DM Sans', sans-serif" font-size="44" font-weight="500"
                    letter-spacing="7" fill="#5DCAA5">PORPASS</text>
                <polyline points="551,72 571,72 578,80 585,65 593,91 601,55 609,72 628,72"
                    fill="none" stroke="#1D9E75" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
                <polyline points="556,82 570,82 577,77 584,84 592,72 600,91 606,82 614,82"
                    fill="none" stroke="#EF9F27" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" opacity="0.8"/>
                <text x="340" y="114" text-anchor="middle"
                    font-family="'DM Sans', sans-serif" font-size="10" letter-spacing="2.2"
                    fill="#5DCAA5" opacity="0.75">PLANETARY ORBITAL RADAR PROCESSING &amp; SIMULATION SYSTEM</text>
                <line x1="52"  y1="106" x2="148" y2="106" stroke="#1D9E75" stroke-width="0.5" opacity="0.4"/>
                <line x1="532" y1="106" x2="628" y2="106" stroke="#1D9E75" stroke-width="0.5" opacity="0.4"/>
            </svg>

            <p class="pp-brand-desc">
                Custom processing and simulation of planetary radar sounder data
                from Mars and the Moon.
            </p>
        </div>
        <p class="pp-brand-footer">Planetary Science Institute &mdash; Tucson, AZ</p>
    </div>

    <!-- ── Form panel ─────────────────────────────────────────────────────── -->
    <div class="pp-form-panel">
        <div class="pp-form-inner">

            <h1 class="pp-form-title">Sign in</h1>
            <p class="pp-form-sub">Use your PORPASS account credentials.</p>

            <?php if ($error): ?>
                <div class="pp-alert">
                    <?= htmlspecialchars($error) ?>
                    <?php if (str_contains($error, 'verify your email')): ?>
                        <br><a href="/auth/verify.php">Resend verification email</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="/login.php" novalidate>

                <div class="pp-field">
                    <label class="pp-label" for="email">Email address</label>
                    <input class="pp-input" type="email" id="email" name="email"
                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                           required autofocus autocomplete="email">
                </div>

                <div class="pp-field">
                    <label class="pp-label" for="password">Password</label>
                    <input class="pp-input" type="password" id="password" name="password"
                           required autocomplete="current-password">
                </div>

                <button type="submit" class="pp-btn-submit">Sign In</button>

                <div class="pp-form-links">
                    <a href="/auth/reset.php">Forgot your password?</a>
                    <a href="/register.php">Create an account</a>
                </div>

            </form>

            <div class="pp-divider">or</div>

            <p style="font-size:0.82rem; color:var(--text-muted); text-align:center; margin:0;">
                Don't have an account?
                <a href="/register.php" style="color:var(--teal-500); text-decoration:none; font-weight:500;">Request access</a>
            </p>

        </div>
    </div>

</div>

<script src="/resources/js/bootstrap.bundle.min.js"></script>
</body>
</html>