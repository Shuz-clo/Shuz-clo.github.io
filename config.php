<?php
if (is_file(__DIR__ . '/config.local.php')) require __DIR__ . '/config.local.php';
if (!function_exists('app_allow_ddl')) {
    function app_allow_ddl(): bool { return PHP_SAPI === 'cli'; }
}

$appEnv = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? getenv('APP_ENV');
$dbHost = $_SERVER['DB_HOST'] ?? $_ENV['DB_HOST'] ?? getenv('DB_HOST');
$dbPort = $_SERVER['DB_PORT'] ?? $_ENV['DB_PORT'] ?? getenv('DB_PORT');
$dbUser = $_SERVER['DB_USER'] ?? $_ENV['DB_USER'] ?? getenv('DB_USER');
$dbPass = $_SERVER['DB_PASS'] ?? $_ENV['DB_PASS'] ?? getenv('DB_PASS');
$dbName = $_SERVER['DB_NAME'] ?? $_ENV['DB_NAME'] ?? getenv('DB_NAME');

$__local = (($appEnv === false || $appEnv === null || $appEnv === 'local') && ($dbHost === false || $dbHost === null));

define('APP_ENV', $appEnv ? $appEnv : 'local');
define('DB_HOST', $dbHost ? $dbHost : 'sql.freedb.tech');
define('DB_PORT', (int)($dbPort ? $dbPort : 3306));
define('DB_USER', $dbUser ? $dbUser : 'u_EnZQm2');
define('DB_PASS', $dbPass !== false && $dbPass !== null ? $dbPass : 'xp6ykz2fBJuM');
define('DB_NAME', $dbName ? $dbName : 'freedb_PEARyuWU');

date_default_timezone_set('Asia/Manila');
define('DEBUG', $__local);
define('APP_DEV_MODE', $__local);
define('REGISTRATION_CODE', getenv('REGISTRATION_CODE') ?: ($__local ? 'poopoo' : bin2hex(random_bytes(16))));
if (!defined('HONEYPOT_FIELD')) define('HONEYPOT_FIELD', '_hp_field');
if (!defined('AUTH_IDLE_TIMEOUT')) define('AUTH_IDLE_TIMEOUT', 1800);
if (!defined('AUTH_MAX_ATTEMPTS')) define('AUTH_MAX_ATTEMPTS', 5);
if (!defined('AUTH_LOCKOUT_MINUTES')) define('AUTH_LOCKOUT_MINUTES', 15);
