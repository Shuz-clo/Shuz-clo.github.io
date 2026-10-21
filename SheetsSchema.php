<?php

function ensure_sheets_schema(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS sheet_documents (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        owner_id INT NOT NULL,
        title VARCHAR(120) NOT NULL DEFAULT 'Untitled sheet',
        cells LONGTEXT NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_sheet_documents_owner_updated (owner_id, updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS competency_templates (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        owner_id INT NOT NULL,
        title VARCHAR(120) NOT NULL DEFAULT 'Untitled template',
        layout_json LONGTEXT NOT NULL,
        min_salary_grade TINYINT UNSIGNED NULL DEFAULT NULL,
        max_salary_grade TINYINT UNSIGNED NULL DEFAULT NULL,
        region VARCHAR(100) NULL DEFAULT NULL,
        field_name VARCHAR(100) NULL DEFAULT NULL,
        is_shared TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_competency_templates_owner_updated (owner_id, updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    foreach ([
        'min_salary_grade' => 'TINYINT UNSIGNED NULL DEFAULT NULL',
        'max_salary_grade' => 'TINYINT UNSIGNED NULL DEFAULT NULL',
        'region' => 'VARCHAR(100) NULL DEFAULT NULL',
        'field_name' => 'VARCHAR(100) NULL DEFAULT NULL',
        'is_shared' => 'TINYINT(1) NOT NULL DEFAULT 0',
    ] as $column => $definition) {
        $stmt = $conn->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
        $table = 'competency_templates';
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        if (!$exists) {
            $conn->query("ALTER TABLE competency_templates ADD COLUMN `$column` $definition");
        }
    }
}
