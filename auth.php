<?php
// Ensure session is started safely before anything else
if (session_status() === PHP_SESSION_NONE) {
    session_start();
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
 * Require user to be logged in; redirect to login.php if not
 */
if (!function_exists('require_login')) {
    function require_login(): void {
        if (!is_logged_in()) {
            flash_set('error', 'Please log in to access this page.');
            header('Location: ./login.php');
            exit;
        }
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