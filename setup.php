<?php
// Run once from the command line: php create_admin.php
if (PHP_SAPI !== 'cli') { exit("Run from the command line only.\n"); }
if (getenv('DB_USER') === false) { putenv('DB_USER=root'); putenv('DB_PASS='); }

require __DIR__ . '/config.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
$conn->set_charset('utf8mb4');

echo "Admin username: ";
$username = trim((string)fgets(STDIN));
echo "Admin password (min 8 chars, shown as you type): ";
$password = rtrim((string)fgets(STDIN), "\r\n");

if ($username === '' || strlen($password) < 8) { exit("Username required and password must be 8+ characters.\n"); }

$hash = password_hash($password, PASSWORD_DEFAULT);
$stmt = $conn->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, 'admin')
                        ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), role = 'admin'");
$stmt->bind_param('ss', $username, $hash);
$stmt->execute();
echo "Admin '$username' is ready.\n";