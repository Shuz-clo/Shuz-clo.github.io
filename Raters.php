<?php
// ONE shared list of raters. Used by index.php (checkboxes), submit_score.php,
// save_score.php and results_2.php (columns). To add a rater later, add it here.
function rater_list(): array {
    return ['ADMIN', 'ENDUSER',  'END-USER', 'CHIEF', 'CHAIR', 'VICE-CHAIR', 'UNION', 'GAD', 'VICE'];
}

// Adds a column to a table only if it isn't already there. Safe to call even
// if the table itself doesn't exist yet (just does nothing in that case),
// which matters because db.php runs mysqli in strict exception mode.
function ensure_column(mysqli $conn, string $table, string $column, string $definitionSql): void {
    try {
        $exists = $conn->query("SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . $conn->real_escape_string($table) . "'
            AND COLUMN_NAME = '" . $conn->real_escape_string($column) . "' LIMIT 1");
        if ($exists && $exists->num_rows === 0) {
            $conn->query("ALTER TABLE `$table` ADD COLUMN `$column` $definitionSql");
        }
    } catch (Exception $e) {
        // Table doesn't exist (yet) — nothing to add a column to.
    }
}

// Creates what the scoring needs if it is not there yet (safe to run every time)
function ensure_schema(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS candidate_scores (
        candidate_id INT NOT NULL,
        rater VARCHAR(30) NOT NULL,
        psycho DECIMAL(5,2) NULL,
        potential DECIMAL(5,2) NULL,
        PRIMARY KEY (candidate_id, rater)
    ) DEFAULT CHARSET=utf8mb4");

    ensure_column($conn, 'candidates', 'remarks', 'TEXT NULL');

    // sheet_settings may already exist from earlier setup; create it if not,
    // then make sure the remarks-label column is there either way.
    $conn->query("CREATE TABLE IF NOT EXISTS sheet_settings (
        id TINYINT PRIMARY KEY,
        hdr_hash VARCHAR(50) DEFAULT '#',
        hdr_name VARCHAR(100) DEFAULT 'Name',
        hdr_psycho_group VARCHAR(100) DEFAULT 'PSYCHO-SOCIAL',
        hdr_potential_group VARCHAR(100) DEFAULT 'POTENTIAL',
        hdr_core VARCHAR(50) DEFAULT 'CORE',
        hdr_org VARCHAR(50) DEFAULT 'ORG',
        hdr_psycho_extra1 VARCHAR(50) DEFAULT '',
        hdr_psycho_extra2 VARCHAR(50) DEFAULT '',
        hdr_psycho_extra3 VARCHAR(50) DEFAULT '',
        hdr_psycho_extra4 VARCHAR(50) DEFAULT '',
        hdr_psycho_ave VARCHAR(50) DEFAULT 'AVE.',
        hdr_func VARCHAR(50) DEFAULT 'FUNC',
        hdr_lead VARCHAR(50) DEFAULT 'LEAD',
        hdr_potential_extra1 VARCHAR(50) DEFAULT '',
        hdr_potential_extra2 VARCHAR(50) DEFAULT '',
        hdr_potential_extra3 VARCHAR(50) DEFAULT '',
        hdr_potential_extra4 VARCHAR(50) DEFAULT '',
        hdr_potential_ave VARCHAR(50) DEFAULT 'AVE.',
        hdr_remarks VARCHAR(100) DEFAULT 'REMARKS'
    ) DEFAULT CHARSET=utf8mb4");
    $conn->query("INSERT IGNORE INTO sheet_settings (id) VALUES (1)");
    ensure_column($conn, 'sheet_settings', 'hdr_remarks', "VARCHAR(100) DEFAULT 'REMARKS'");

    // Remembers, per office + position, the left-to-right order rater columns
    // should appear in on the results page. A rater's sort_order is set once,
    // the first time it's ever selected for that office+position, and never
    // changes after that — new raters just get appended with a higher number.
    $conn->query("CREATE TABLE IF NOT EXISTS position_rater_order (
        office VARCHAR(100) NOT NULL,
        position VARCHAR(255) NOT NULL,
        rater VARCHAR(30) NOT NULL,
        sort_order INT NOT NULL,
        PRIMARY KEY (office, position, rater)
    ) DEFAULT CHARSET=utf8mb4");

    // Tabs (offices) an admin deleted on the results page. Without this, built-in tabs
    // such as PENRO ILOILO would be re-created on every page load.
    $conn->query("CREATE TABLE IF NOT EXISTS removed_tabs (
        office VARCHAR(100) NOT NULL PRIMARY KEY,
        removed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) DEFAULT CHARSET=utf8mb4");
}

// Call this with the raters in the order they should appear (e.g. the order
// they were checked on the index form, or the order currently shown on the
// results page). Already-recorded raters for this office+position keep their
// existing position; only genuinely new ones get appended at the end.
function record_rater_order(mysqli $conn, string $office, string $position, array $ratersInOrder): void {
    $office   = strtoupper(trim($office));
    $position = trim($position);
    if ($office === '' || $position === '' || empty($ratersInOrder)) return;

    $stmt = $conn->prepare("SELECT COALESCE(MAX(sort_order), 0) AS m FROM position_rater_order WHERE office = ? AND position = ?");
    $stmt->bind_param('ss', $office, $position);
    $stmt->execute();
    $max = (int)($stmt->get_result()->fetch_assoc()['m'] ?? 0);
    $stmt->close();

    $next = $max + 1;
    $ins = $conn->prepare("INSERT IGNORE INTO position_rater_order (office, position, rater, sort_order) VALUES (?, ?, ?, ?)");
    foreach ($ratersInOrder as $r) {
        $r = trim((string)$r);
        if ($r === '') continue;
        $ins->bind_param('sssi', $office, $position, $r, $next);
        $ins->execute();
        if ($conn->affected_rows > 0) $next++;
    }
    $ins->close();
}

// Returns the stored rater order for one office+position, e.g. ['CHIEF', 'ADMIN'].
function position_rater_order(mysqli $conn, string $office, string $position): array {
    $office   = strtoupper(trim($office));
    $position = trim($position);
    $out = [];

    $stmt = $conn->prepare("SELECT rater FROM position_rater_order WHERE office = ? AND position = ? ORDER BY sort_order ASC");
    $stmt->bind_param('ss', $office, $position);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $out[] = $row['rater'];
    }
    $stmt->close();
    return $out;
}

// Returns stored orders for every office+position pair the given rows cover,
// as ['OFFICE' => ['Position' => ['RATER1', 'RATER2', ...]]].
function all_position_rater_orders(mysqli $conn): array {
    $out = [];
    $res = $conn->query("SELECT office, position, rater FROM position_rater_order ORDER BY office, position, sort_order ASC");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $out[$row['office']][$row['position']][] = $row['rater'];
        }
    }
    return $out;
}

// Returns the (upper-case) names of tabs an admin has deleted.
function removed_tab_list(mysqli $conn): array {
    $out = [];
    $res = $conn->query("SELECT office FROM removed_tabs");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $out[] = strtoupper(trim($row['office']));
        }
    }
    return $out;
}