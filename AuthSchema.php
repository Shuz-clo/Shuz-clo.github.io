<?php
// Shared schema + helpers for the login system: real user accounts with roles,
// secure "remember me" tokens, and brute-force lockout.
// Require this (after db.php) in login.php, logout.php, and the account-setup tool.

function auth_ensure_column(mysqli $conn, string $table, string $column, string $definitionSql): void {
    try {
        $exists = $conn->query("SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . $conn->real_escape_string($table) . "'
            AND COLUMN_NAME = '" . $conn->real_escape_string($column) . "' LIMIT 1");
        if ($exists && $exists->num_rows === 0) {
            $conn->query("ALTER TABLE `$table` ADD COLUMN `$column` $definitionSql");
        }
    } catch (Exception $e) {
        // Table doesn't exist yet — nothing to add a column to.
    }
}

function ensure_auth_schema(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(100) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        role ENUM('admin','rater','user') NOT NULL DEFAULT 'user',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS remember_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        token_hash CHAR(64) NOT NULL UNIQUE,
        expires_at DATETIME NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) DEFAULT CHARSET=utf8mb4");
    // In case this table already existed from an earlier, admin-only version
    auth_ensure_column($conn, 'remember_tokens', 'user_id', 'INT NOT NULL DEFAULT 0');

    $conn->query("CREATE TABLE IF NOT EXISTS login_attempts (
        ip VARCHAR(45) PRIMARY KEY,
        attempts INT NOT NULL DEFAULT 0,
        locked_until DATETIME NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) DEFAULT CHARSET=utf8mb4");
}

// ---- User lookup ----

function find_user_by_username(mysqli $conn, string $username): ?array {
    $stmt = $conn->prepare("SELECT id, username, password_hash, role FROM users WHERE username = ? LIMIT 1");
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function find_user_by_id(mysqli $conn, int $id): ?array {
    $stmt = $conn->prepare("SELECT id, username, role FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

// ---- Remember-me tokens ----
// The cookie holds a random, unguessable token. Only its SHA-256 hash is
// stored in the database — a leaked database alone never reveals a usable
// cookie value. The token is tied to a user_id; the *role* is always re-read
// live from the users table on each auto-login, so a role change takes
// effect immediately even for an existing "remembered" device.

function issue_remember_token(mysqli $conn, int $userId, int $days = 30): string {
    $token   = bin2hex(random_bytes(32)); // 64 hex chars — this goes in the cookie
    $hash    = hash('sha256', $token);    // only this goes in the database
    $expires = date('Y-m-d H:i:s', time() + ($days * 86400));

    $stmt = $conn->prepare("INSERT INTO remember_tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)");
    $stmt->bind_param('iss', $userId, $hash, $expires);
    $stmt->execute();
    $stmt->close();

    return $token;
}

// Returns the user array (id, username, role) the token belongs to, or null
// if the token is missing, expired, or the user no longer exists.
function verify_remember_token(mysqli $conn, string $token): ?array {
    $hash = hash('sha256', $token);
    $stmt = $conn->prepare("SELECT u.id, u.username, u.role
        FROM remember_tokens t JOIN users u ON u.id = t.user_id
        WHERE t.token_hash = ? AND t.expires_at > NOW() LIMIT 1");
    $stmt->bind_param('s', $hash);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function delete_remember_token(mysqli $conn, ?string $token): void {
    if (!$token) return;
    $hash = hash('sha256', $token);
    $stmt = $conn->prepare("DELETE FROM remember_tokens WHERE token_hash = ?");
    $stmt->bind_param('s', $hash);
    $stmt->execute();
    $stmt->close();
}

function purge_expired_tokens(mysqli $conn): void {
    $conn->query("DELETE FROM remember_tokens WHERE expires_at <= NOW()");
}

// ---- Brute-force lockout ----
// After AUTH_MAX_ATTEMPTS failures from the same IP, that IP is locked out
// for AUTH_LOCKOUT_MINUTES. A successful login resets the counter.

const AUTH_MAX_ATTEMPTS    = 5;
const AUTH_LOCKOUT_MINUTES = 15;

function client_ip(): string {
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

// Returns seconds remaining if locked out, or null if not locked out.
function is_locked_out(mysqli $conn): ?int {
    $ip = client_ip();
    $stmt = $conn->prepare("SELECT locked_until FROM login_attempts WHERE ip = ?");
    $stmt->bind_param('s', $ip);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row && $row['locked_until'] && strtotime($row['locked_until']) > time()) {
        return strtotime($row['locked_until']) - time();
    }
    return null;
}

function register_failed_attempt(mysqli $conn): void {
    $ip = client_ip();

    $stmt = $conn->prepare("INSERT INTO login_attempts (ip, attempts, locked_until)
        VALUES (?, 1, NULL)
        ON DUPLICATE KEY UPDATE attempts = attempts + 1");
    $stmt->bind_param('s', $ip);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("SELECT attempts FROM login_attempts WHERE ip = ?");
    $stmt->bind_param('s', $ip);
    $stmt->execute();
    $attempts = (int)($stmt->get_result()->fetch_assoc()['attempts'] ?? 0);
    $stmt->close();

    if ($attempts >= AUTH_MAX_ATTEMPTS) {
        $lockedUntil = date('Y-m-d H:i:s', time() + (AUTH_LOCKOUT_MINUTES * 60));
        $stmt = $conn->prepare("UPDATE login_attempts SET locked_until = ? WHERE ip = ?");
        $stmt->bind_param('ss', $lockedUntil, $ip);
        $stmt->execute();
        $stmt->close();
    }
}

function reset_login_attempts(mysqli $conn): void {
    $ip = client_ip();
    $stmt = $conn->prepare("DELETE FROM login_attempts WHERE ip = ?");
    $stmt->bind_param('s', $ip);
    $stmt->execute();
    $stmt->close();
}

// ---- CSRF ----

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(?string $submitted): bool {
    return !empty($_SESSION['csrf_token']) && !empty($submitted)
        && hash_equals($_SESSION['csrf_token'], $submitted);
}