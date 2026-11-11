CREATE TABLE IF NOT EXISTS password_reset_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    status ENUM('pending', 'approved', 'rejected', 'used', 'expired') NOT NULL DEFAULT 'pending',
    token_hash CHAR(64) NULL,
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    approved_at DATETIME NULL,
    expires_at DATETIME NULL,
    completed_at DATETIME NULL,
    reviewed_by INT NULL,
    INDEX idx_reset_status_requested (status, requested_at),
    CONSTRAINT fk_password_reset_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
