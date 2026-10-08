<?php
// Settings come from environment variables on Render, or fall back to FreeDB defaults locally.
$__local = (getenv('DB_HOST') === false);

define('DB_HOST', getenv('DB_HOST') ?: 'sql.freedb.tech');
define('DB_PORT', (int)(getenv('DB_PORT') ?: 3306));
define('DB_USER', getenv('DB_USER') ?: 'u_sMvIn5');
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : 'YOUR_FREEDB_PASSWORD_HERE');
define('DB_NAME', getenv('DB_NAME') ?: 'freedb_8Y0ESw9X');

date_default_timezone_set('Asia/Manila');

// Error details show only on your own computer; online they stay hidden.
define('DEBUG', $__local);

// Online: set REGISTRATION_CODE in Render. If it is forgotten, nobody can register.
define('REGISTRATION_CODE', getenv('REGISTRATION_CODE') ?: ($__local ? 'poopoo' : bin2hex(random_bytes(16))));
