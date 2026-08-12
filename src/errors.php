<?php
/**
 * errors.php — Central error/exception rendering and global handlers.
 *
 * Provides render_error_page() for consistent branded 403/404/500 pages
 * (admins additionally see the underlying exception, e.g. SQL error text,
 * to help diagnose production issues without needing server log access),
 * plus install_error_handlers() which registers a global exception handler
 * and a shutdown-based fatal error catcher so uncaught PHP errors render
 * this page instead of falling through to raw PHP output.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';

/**
 * Whether the current viewer is an authenticated admin.
 *
 * Safe to call from any context, including error handlers that may fire
 * before a page has started its session.
 */
function pp_is_admin_viewer(): bool {
    session_start_secure();
    return ($_SESSION['role'] ?? '') === 'admin';
}

/**
 * Render a branded error page. Admins additionally see exception detail.
 *
 * @param int             $status_code HTTP status code to send (403, 404, 500, ...).
 * @param string          $title       Short human-readable title, e.g. "Page Not Found".
 * @param string          $message     User-facing explanation.
 * @param \Throwable|null $exception   The underlying exception, if any, shown to admins only.
 * @param array<string,string> $context Extra admin-only key/value context (e.g. requested URI).
 */
function render_error_page(
    int $status_code,
    string $title,
    string $message,
    ?\Throwable $exception = null,
    array $context = []
): void {
    if (!headers_sent()) {
        http_response_code($status_code);
    }

    $is_admin = pp_is_admin_viewer();

    open_layout($title);
    ?>
    <div class="pp-container-narrow">
        <div class="pp-panel pp-panel--danger">
            <h1 class="pp-panel-title"><?= htmlspecialchars((string) $status_code) ?> — <?= htmlspecialchars($title) ?></h1>
            <p><?= htmlspecialchars($message) ?></p>
        </div>

        <?php if ($is_admin && ($exception !== null || !empty($context))): ?>
        <div class="pp-panel pp-panel--warning" style="margin-top: 1.5rem;">
            <h2 class="pp-panel-title">Debug details (admins only)</h2>
            <?php if ($exception !== null): ?>
                <p><strong>Exception:</strong> <?= htmlspecialchars(get_class($exception)) ?></p>
                <p><strong>Message:</strong> <?= htmlspecialchars($exception->getMessage()) ?></p>
                <p><strong>Location:</strong> <?= htmlspecialchars($exception->getFile()) ?>:<?= (int) $exception->getLine() ?></p>
                <pre style="white-space: pre-wrap; font-size: 0.8rem;"><?= htmlspecialchars($exception->getTraceAsString()) ?></pre>
            <?php endif; ?>
            <?php foreach ($context as $label => $value): ?>
                <p><strong><?= htmlspecialchars($label) ?>:</strong> <?= htmlspecialchars($value) ?></p>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php
    close_layout();
}

/**
 * Render an error page, falling back to dependency-free inline HTML if
 * rendering itself fails (e.g. layout chrome depends on something that's
 * also broken). Called from the global handlers below, where a second
 * failure must never produce a blank screen or raw PHP fatal.
 */
function pp_safe_render_error(
    int $status_code,
    string $title,
    string $message,
    ?\Throwable $exception = null,
    array $context = []
): void {
    try {
        render_error_page($status_code, $title, $message, $exception, $context);
    } catch (\Throwable $render_failure) {
        error_log('render_error_page itself failed: ' . $render_failure->getMessage());
        if (!headers_sent()) {
            http_response_code($status_code);
        }
        echo '<!doctype html><html><body style="font-family: sans-serif;">'
           . '<h1>' . htmlspecialchars((string) $status_code) . ' — ' . htmlspecialchars($title) . '</h1>'
           . '<p>' . htmlspecialchars($message) . '</p>'
           . '</body></html>';
    }
}

/**
 * Install global handlers so uncaught exceptions and fatal errors render
 * the branded error page instead of PHP's raw output.
 *
 * Requests under /api/ get the existing JSON error envelope
 * ({"ok":false,"error":...}) instead of HTML, to match how API endpoints
 * already report their own caught errors.
 */
function install_error_handlers(): void {
    set_exception_handler(function (\Throwable $e): void {
        error_log('Uncaught exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());

        $is_api = str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/api/');
        if ($is_api) {
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: application/json');
            }
            $detail = pp_is_admin_viewer() ? $e->getMessage() : 'Internal server error';
            echo json_encode(['ok' => false, 'error' => $detail]);
            return;
        }

        pp_safe_render_error(
            500,
            'Something Went Wrong',
            'An unexpected error occurred. Please try again, or contact support if the problem persists.',
            $e
        );
    });

    register_shutdown_function(function (): void {
        $error = error_get_last();
        if ($error === null) {
            return;
        }
        if (!in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }

        error_log('Fatal error: ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line']);

        if (headers_sent()) {
            return;
        }

        $is_api = str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/api/');
        if ($is_api) {
            http_response_code(500);
            header('Content-Type: application/json');
            $detail = pp_is_admin_viewer() ? $error['message'] : 'Internal server error';
            echo json_encode(['ok' => false, 'error' => $detail]);
            return;
        }

        pp_safe_render_error(
            500,
            'Something Went Wrong',
            'An unexpected error occurred. Please try again, or contact support if the problem persists.'
        );
    });
}
