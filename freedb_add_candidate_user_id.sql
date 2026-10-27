-- Add candidate columns expected by manage_users.php and submit_score.php.
-- Safe to run repeatedly; existing candidate records are preserved.

SET @has_candidate_user_id = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'candidates'
      AND COLUMN_NAME = 'user_id'
);

SET @candidate_user_id_sql = IF(
    @has_candidate_user_id = 0,
    'ALTER TABLE `candidates` ADD COLUMN `user_id` INT NULL',
    'SELECT ''candidates.user_id already exists'' AS status'
);

PREPARE candidate_user_id_stmt FROM @candidate_user_id_sql;
EXECUTE candidate_user_id_stmt;
DEALLOCATE PREPARE candidate_user_id_stmt;

SET @has_candidate_custom_json = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'candidates'
      AND COLUMN_NAME = 'custom_json'
);

SET @candidate_custom_json_sql = IF(
    @has_candidate_custom_json = 0,
    'ALTER TABLE `candidates` ADD COLUMN `custom_json` LONGTEXT NULL',
    'SELECT ''candidates.custom_json already exists'' AS status'
);

PREPARE candidate_custom_json_stmt FROM @candidate_custom_json_sql;
EXECUTE candidate_custom_json_stmt;
DEALLOCATE PREPARE candidate_custom_json_stmt;
