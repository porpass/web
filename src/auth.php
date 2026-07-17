<?php
/**
 * auth.php — Session and authentication helpers.
 *
 * Provides functions for starting sessions, checking login state,
 * enforcing authentication on protected pages, and role-based access.
 * Also defines constants used across the authentication workflow.
 */

if (defined('AUTH_LOADED')) return;
define('AUTH_LOADED', true);

define('SESSION_TIMEOUT', 1800); // 30 minutes

/**
 * Start a secure PHP session with hardened cookie parameters.
 *
 * Safe to call multiple times — only starts a new session if one is not
 * already active.
 */
function session_start_secure(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => false, // Set to true in production with HTTPS
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }
}

/**
 * Check whether the current session is authenticated and not timed out.
 *
 * Updates the last activity timestamp on each call to keep active sessions
 * alive. Sessions that have been idle longer than SESSION_TIMEOUT are
 * destroyed and the function returns false.
 *
 * @return bool True if the user is logged in and the session is valid.
 */
function is_logged_in(): bool {
    session_start_secure();
    if (empty($_SESSION['user_id'])) {
        return false;
    }
    if (isset($_SESSION['last_activity']) &&
        (time() - $_SESSION['last_activity']) > SESSION_TIMEOUT) {
        session_unset();
        session_destroy();
        return false;
    }
    $_SESSION['last_activity'] = time();
    return true;
}

/**
 * Require the user to be logged in.
 *
 * Redirects to the login page if the session is not authenticated or has
 * timed out. Does not return if the redirect fires.
 */
function require_login(): void {
    if (!is_logged_in()) {
        header('Location: /login.php');
        exit;
    }
}

/**
 * Require the user to be an admin.
 *
 * Calls require_login() first, then redirects to the dashboard if the
 * authenticated user does not have the admin role. Does not return if
 * either redirect fires.
 */
function require_admin(): void {
    require_login();
    if ($_SESSION['role'] !== 'admin') {
        header('Location: /dashboard.php');
        exit;
    }
}

/**
 * Log the current user out and redirect to the login page.
 *
 * Clears all session data, destroys the session, and redirects.
 * Does not return.
 */
function logout(): void {
    session_start_secure();
    session_unset();
    session_destroy();
    header('Location: /login.php');
    exit;
}

/**
 * Return a human-readable login error message based on user record state.
 *
 * Evaluates the user record returned from the database and returns the
 * appropriate error string for display on the login form.
 *
 * Login state priority:
 *   1. Email not verified  → prompt to check inbox
 *   2. Account not active  → pending admin approval
 *   3. Active and verified → null (no error, login should proceed)
 *
 * @param array $user Associative array with keys: email_verified, is_active.
 *
 * @return string|null Error message string, or null if the account is valid.
 */
function get_login_error(array $user): ?string {
    if (!$user['email_verified']) {
        return 'Please verify your email address before signing in. '
             . 'Check your inbox for the verification link.';
    }
    if (!$user['is_active']) {
        return 'Your account is pending administrator approval. '
             . 'You will be notified when your account has been reviewed.';
    }
    return null;
}