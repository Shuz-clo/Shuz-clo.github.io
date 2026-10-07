<?php
require_once __DIR__ . '/config.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (!DEBUG) {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}

try {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $conn->set_charset("utf8mb4");
    $conn->query("SET time_zone = '+08:00'");
} catch (mysqli_sql_exception $e) {
    error_log('DB connection failed: ' . $e->getMessage());
    http_response_code(500);

    if (!DEBUG) {
        die('Service temporarily unavailable.');
    }

    die("
        <div style='font-family: Arial, sans-serif; padding: 20px; background: #ffe6e6; border: 1px solid #ff0000; border-radius: 5px; margin: 20px;'>
            <h3 style='color: #cc0000; margin-top: 0;'>Database Connection Error</h3>
            <p><strong>PHP Details:</strong> " . htmlspecialchars($e->getMessage()) . "</p>
            <hr>
            <p><strong>Troubleshooting Steps:</strong></p>
            <ol>
                <li>Open <a href='http://localhost/phpmyadmin' target='_blank'>http://localhost/phpmyadmin</a> in your browser.</li>
                <li>Verify if a database named <strong><code>" . htmlspecialchars(DB_NAME) . "</code></strong> exists.</li>
            </ol>
        </div>
    ");
}
?>