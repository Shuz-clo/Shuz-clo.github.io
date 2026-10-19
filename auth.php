<?php
// ============================================================
// auth.php — COMPLETE FIXED FILE
//
// FIX (Bug C): refresh_session_user(), require_login(),
//              require_role() and require_admin() now accept an
//              $apiMode flag. When true, they return a JSON 401/
//              403 instead of issuing an HTML redirect, so JSON
//              endpoints never receive a 302 -> HTML login page
//              that breaks fetch().json() parsing.
// ============================================================

if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
    $_SERVER['HTTPS'] = 'on';
}

if (!defined('AUTH_IDLE_TIMEOUT')) {
    define('AUTH_IDLE_TIMEOUT', 1800);
}

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
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

if (!function_exists('flash_set')) {
    function flash_set(string $key, string $message): void {
        $_SESSION['flash'][$key] = $message;
    }
}

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

if (!function_exists('refresh_session_user')) {
    function refresh_session_user(bool $apiMode = false): void {
        global $conn;
        if (!isset($conn) || !($conn instanceof mysqli) || !function_exists('find_user_by_id')) {
            return;
        }

        $user = find_user_by_id($conn, (int)$_SESSION['user_id']);
        if ($user === null) {
            $_SESSION = [];
            session_regenerate_id(true);

            if ($apiMode) {
                http_response_code(401);
                header('Content-Type: application/json');
                echo json_encode(['status' => 'error', 'message' => 'Your account is no longer available. Please log in again.']);
                exit;
            }

            flash_set('error', 'Your account is no longer available. Please log in again.');
            header('Location: ./login.php');
            exit;
        }

        $_SESSION['username'] = $user['username'];
        $_SESSION['role']     = $user['role'];
    }
}

if (!function_exists('require_login')) {
    function require_login(bool $apiMode = false): void {
        if (!is_logged_in()) {
            if ($apiMode) {
                http_response_code(401);
                header('Content-Type: application/json');
                echo json_encode(['status' => 'error', 'message' => 'Please log in to access this page.']);
                exit;
            }
            if (!isset($_SESSION['flash']['error'])) {
                flash_set('error', 'Please log in to access this page.');
            }
            header('Location: ./login.php');
            exit;
        }
        refresh_session_user($apiMode);
    }
}

if (!function_exists('require_role')) {
    function require_role(...$args): void {
        $apiMode = false;
        if (isset($args[0]) && is_bool($args[0])) {
            $apiMode = array_shift($args);
        }
        if (isset($args[0]) && is_array($args[0])) {
            $args = $args[0];
        }
        require_login($apiMode);

        if (!in_array(current_role(), $args, true)) {
            if ($apiMode) {
                http_response_code(403);
                header('Content-Type: application/json');
                echo json_encode(['status' => 'error', 'message' => 'You do not have permission to access that page.']);
                exit;
            }
            flash_set('error', 'You do not have permission to access that page.');
            header('Location: ' . landing_page_for(current_role() ?? 'user'));
            exit;
        }
    }
}

if (!function_exists('require_admin')) {
    function require_admin(bool $apiMode = false): void {
        require_role($apiMode, 'admin');
    }
}

if (!function_exists('current_user_id')) {
    function current_user_id(): ?int {
        return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    }
}

if (!function_exists('current_user')) {
    function current_user(?string $key = null) {
        if ($key !== null) {
            return $_SESSION['user'][$key] ?? $_SESSION[$key] ?? null;
        }
        return $_SESSION['user'] ?? null;
    }
}
