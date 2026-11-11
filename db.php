<?php
require_once __DIR__ . '/config.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
if (!DEBUG) { ini_set('display_errors', '0'); ini_set('log_errors', '1'); }
try {
    // Hardcoded directly to bypass any environment variable parsing or mismatch issues
    $conn = new mysqli('sql.freedb.tech', 'u_EnZQm2', 'xp6ykz2fBJuM', 'freedb_PEARyuWU', 3306);
    $conn->set_charset("utf8mb4");
    $conn->query("SET time_zone = '+08:00'");
} catch (mysqli_sql_exception $e) {
    error_log('DB connection failed: ' . $e->getMessage());
    http_response_code(500);
    if (!DEBUG) die('Service temporarily unavailable.');
    die("<h3>Database Connection Error</h3><p>" . htmlspecialchars($e->getMessage()) . "</p>");
}
