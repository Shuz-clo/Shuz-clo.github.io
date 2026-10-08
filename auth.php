<?php
if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
    $_SERVER['HTTPS'] = 'on';
}
// Hardened session start: HttpOnly + SameSite cookie, Secure when on HTTPS
if (session_status() === PHP_SESSION_NONE) {
// Hardened session start: HttpOnly + SameSite cookie, Secure when on HTTPS
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// Idle timeout: log out after 30 minutes without a request
if (!defined('AUTH_IDLE_TIMEOUT')) {
    define('AUTH_IDLE_TIMEOUT', 1800);
}

if (!empty($_SESSION['user_id'])) {
    if (isset($_SESSION['last_activity']) && (time() - (int)$_SESSION['last_activity']) > AUTH_IDLE_TIMEOUT) {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['flash']['error'] = 'You were logged out due to inactivity.';
    } else {
        $_SESSION['last_activity'] = time();
    }
}

/**
 * Set a session flash message
 */
if (!function_exists('flash_set')) {
    function flash_set(string $key, string $message): void {
        $_SESSION['flash'][$key] = $message;
    }
}

/**
 * Retrieve and clear a session flash message
 */
if (!function_exists('flash_get')) {
    function flash_get(string $key): ?string {
        if (isset($_SESSION['flash'][$key])) {
            $msg = $_SESSION['flash'][$key];
            unset($_SESSION['flash'][$key]);
            return $msg;
        }
        return null;
    }
}

/**
 * Map user roles to their respective landing pages
 */
if (!function_exists('is_logged_in')) {
    function is_logged_in(): bool {
        return !empty($_SESSION['user_id']);
    }
}

if (!function_exists('current_role')) {
    function current_role(): ?string {
        return $_SESSION['role'] ?? null;
    }
}

if (!function_exists('landing_page_for')) {
    function landing_page_for(?string $role): string {
        switch ($role) {
            case 'admin':
                return './results_2.php';
            case 'rater':
            case 'user':
            default:
                return './index.php';
        }
    }
}

/**
 * Re-read the logged-in user's name and role from the database on every request,
 * so a demoted or deleted account loses access immediately.
 * Needs db.php ($conn) and AuthSchema.php loaded BEFORE auth.php on the page.
 */
if (!function_exists('refresh_session_user')) {
    function refresh_session_user(): void {
        global $conn;
        if (!isset($conn) || !($conn instanceof mysqli) || !function_exists('find_user_by_id')) {
            return; // page didn't load the database first; nothing to check against
        }

        $user = find_user_by_id($conn, (int)$_SESSION['user_id']);
        if ($user === null) {
            $_SESSION = [];
            session_regenerate_id(true);
            flash_set('error', 'Your account is no longer available. Please log in again.');
            header('Location: ./login.php');
            exit;
        }

        $_SESSION['username'] = $user['username'];
        $_SESSION['role']     = $user['role'];
    }
}

/**
 * Require user to be logged in; redirect to login.php if not
 */
if (!function_exists('require_login')) {
    function require_login(): void {
        if (!is_logged_in()) {
            if (!isset($_SESSION['flash']['error'])) {
                flash_set('error', 'Please log in to access this page.');
            }
            header('Location: ./login.php');
            exit;
        }
        refresh_session_user();
    }
}

/**
 * Require a specific user role or array of roles
 */
if (!function_exists('require_role')) {
    function require_role(...$roles): void {
        require_login();

        // Flatten arguments if an array was passed as the first parameter
        if (isset($roles[0]) && is_array($roles[0])) {
            $roles = $roles[0];
        }

        if (!in_array(current_role(), $roles, true)) {
            flash_set('error', 'You do not have permission to access that page.');
            header('Location: ' . landing_page_for(current_role() ?? 'user'));
            exit;
        }
    }
}

/**
 * Require the logged-in user to be an admin
 */
if (!function_exists('require_admin')) {
    function require_admin(): void {
        require_role('admin');
    }
}

/**
 * Get the current logged-in user's ID
 */
if (!function_exists('current_user_id')) {
    function current_user_id(): ?int {
        return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    }
}

/**
 * Get current logged-in user details array or key
 */
if (!function_exists('current_user')) {
    function current_user(?string $key = null) {
        if ($key !== null) {
            return $_SESSION['user'][$key] ?? $_SESSION[$key] ?? null;
        }
        return $_SESSION['user'] ?? null;
    }
}
