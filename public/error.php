<?php
/**
 * error.php — Shared 403/404/500 error page.
 *
 * Targeted by Apache's ErrorDocument directives (public/.htaccess) and
 * usable directly (e.g. /error.php?code=404) for testing. Admins see
 * additional debug context; other viewers see a generic message only.
 */
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/auth.php';

session_start_secure();

$code = (int) ($_GET['code'] ?? $_SERVER['REDIRECT_STATUS'] ?? 500);
if (!in_array($code, [403, 404, 500], true)) {
    $code = 404;
}

$titles = [
    403 => 'Access Denied',
    404 => 'Page Not Found',
    500 => 'Something Went Wrong',
];
$messages = [
    403 => "You don't have permission to view this page.",
    404 => "The page you're looking for doesn't exist or may have been moved.",
    500 => 'An unexpected error occurred. Please try again, or contact support if the problem persists.',
];

render_error_page($code, $titles[$code], $messages[$code], null, [
    'Requested URI' => $_SERVER['REQUEST_URI'] ?? '(unknown)',
]);
