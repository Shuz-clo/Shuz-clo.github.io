<?php
require_once 'auth.php';
require_once __DIR__ . '/config.php';
require_once 'db.php';
require_once __DIR__ . '/AuthSchema.php';

// Delete "Remember Me" token from database if present
if (!empty($_COOKIE['remember_admin'])) {
    delete_remember_token($conn, $_COOKIE['remember_admin']);
}

// Clear session variables
$_SESSION = array();

// Delete session cookie properly with exact params
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// Delete persistent remember cookie
setcookie('remember_admin', '', time() - 3600, '/', '', !empty($_SERVER['HTTPS']), true);

// Destroy existing session
session_destroy();

// Start fresh session to pass logout notification message
session_start();
session_regenerate_id(true);

flash_set('success', 'You have been logged out. You can now log in or create a new account.');

header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

header("Location: login.php");
exit();
?>