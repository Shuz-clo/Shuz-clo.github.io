<?php

function ensure_drafts_schema(mysqli $conn): void {
    if (!app_allow_ddl()) return;

    $conn->query("CREATE TABLE IF NOT EXISTS form_drafts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        status ENUM('draft','saved') NOT NULL DEFAULT 'draft',
        template_id BIGINT UNSIGNED NULL,
        salary_grade TINYINT UNSIGNED NULL,
        position_title VARCHAR(255),
        office_name VARCHAR(255),
        candidate_name VARCHAR(255),
        form_json LONGTEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_user_status_updated (user_id, status, updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
