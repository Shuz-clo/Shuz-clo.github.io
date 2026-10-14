<?php
// Audit trail: who did what to which applicant (or account).
// Logging must never block or undo the action it records, so every failure here is swallowed (and written to the error log).

function ensure_audit_table(mysqli $conn): void {
    static $ready = false;
    if ($ready) return;
    $conn->query("CREATE TABLE IF NOT EXISTS score_audit (
        id INT AUTO_INCREMENT PRIMARY KEY,
        candidate_id INT NOT NULL,
        user_id INT NULL,
        username VARCHAR(100) NULL,
        action ENUM('submit','edit','delete','role_change','password_reset') NOT NULL,
        old_value TEXT NULL,
        new_value TEXT NULL,
        ip VARCHAR(45) NULL,
        user_agent VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_candidate (candidate_id),
        INDEX idx_user (user_id),
        INDEX idx_created (created_at)
    ) DEFAULT CHARSET=utf8mb4");
    $ready = true;
}

// $candidateId is 0 for actions that are about an account (role change, password reset) rather than an applicant.
// $oldValue / $newValue can be arrays (stored as JSON), strings or null. Never put passwords or hashes in them.
function audit_log(mysqli $conn, int $candidateId, string $action, $oldValue = null, $newValue = null): void {
    try {
        ensure_audit_table($conn);

        $encode = function ($v) {
            if ($v === null) return null;
            return is_string($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE);
        };
        $old = $encode($oldValue);
        $new = $encode($newValue);

        $userId   = function_exists('current_user_id') ? current_user_id() : null;
        $username = isset($_SESSION['username']) ? mb_substr((string)$_SESSION['username'], 0, 100) : null;
        $ip       = function_exists('request_ip') ? request_ip() : ($_SERVER['REMOTE_ADDR'] ?? null);
        $agent    = isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 255) : null;

        $stmt = $conn->prepare("INSERT INTO score_audit
            (candidate_id, user_id, username, action, old_value, new_value, ip, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('iissssss', $candidateId, $userId, $username, $action, $old, $new, $ip, $agent);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        error_log('audit_log failed: ' . $e->getMessage());
    }
}