<?php
function auth_ensure_column(mysqli $conn, string $table, string $column, string $definitionSql): void {
    try {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table) || !preg_match('/^[a-zA-Z0-9_]+$/', $column)) return;
        $stmt = $conn->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
        $stmt->bind_param('ss', $table, $column); $stmt->execute(); $exists = $stmt->get_result();
        if ($exists && $exists->num_rows === 0) $conn->query("ALTER TABLE `$table` ADD COLUMN `$column` $definitionSql");
        $stmt->close();
    } catch (Throwable $e) { error_log("auth_ensure_column {$table}.{$column}: " . $e->getMessage()); }
}

function ensure_auth_schema(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS users (id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(100) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL, role ENUM('admin','rater','user') NOT NULL DEFAULT 'user', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) DEFAULT CHARSET=utf8mb4");
    auth_ensure_column($conn, 'users', 'region', 'VARCHAR(100) NULL DEFAULT NULL');
    auth_ensure_column($conn, 'users', 'field_name', 'VARCHAR(100) NULL DEFAULT NULL');
    auth_ensure_column($conn, 'users', 'salary_grade', 'TINYINT UNSIGNED NULL DEFAULT NULL');
    $conn->query("CREATE TABLE IF NOT EXISTS remember_tokens (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, token_hash CHAR(64) NOT NULL UNIQUE, expires_at DATETIME NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) DEFAULT CHARSET=utf8mb4");
    $conn->query("CREATE TABLE IF NOT EXISTS login_attempts (ip VARCHAR(45) PRIMARY KEY, attempts INT NOT NULL DEFAULT 0, locked_until DATETIME NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) DEFAULT CHARSET=utf8mb4");
}

function find_user_by_username(mysqli $conn, string $username): ?array {
    $stmt = $conn->prepare("SELECT id, username, password_hash, role FROM users WHERE username = ? LIMIT 1");
    $stmt->bind_param('s', $username); $stmt->execute(); $row = $stmt->get_result()->fetch_assoc(); $stmt->close(); return $row ?: null;
}

function find_user_by_id(mysqli $conn, int $id): ?array {
    $stmt = $conn->prepare("SELECT id, username, role FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $id); $stmt->execute(); $row = $stmt->get_result()->fetch_assoc(); $stmt->close(); return $row ?: null;
}

function find_user_access_profile(mysqli $conn, int $id): ?array {
    $stmt = $conn->prepare("SELECT id, username, role, region, field_name, salary_grade FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $id); $stmt->execute(); $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if ($row) $row['salary_grade'] = $row['salary_grade'] === null ? null : (int)$row['salary_grade'];
    return $row ?: null;
}

function issue_remember_token(mysqli $conn, int $userId, int $days = 30): string {
    $token = bin2hex(random_bytes(32)); $hash = hash('sha256', $token); $expires = date('Y-m-d H:i:s', time() + ($days * 86400));
    $stmt = $conn->prepare("INSERT INTO remember_tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)");
    $stmt->bind_param('iss', $userId, $hash, $expires); $stmt->execute(); $stmt->close(); return $token;
}

function verify_remember_token(mysqli $conn, string $token): ?array {
    $hash = hash('sha256', $token);
    $stmt = $conn->prepare("SELECT u.id, u.username, u.role FROM remember_tokens t JOIN users u ON u.id = t.user_id WHERE t.token_hash = ? AND t.expires_at > NOW() LIMIT 1");
    $stmt->bind_param('s', $hash); $stmt->execute(); $row = $stmt->get_result()->fetch_assoc(); $stmt->close(); return $row ?: null;
}

function delete_remember_token(mysqli $conn, ?string $token): void {
    if (!$token) return; $hash = hash('sha256', $token);
    $stmt = $conn->prepare("DELETE FROM remember_tokens WHERE token_hash = ?");
    $stmt->bind_param('s', $hash); $stmt->execute(); $stmt->close();
}

function purge_expired_tokens(mysqli $conn): void { $conn->query("DELETE FROM remember_tokens WHERE expires_at <= NOW()"); }

function request_ip(): string {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_TRUE_CLIENT_IP'] as $h) { $v = trim((string)($_SERVER[$h] ?? '')); if ($v !== '' && filter_var($v, FILTER_VALIDATE_IP)) return $v; }
    $xff = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
    if ($xff !== '') { $parts = array_filter(array_map('trim', explode(',', $xff))); if (!empty($parts)) { $last = end($parts); if (filter_var($last, FILTER_VALIDATE_IP)) return $last; } }
    $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'; return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '0.0.0.0';
}

function client_ip(): string { return request_ip(); }

function submission_rate_ok(mysqli $conn, int $limit = 500, int $windowMinutes = 60): bool {
    static $tableReady = false;
    try {
        if (!$tableReady) { $conn->query("CREATE TABLE IF NOT EXISTS submission_attempts (ip VARCHAR(45) PRIMARY KEY, `count` INT NOT NULL DEFAULT 0, window_start DATETIME NOT NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) DEFAULT CHARSET=utf8mb4"); $tableReady = true; }
        $ip = request_ip(); $now = date('Y-m-d H:i:s'); $cut = date('Y-m-d H:i:s', time() - ($windowMinutes * 60));
        $stmt = $conn->prepare("INSERT INTO submission_attempts (ip, `count`, window_start) VALUES (?, 1, ?) ON DUPLICATE KEY UPDATE `count` = IF(window_start < ?, 1, `count` + 1), window_start = IF(window_start < ?, VALUES(window_start), window_start)");
        $stmt->bind_param('ssss', $ip, $now, $cut, $cut); $stmt->execute(); $stmt->close();
        $stmt = $conn->prepare("SELECT `count` FROM submission_attempts WHERE ip = ?"); $stmt->bind_param('s', $ip); $stmt->execute(); $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
        return !$row || (int)$row['count'] <= $limit;
    } catch (Throwable $e) { error_log('submission_rate_ok failed: ' . $e->getMessage()); return true; }
}

function is_locked_out(mysqli $conn): ?int {
    $ip = client_ip(); $stmt = $conn->prepare("SELECT locked_until FROM login_attempts WHERE ip = ?"); $stmt->bind_param('s', $ip); $stmt->execute(); $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if ($row && $row['locked_until'] && strtotime($row['locked_until']) > time()) return strtotime($row['locked_until']) - time(); return null;
}

function register_failed_attempt(mysqli $conn): void {
    $ip = client_ip(); $now = date('Y-m-d H:i:s'); $maxAttempts = 5; $lockMinutes = 15;
    $stmt = $conn->prepare("UPDATE login_attempts SET attempts = 0, locked_until = NULL WHERE ip = ? AND locked_until IS NOT NULL AND locked_until <= ?"); $stmt->bind_param('ss', $ip, $now); $stmt->execute(); $stmt->close();
    $stmt = $conn->prepare("INSERT INTO login_attempts (ip, attempts) VALUES (?, 1) ON DUPLICATE KEY UPDATE attempts = attempts + 1"); $stmt->bind_param('s', $ip); $stmt->execute(); $stmt->close();
    $stmt = $conn->prepare("UPDATE login_attempts SET locked_until = DATE_ADD(?, INTERVAL ? MINUTE) WHERE ip = ? AND attempts >= ?"); $stmt->bind_param('sisi', $now, $lockMinutes, $ip, $maxAttempts); $stmt->execute(); $stmt->close();
}

function reset_login_attempts(mysqli $conn): void {
    $ip = client_ip(); $stmt = $conn->prepare("DELETE FROM login_attempts WHERE ip = ?"); $stmt->bind_param('s', $ip); $stmt->execute(); $stmt->close();
}

function csrf_token(): string { if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); return $_SESSION['csrf_token']; }
function verify_csrf(?string $submitted): bool { return !empty($_SESSION['csrf_token']) && !empty($submitted) && hash_equals($_SESSION['csrf_token'], $submitted); }
