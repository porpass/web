<?php
/**
 * layout.php — Shared page layout for PORPASS.
 *
 * Provides open_layout() and close_layout() functions that wrap page content
 * in a consistent HTML shell with branded navigation. Call open_layout() at
 * the top of each page and close_layout() at the bottom.
 */

/**
 * Output the opening HTML, head, and navigation bar.
 *
 * Left side of the navbar contains primary navigation (Dashboard, Browse
 * Observations, Map, Processing). Right side contains Documentation, the Admin
 * dropdown (admin users only), and the username/account dropdown.
 *
 * @param string $title      Page title appended to "PORPASS —" in the <title> element.
 * @param string $head_extra Optional extra HTML to inject into <head> (CSS, JS, etc.).
 * @param string $body_class Optional CSS class(es) to add to the <body> element.
 */
function open_layout(string $title = 'PORPASS', string $head_extra = '', string $body_class = ''): void {
    $username = htmlspecialchars($_SESSION['username'] ?? '');
    $role     = $_SESSION['role'] ?? 'user';
    $is_admin = $role === 'admin';
    $is_dev   = in_array($_ENV['APP_ENV'] ?? '', ['development-local', 'development'], true);

    // Queue count for the "Processing" nav badge. Guarded so an unexpected
    // DB hiccup doesn't take down the whole page chrome.
    $queue_count = 0;
    if (function_exists('get_db') && !empty($_SESSION['user_id'])) {
        try {
            $queue_count = (new \porpass\processing\QueueRepository(get_db()))
                ->count((int) $_SESSION['user_id']);
        } catch (\Throwable $e) {
            $queue_count = 0;
        }
    }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PORPASS — <?= htmlspecialchars($title) ?></title>
    <link href="/resources/css/bootstrap.min.css" rel="stylesheet">
    <link href="/resources/css/porpass.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
    <?= $head_extra ?>
</head>
<body<?= $body_class ? ' class="' . htmlspecialchars($body_class) . '"' : '' ?>>

<!-- ── App navbar ─────────────────────────────────────────────────────────── -->
<nav class="navbar navbar-expand-lg navbar-dark pp-app-nav">
    <div class="container-fluid">

        <a class="navbar-brand" href="/dashboard.php">
            <svg width="44" height="24" viewBox="0 0 44 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <polyline points="0,12 6,12 9,4 13,20 17,8 21,14 24,12 30,12"
                    stroke="#1D9E75" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                <polyline points="4,15 8,15 10,19 14,11 18,16 22,13 25,15 30,15"
                    stroke="#EF9F27" stroke-width="1.1" stroke-linecap="round" stroke-linejoin="round" opacity="0.8"/>
            </svg>
            <span><?= $is_dev ? 'PORPASS-DEV' : 'PORPASS' ?></span>
        </a>

        <button class="navbar-toggler"
                type="button"
                data-bs-toggle="collapse"
                data-bs-target="#porpassNav"
                aria-controls="porpassNav"
                aria-expanded="false"
                aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="porpassNav">

            <!-- Left side: primary navigation -->
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <li class="nav-item">
                    <a class="nav-link" href="/dashboard.php">Dashboard</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="/observations.php">Browse Observations</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="/map.php">Map</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="/processing.php">
                        Processing
                        <span id="nav-queue-badge"
                              class="pp-badge pp-badge-warning"
                              style="margin-left: 0.35rem; font-size: 0.7rem;<?= $queue_count > 0 ? '' : ' display: none;' ?>">
                            <?= (int) $queue_count ?>
                        </span>
                    </a>
                </li>
            </ul>

            <!-- Right side: Documentation, Admin (admin only), username -->
            <ul class="navbar-nav ms-auto mb-2 mb-lg-0">

                <li class="nav-item">
                    <a class="nav-link" href="/docs.php">Documentation</a>
                </li>

                <?php if ($is_admin): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button"
                       data-bs-toggle="dropdown" aria-expanded="false">
                        Admin
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="/admin/admin_dashboard.php">Analytics Dashboard</a></li>
                        <li><a class="dropdown-item" href="/admin/users.php">Manage Users</a></li>
                        <li><a class="dropdown-item" href="/admin/instruments.php">Manage Instruments</a></li>
                        <li><a class="dropdown-item" href="/admin/bodies.php">Manage Bodies</a></li>
                        <li><a class="dropdown-item" href="/admin/institutions.php">Manage Institutions</a></li>
                        <li><a class="dropdown-item" href="/admin/change_requests.php">Change Requests</a></li>
                        <li><a class="dropdown-item" href="/admin/announcements.php">Announcements</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="/admin/docs.php">Admin Documentation</a></li>
                    </ul>
                </li>
                <?php endif; ?>

                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button"
                       data-bs-toggle="dropdown" aria-expanded="false">
                        <?= $username ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="/account.php">Account Settings</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="/logout.php">Sign Out</a></li>
                    </ul>
                </li>

            </ul>

        </div>

    </div>
</nav>

<main class="container-fluid py-4">
<?php
}

/**
 * Output the closing HTML including the page footer and Bootstrap JS.
 */
function close_layout(): void {
?>
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
<?php
}