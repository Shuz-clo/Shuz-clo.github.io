<?php

require_once 'auth.php';
require_once __DIR__ . '/config.php';
require_once 'db.php';
require_once __DIR__ . '/AuthSchema.php';
require_once __DIR__ . '/Audit.php';

if (is_logged_in()) {
    audit_log($conn, 0, 'logout',
        null,
        ['user_id' => current_user_id(), 'username' => $_SESSION['username'] ?? null]
    );
}

if (!empty($_COOKIE['remember_admin'])) {
    delete_remember_token($conn, $_COOKIE['remember_admin']);
}

$_SESSION = [];

if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires'  => time() - 42000,
        'path'     => $params['path'] ?? '/',
        'domain'   => $params['domain'] ?? '',
        'secure'   => !empty($params['secure']),
        'httponly' => !empty($params['httponly']),
        'samesite' => $params['samesite'] ?? 'Lax',
    ]);
}

setcookie('remember_admin', '', [
    'expires'  => time() - 3600,
    'path'     => '/',
    'secure'   => !empty($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_destroy();

session_start();
session_regenerate_id(true);

flash_set('success', 'You have been logged out. You can now log in or create a new account.');

header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

header("Location: login.php");
exit();
