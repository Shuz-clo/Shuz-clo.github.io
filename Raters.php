<?php
const BASIC_MAX_SG = 9;

function position_is_basic_level(int $salaryGrade): bool {
    return $salaryGrade <= BASIC_MAX_SG;
}

function basic_core_competency_descriptions(): array {
    return [
        'cc1' => 'Practices ethical and professional behaviors',
        'cc2' => "Complies with DENR's standards of service delivery",
        'cc3' => 'Demonstrates virtue, goodness, honor, justness and decency at all times',
        'cc4' => 'Observes the principle of transparency and accountability in the workplace with supervision and guidance',
        'cc5' => 'Has knowledge of concepts and application of preservation and conservation of the environment',
    ];
}

function basic_org_competency_descriptions(): array {
    return [
        'oc1' => 'Refers to and/or uses existing communication materials or templates to produce own written work',
        'oc2' => 'Effectively delivers messages that simply focus on data, facts or information and requires minimal preparation or can be supported by available communication materials. Focus of communication is to relay information and/or data',
        'oc3' => 'Applies basic technology (office software and equipment) in the performance of the assigned tasks. Collects, organizes and maintains data',
        'oc4' => 'Assist in project management activities',
        'oc5' => 'Practices basic knowledge of CSW to generate complete, accurate, relevant and timely output',
    ];
}

if (!function_exists('rater_list')) {
    function rater_list(): array {
        return ['ADMIN', 'END-USER', 'CHIEF PERSONNEL SECTION', 'CHAIR', 'VICE-CHAIR', 'UNION', 'GAD', 'ALTERNATE'];
    }
}

if (!function_exists('canonical_rater_name')) {
    function canonical_rater_name(string $r): string {
        $trimmed = trim($r);
        if ($trimmed === '') return '';
        $compact = preg_replace('/[\s\-_]+/', '', strtoupper($trimmed));
        foreach (rater_list() as $known) {
            if (preg_replace('/[\s\-_]+/', '', strtoupper($known)) === $compact) return $known;
        }
        return strtoupper($trimmed);
    }
}

function ensure_column(mysqli $conn, string $table, string $column, string $definitionSql): void {
    try {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table) || !preg_match('/^[a-zA-Z0-9_]+$/', $column)) return;
        $stmt = $conn->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $exists = $stmt->get_result();
        if ($exists && $exists->num_rows === 0) { $conn->query("ALTER TABLE `$table` ADD COLUMN `$column` $definitionSql"); }
        $stmt->close();
    } catch (mysqli_sql_exception $e) {
        if ((int)$e->getCode() === 1060) return;
        error_log("ensure_column {$table}.{$column}: " . $e->getMessage());
    } catch (Throwable $e) { error_log("ensure_column {$table}.{$column}: " . $e->getMessage()); }
}

function ensure_schema(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS `candidates` (
      `id` int(11) NOT NULL AUTO_INCREMENT, `candidate_name` varchar(255) DEFAULT NULL, `position_title` varchar(255) DEFAULT NULL,
      `office_location` varchar(100) DEFAULT NULL, `office_name` varchar(255) DEFAULT '', `interview_date` varchar(255) DEFAULT NULL,
      `salary_grade` tinyint(3) unsigned DEFAULT NULL,
      `user_id` int(11) DEFAULT NULL,
      `rater_name` varchar(255) DEFAULT NULL, `evidence_json` text DEFAULT NULL, `custom_competencies_json` longtext DEFAULT NULL,
      `cc1` decimal(4,2) DEFAULT NULL, `cc2` decimal(4,2) DEFAULT NULL, `cc3` decimal(4,2) DEFAULT NULL, `cc4` decimal(4,2) DEFAULT NULL, `cc5` decimal(4,2) DEFAULT NULL,
      `oc1` decimal(4,2) DEFAULT NULL, `oc2` decimal(4,2) DEFAULT NULL, `oc3` decimal(4,2) DEFAULT NULL, `oc4` decimal(4,2) DEFAULT NULL, `oc5` decimal(4,2) DEFAULT NULL,
      `lc1` decimal(4,2) DEFAULT NULL, `lc2` decimal(4,2) DEFAULT NULL, `lc3` decimal(4,2) DEFAULT NULL, `lc4` decimal(4,2) DEFAULT NULL, `lc5` decimal(4,2) DEFAULT NULL,
      `pcp1` decimal(4,2) DEFAULT NULL, `pcp2` decimal(4,2) DEFAULT NULL, `pcp3` decimal(4,2) DEFAULT NULL, `pco4` decimal(4,2) DEFAULT NULL,
      `core_avg` decimal(4,2) DEFAULT NULL, `org_avg` decimal(4,2) DEFAULT NULL, `lead_avg` decimal(4,2) DEFAULT NULL, `func_avg` decimal(4,2) DEFAULT NULL,
      `overall_avg` decimal(4,2) DEFAULT NULL, `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      `psycho_extra1` decimal(4,2) DEFAULT NULL, `psycho_extra2` decimal(4,2) DEFAULT NULL, `psycho_extra3` decimal(4,2) DEFAULT NULL, `psycho_extra4` decimal(4,2) DEFAULT NULL,
      `potential_extra1` decimal(4,2) DEFAULT NULL, `potential_extra2` decimal(4,2) DEFAULT NULL, `potential_extra3` decimal(4,2) DEFAULT NULL, `potential_extra4` decimal(4,2) DEFAULT NULL,
      `psycho_ave` decimal(4,2) DEFAULT NULL, `potential_ave` decimal(4,2) DEFAULT NULL, `remarks` text DEFAULT NULL, PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->query("CREATE TABLE IF NOT EXISTS candidate_scores (candidate_id INT NOT NULL, rater VARCHAR(30) NOT NULL, psycho DECIMAL(5,2) NULL, potential DECIMAL(5,2) NULL, rated_by INT NULL, rated_at DATETIME NULL, PRIMARY KEY (candidate_id, rater)) DEFAULT CHARSET=utf8mb4");
    ensure_column($conn, 'candidates', 'remarks', 'TEXT NULL');
    ensure_column($conn, 'candidates', 'custom_competencies_json', 'LONGTEXT NULL');
    ensure_column($conn, 'candidates', 'psycho_ave', 'DECIMAL(4,2) NULL');
    ensure_column($conn, 'candidates', 'potential_ave', 'DECIMAL(4,2) NULL');
    ensure_column($conn, 'candidates', 'salary_grade', 'TINYINT UNSIGNED NULL');
    ensure_column($conn, 'candidates', 'user_id', 'INT NULL');
    ensure_column($conn, 'candidate_scores', 'rated_by', 'INT NULL');
    ensure_column($conn, 'candidate_scores', 'rated_at', 'DATETIME NULL');
    $conn->query("CREATE TABLE IF NOT EXISTS sheet_settings (id TINYINT PRIMARY KEY, hdr_hash VARCHAR(50) DEFAULT '#', hdr_name VARCHAR(100) DEFAULT 'Name', hdr_psycho_group VARCHAR(100) DEFAULT 'PSYCHO-SOCIAL', hdr_potential_group VARCHAR(100) DEFAULT 'POTENTIAL', hdr_core VARCHAR(50) DEFAULT 'CORE', hdr_org VARCHAR(50) DEFAULT 'ORG', hdr_psycho_extra1 VARCHAR(50) DEFAULT '', hdr_psycho_extra2 VARCHAR(50) DEFAULT '', hdr_psycho_extra3 VARCHAR(50) DEFAULT '', hdr_psycho_extra4 VARCHAR(50) DEFAULT '', hdr_psycho_ave VARCHAR(50) DEFAULT 'AVE.', hdr_func VARCHAR(50) DEFAULT 'FUNC', hdr_lead VARCHAR(50) DEFAULT 'LEAD', hdr_potential_extra1 VARCHAR(50) DEFAULT '', hdr_potential_extra2 VARCHAR(50) DEFAULT '', hdr_potential_extra3 VARCHAR(50) DEFAULT '', hdr_potential_extra4 VARCHAR(50) DEFAULT '', hdr_potential_ave VARCHAR(50) DEFAULT 'AVE.', hdr_remarks VARCHAR(100) DEFAULT 'REMARKS') DEFAULT CHARSET=utf8mb4");
    $conn->query("INSERT IGNORE INTO sheet_settings (id) VALUES (1)");
    ensure_column($conn, 'sheet_settings', 'hdr_remarks', "VARCHAR(100) DEFAULT 'REMARKS'");
    $conn->query("CREATE TABLE IF NOT EXISTS position_rater_order (office VARCHAR(100) NOT NULL, position VARCHAR(255) NOT NULL, rater VARCHAR(30) NOT NULL, sort_order INT NOT NULL, PRIMARY KEY (office, position, rater)) DEFAULT CHARSET=utf8mb4");
    $conn->query("CREATE TABLE IF NOT EXISTS removed_tabs (office VARCHAR(100) NOT NULL PRIMARY KEY, removed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) DEFAULT CHARSET=utf8mb4");
}

function leadership_level_titles(): array {
    return [ 'Planning Officer V' => 'I', 'Chief Administrative Officer' => 'I', 'Attorney V' => 'I', 'Development Management Officer V' => 'I', 'Engineer V (Geodetic Engineer)' => 'I', 'Chief Ecosystems Management Specialist' => 'I', 'Provincial Environment & Natural Resources Officer' => 'I', 'Community Environment & Natural Resources Officer' => 'I', 'Community Environment & Natural Resources Officer (CENRO)' => 'I', 'Senior Communications Development Officer' => 'B', 'Planning Officer IV' => 'B', 'Planning Officer III' => 'B', 'Project Evaluation Officer III' => 'B', 'Information Systems Analyst III' => 'B', 'Supervising Administrative Officer' => 'B', 'Administrative Officer V' => 'B', 'Administrative Officer V (Budget Officer III)' => 'B', 'Administrative Officer V (HRMO III)' => 'B', 'Administrative Officer V (Cashier III)' => 'B', 'Accountant III' => 'B', 'Attorney IV' => 'B', 'Attorney III' => 'B', 'Attorney II' => 'B', 'Development Management Officer IV' => 'B', 'Development Management Officer III' => 'B', 'Engineer IV (Geodetic Engineer)' => 'B', 'Engineer III (Geodetic Engineer)' => 'B', 'Engineer II (Geodetic Engineer)' => 'B', 'Forester III' => 'B', 'Supervising Ecosystems Management Specialist' => 'B', 'Senior Ecosystems Management Specialist' => 'B', 'Land Management Officer III' => 'B', ];
}

function leadership_level_aliases(): array {
    return [ ['ATY','V','I'], ['ATY','IV','B'], ['ATY','III','B'], ['ATY','II','B'], ['ATTY','V','I'], ['ATTY','IV','B'], ['ATTY','III','B'], ['ATTY','II','B'], ['DMO','V','I'], ['DMO','IV','B'], ['DMO','III','B'], ['ENG','V','I'], ['ENG','IV','B'], ['ENG','III','B'], ['ENG','II','B'], ['PLO','V','I'], ['PLO','IV','B'], ['PLO','III','B'], ['PEO','III','B'], ['LAMO','III','B'], ['FORST','III','B'], ['INFOSA','III','B'], ['ISA','III','B'], ['A','III','B'], ['ADOF','V','B'], ['AO','V','B'], ['SADOF','','B'], ['SAO','','B'], ['SVEMS','','B'], ['SREMS','','B'], ['SRCDO','','B'], ['CECMS','','I'], ['CADOF','','I'], ['CAO','','I'], ['CENRO','','I'], ['PENRO','','I'], ];
}

function leadership_hidden_positions(): array { return ['SVEMS', 'SUPERVISING ECOSYSTEMS MANAGEMENT SPECIALIST']; }
function offices_without_leadership(): array { return []; }

function normalize_position(string $s): string { $s = str_replace('&', ' AND ', $s); $s = preg_replace('/\s+/u', ' ', $s); return mb_strtoupper(trim((string)$s)); }
function compact_position(string $s): string { return (string)preg_replace('/[^\p{L}\p{N}]+/u', '', normalize_position($s)); }
function compact_position_no_parens(string $s): string { return compact_position((string)preg_replace('/\([^)]*\)/u', ' ', $s)); }

function leadership_level_map(): array {
    static $map = null;
    if ($map !== null) return $map;
    $map = [];
    foreach (leadership_level_titles() as $title => $lvl) { $map[compact_position($title)] = $lvl; }
    foreach (leadership_level_titles() as $title => $lvl) { $base = compact_position_no_parens($title); if (!isset($map[$base])) $map[$base] = $lvl; }
    $r2d = ['II' => '2', 'III' => '3', 'IV' => '4', 'V' => '5']; $d2r = ['2' => 'II', '3' => 'III', '4' => 'IV', '5' => 'V'];
    foreach (leadership_level_aliases() as $alias) { [$abbr, $numeral, $lvl] = $alias; $keys = [$abbr . $numeral]; if ($numeral !== '' && isset($r2d[$numeral])) $keys[] = $abbr . $r2d[$numeral]; if ($numeral !== '' && isset($d2r[$numeral])) $keys[] = $abbr . $d2r[$numeral]; foreach ($keys as $k) { if (!isset($map[$k])) $map[$k] = $lvl; } }
    return $map;
}

function leadership_level_for_position(string $position): ?string {
    $map = leadership_level_map(); $full = compact_position($position); if ($full === '') return null;
    if (isset($map[$full])) return $map[$full]; $base = compact_position_no_parens($position); return ($base !== '' && isset($map[$base])) ? $map[$base] : null;
}

function canonical_position_title(string $position): string {
    static $cache = null;
    if ($cache === null) {
        $cache = ['titles' => [], 'titlesNoParens' => [], 'aliasToTitle' => []];
        foreach (leadership_level_titles() as $title => $lvl) { $cache['titles'][compact_position($title)] = $title; $cp = compact_position_no_parens($title); if (!isset($cache['titlesNoParens'][$cp])) $cache['titlesNoParens'][$cp] = $title; }
        $r2d = ['II' => '2', 'III' => '3', 'IV' => '4', 'V' => '5'];
        $stems = ['ATTY' => 'Attorney', 'ATY' => 'Attorney', 'DMO' => 'Development Management Officer', 'ENG' => 'Engineer', 'PLO' => 'Planning Officer', 'PEO' => 'Project Evaluation Officer', 'LAMO' => 'Land Management Officer', 'FORST' => 'Forester', 'INFOSA' => 'Information Systems Analyst', 'ISA' => 'Information Systems Analyst', 'A' => 'Accountant', 'ADOF' => 'Administrative Officer', 'AO' => 'Administrative Officer', 'SADOF' => 'Supervising Administrative Officer', 'SAO' => 'Supervising Administrative Officer', 'SVEMS' => 'Supervising Ecosystems Management Specialist', 'SREMS' => 'Senior Ecosystems Management Specialist', 'SRCDO' => 'Senior Communications Development Officer', 'CECMS' => 'Chief Ecosystems Management Specialist', 'CADOF' => 'Chief Administrative Officer', 'CAO' => 'Chief Administrative Officer', 'CENRO' => 'Community Environment & Natural Resources Officer', 'PENRO' => 'Provincial Environment & Natural Resources Officer', ];
        foreach (leadership_level_aliases() as [$abbr, $numeral, $lvl]) {
            if (!isset($stems[$abbr])) continue;
            $want = compact_position($stems[$abbr] . ' ' . $numeral); $target = null;
            foreach (leadership_level_titles() as $title => $_l) { if (compact_position_no_parens($title) === $want) { $target = $title; break; } }
            if ($target === null) continue;
            $keys = [$abbr . $numeral]; if ($numeral !== '' && isset($r2d[$numeral])) $keys[] = $abbr . $r2d[$numeral];
            foreach ($keys as $k) { if (!isset($cache['aliasToTitle'][$k])) $cache['aliasToTitle'][$k] = $target; }
        }
    }
    $raw = trim($position); if ($raw === '') return '';
    $full = compact_position($raw); if (isset($cache['titles'][$full])) return $cache['titles'][$full];
    $base = compact_position_no_parens($raw); if (isset($cache['titlesNoParens'][$base])) return $cache['titlesNoParens'][$base];
    if (isset($cache['aliasToTitle'][$full])) return $cache['aliasToTitle'][$full]; if (isset($cache['aliasToTitle'][$base])) return $cache['aliasToTitle'][$base];
    return $raw;
}

function office_has_leadership(string $office, string $position = ''): bool {
    if (in_array(strtoupper(trim($office)), offices_without_leadership(), true)) return false;
    $canonical = canonical_position_title($position);
    if (in_array(strtoupper(trim($position)), leadership_hidden_positions(), true)) return false;
    if (in_array(strtoupper(trim($canonical)), leadership_hidden_positions(), true)) return false;
    return leadership_level_for_position($canonical) !== null;
}

function core_cell_codes(): array { return ['cc1', 'cc2', 'cc3', 'cc4', 'cc5']; }
function right_cell_codes(bool $withLeadership = true): array { $codes = ['oc1', 'oc2', 'oc3', 'oc4', 'oc5']; if ($withLeadership) $codes = array_merge($codes, ['lc1', 'lc2', 'lc3', 'lc4', 'lc5']); return array_merge($codes, ['pcp1', 'pcp2', 'pcp3', 'pco4']); }
function cell_avg(array $cells, array $codes): ?float { $vals = []; foreach ($codes as $c) { if (isset($cells[$c]) && $cells[$c] !== null && $cells[$c] !== '') $vals[] = (float)$cells[$c]; } return count($vals) > 0 ? array_sum($vals) / count($vals) : null; }

function record_rater_order(mysqli $conn, string $office, string $position, array $ratersInOrder): void {
    $office = strtoupper(trim($office)); $position = canonical_position_title(trim($position));
    if ($office === '' || $position === '' || empty($ratersInOrder)) return;
    $clean = [];
    foreach ($ratersInOrder as $r) { $c = canonical_rater_name((string)$r); if ($c !== '' && !in_array($c, $clean, true)) $clean[] = $c; }
    if (empty($clean)) return;
    $del = $conn->prepare("DELETE FROM position_rater_order WHERE office = ? AND position = ?"); $del->bind_param('ss', $office, $position); $del->execute(); $del->close();
    $ins = $conn->prepare("INSERT INTO position_rater_order (office, position, rater, sort_order) VALUES (?, ?, ?, ?)");
    foreach ($clean as $index => $rater) { $ins->bind_param('sssi', $office, $position, $rater, $index); $ins->execute(); }
    $ins->close();
}

function position_rater_order(mysqli $conn, string $office, string $position): array {
    $office = strtoupper(trim($office)); $position = canonical_position_title(trim($position)); $out = [];
    $stmt = $conn->prepare("SELECT rater FROM position_rater_order WHERE office = ? AND position = ? ORDER BY sort_order ASC"); $stmt->bind_param('ss', $office, $position); $stmt->execute();
    $res = $stmt->get_result(); while ($row = $res->fetch_assoc()) $out[] = $row['rater']; $stmt->close();
    return $out;
}

function all_position_rater_orders(mysqli $conn): array {
    $out = []; $res = $conn->query("SELECT office, position, rater FROM position_rater_order ORDER BY office, position, sort_order ASC");
    if ($res) { while ($row = $res->fetch_assoc()) { $out[$row['office']][$row['position']][] = $row['rater']; } }
    return $out;
}

function removed_tab_list(mysqli $conn): array {
    $out = []; $res = $conn->query("SELECT office FROM removed_tabs");
    if ($res) { while ($row = $res->fetch_assoc()) $out[] = strtoupper(trim($row['office'])); }
    return $out;
}
