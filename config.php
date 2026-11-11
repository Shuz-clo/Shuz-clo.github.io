<?php
if (is_file(__DIR__ . '/config.local.php')) require __DIR__ . '/config.local.php';
if (!function_exists('app_allow_ddl')) {
    function app_allow_ddl(): bool { return PHP_SAPI === 'cli'; }
}
$appEnv = getenv('APP_ENV');
$appEnv = getenv('APP_ENV');
$__local = (($appEnv === false || $appEnv === 'local') && (getenv('DB_HOST') === false));
define('APP_ENV', $appEnv === false ? 'local' : $appEnv);
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_PORT', (int)(getenv('DB_PORT') ?: 3306));
define('DB_USER', getenv('DB_USER') ?: 'interview_app');
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
define('DB_NAME', getenv('DB_NAME') ?: 'interview_rating');
date_default_timezone_set('Asia/Manila');
define('DEBUG', $__local);
define('APP_DEV_MODE', $__local);
define('REGISTRATION_CODE', getenv('REGISTRATION_CODE') ?: ($__local ? 'poopoo' : bin2hex(random_bytes(16))));
if (!defined('HONEYPOT_FIELD')) define('HONEYPOT_FIELD', '_hp_field');
if (!defined('AUTH_IDLE_TIMEOUT')) define('AUTH_IDLE_TIMEOUT', 1800);
if (!defined('AUTH_MAX_ATTEMPTS')) define('AUTH_MAX_ATTEMPTS', 5);
if (!defined('AUTH_LOCKOUT_MINUTES')) define('AUTH_LOCKOUT_MINUTES', 15);