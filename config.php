<?php
// Settings come from environment variables (set them in Render -> Environment).
// On your own XAMPP none are set, so the local defaults below are used.
$__local = (getenv('DB_HOST') === false);

define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_PORT', (int)(getenv('DB_PORT') ?: 3306));
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
define('DB_NAME', getenv('DB_NAME') ?: 'interview_rating');

date_default_timezone_set('Asia/Manila');

// Error details show only on your own computer; online they stay hidden.
define('DEBUG', $__local);

// Online: set REGISTRATION_CODE in Render. If it is forgotten, nobody can register.
define('REGISTRATION_CODE', getenv('REGISTRATION_CODE') ?: ($__local ? 'local-test-code' : bin2hex(random_bytes(16))));
