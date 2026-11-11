<?php
// ============================================================
// submit_score.php — full replacement
// ============================================================

require_once 'db.php';
require_once __DIR__ . '/AuthSchema.php';
require_once 'auth.php';
require_once __DIR__ . '/Raters.php';
require_once __DIR__ . '/sheets_schema.php';
require_once __DIR__ . '/competency_template_schema.php';
require_once __DIR__ . '/AccessGroups.php';
require_once __DIR__ . '/OfficeTypeMap.php';

$wantsJson = strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;

function respond(bool $ok, string $message, int $code, bool $wantsJson, array $extra = []): void {
    if ($wantsJson) {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode(array_merge(['status' => $ok ? 'success' : 'error', 'message' => $message], $extra));
    } elseif ($ok) {
        header('Location: index.php');
    } else {
        http_response_code($code);
        echo htmlspecialchars($message);
    }
    exit;
}

if (!is_logged_in()) {
    respond(false, 'Please log in again.', 401, $wantsJson);
}

if (isset($conn) && $conn instanceof mysqli && function_exists('find_user_by_id')) {
    $freshUser = find_user_by_id($conn, (int)$_SESSION['user_id']);
    if ($freshUser === null) {
        $_SESSION = [];
        session_regenerate_id(true);
        respond(false, 'Your account is no longer available. Please log in again.', 401, $wantsJson);
    }
    $_SESSION['username'] = $freshUser['username'];
    $_SESSION['role']     = $freshUser['role'];
}

ensure_schema($conn);
ensure_sheets_schema($conn);
ensure_column($conn, 'candidates', 'custom_json', 'LONGTEXT NULL');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Method not allowed.', 405, $wantsJson);
}

if (!verify_csrf($_POST['csrf_token'] ?? null)) {
    respond(false, 'Your session expired. Please refresh the page.', 403, $wantsJson);
}

if (!empty($_POST[HONEYPOT_FIELD])) {
    respond(true, 'Saved.', 200, $wantsJson);
}

if (isset($_SESSION['last_submit']) && (time() - $_SESSION['last_submit']) < 5) {
    respond(false, 'Please wait a few seconds before submitting again.', 429, $wantsJson);
}

if (function_exists('submission_rate_ok') && !submission_rate_ok($conn)) {
    respond(false, 'Too many submissions in a short time. Please wait a bit and try again.', 429, $wantsJson);
}

$currentUserId = (int)current_user_id();
$validRaters = rater_list();
$postedRaters = $_POST['raters'] ?? [];
if (!is_array($postedRaters) || count($postedRaters) > 1) {
    respond(false, 'Choose exactly one valid rater position before saving.', 422, $wantsJson);
}
$postedOrder = trim((string)($_POST['raters_order'] ?? ''));
$orderedRaters = $postedOrder === '' ? [] : explode(',', $postedOrder);
if (count($orderedRaters) > 1) {
    respond(false, 'Choose exactly one valid rater position before saving.', 422, $wantsJson);
}

$selectedRaters = [];
$requestedRaters = array_merge($postedRaters, $orderedRaters);
foreach ($requestedRaters as $requestedRater) {
    $requestedRater = trim((string)$requestedRater);
    if ($requestedRater === '' || !in_array($requestedRater, $validRaters, true)) {
        respond(false, 'Choose exactly one valid rater position before saving.', 422, $wantsJson);
    }
    $selectedRaters[] = $requestedRater;
}
$selectedRaters = array_values(array_unique($selectedRaters));
if (count($selectedRaters) !== 1) {
    respond(false, 'Choose exactly one valid rater position before saving.', 422, $wantsJson);
}

$allowedOffices = [
    'PENRO Iloilo', 'PENRO Capiz', 'PENRO Aklan', 'PENRO Antique',
    'PENRO Guimaras', 'PENRO Negros Occidental', 'Regional Office'
];

$candidate_name = mb_substr(trim($_POST['candidate_name'] ?? ''), 0, 255);
$submittedPositionTitle = mb_substr(trim($_POST['position_title'] ?? ''), 0, 255);
$position_title = $submittedPositionTitle;
$position_title = canonical_position_title($position_title);
$office_name    = trim($_POST['office_name'] ?? '');
$interview_date = mb_substr(trim($_POST['interview_date'] ?? ''), 0, 100);
$rater_name     = mb_substr(trim($_POST['rater_name'] ?? ''), 0, 255);

if ($position_title === '') $position_title = 'LMO I';
if ($interview_date === '') $interview_date = date('F d, Y');

$office_location = mb_substr($office_name, 0, 100);

$salaryGrade = filter_var($_POST['salary_grade'] ?? null, FILTER_VALIDATE_INT);
$templateId = filter_var($_POST['competency_template_id'] ?? null, FILTER_VALIDATE_INT);
if (!access_salary_grade_is_valid($salaryGrade) || $templateId === false || $templateId < 1) {
    respond(false, 'A valid designated scoring form and salary grade are required.', 422, $wantsJson);
}
$templateStmt = $conn->prepare('SELECT title, layout_json, min_salary_grade, max_salary_grade, is_shared
    FROM competency_templates WHERE id = ? LIMIT 1');
$templateStmt->bind_param('i', $templateId);
$templateStmt->execute();
$selectedTemplate = $templateStmt->get_result()->fetch_assoc();
$templateStmt->close();
$expectedTemplateTitle = 'SG ' . (int)$salaryGrade . ' - ' . $submittedPositionTitle;
if (!$selectedTemplate
    || (string)$selectedTemplate['title'] !== $expectedTemplateTitle
    || (int)$salaryGrade < (int)$selectedTemplate['min_salary_grade']
    || (int)$salaryGrade > (int)$selectedTemplate['max_salary_grade']
    || ((int)$selectedTemplate['is_shared'] !== 1 && current_role() !== 'admin')) {
    respond(false, 'The selected scoring form is not available for this position and salary grade.', 422, $wantsJson);
}
$templateLayout = json_decode((string)$selectedTemplate['layout_json'], true);
if (!is_array($templateLayout) || !is_array($templateLayout['sections'] ?? null)) {
    respond(false, 'The selected scoring form has an invalid layout.', 422, $wantsJson);
}
$templateOfficeType = office_type_for_template_layout($templateLayout, (string)$selectedTemplate['title']);
if (current_role() === 'rater') {
    $profile = find_user_access_profile($conn, (int)current_user_id());
    $accountType = account_type_for_profile($profile);
    if (!in_array($templateOfficeType, office_types_for_account_type($accountType), true)) {
        respond(false, 'The selected scoring form is not available for your account type.', 403, $wantsJson);
    }
}
$positionLeadershipLevel = '';
if ($templateOfficeType === 'FO') {
    $sourceSheet = trim((string)($templateLayout['source_sheet'] ?? ''));
    $fieldPosition = field_position_for_sheet_title($sourceSheet, $submittedPositionTitle);
    if (!$fieldPosition
        || (int)$salaryGrade < (int)$fieldPosition['min_salary_grade']
        || (int)$salaryGrade > (int)$fieldPosition['max_salary_grade']) {
        respond(false, 'The selected Field position is not available in this sheet and salary grade.', 422, $wantsJson);
    }
} else {
    $positionScope = 'REGIONAL_OFFICE';
    $positionStmt = $conn->prepare('SELECT leadership_level FROM positions WHERE office_scope = ? AND title = ? AND salary_grade = ? LIMIT 1');
    $positionStmt->bind_param('ssi', $positionScope, $submittedPositionTitle, $salaryGrade);
    $positionStmt->execute();
    $selectedPosition = $positionStmt->get_result()->fetch_assoc();
    $positionStmt->close();
    if (!$selectedPosition) {
        respond(false, 'The selected position is not available at this salary grade.', 422, $wantsJson);
    }
    $positionLeadershipLevel = strtoupper(trim((string)($selectedPosition['leadership_level'] ?? '')));
}

if ($candidate_name === '') {
    respond(false, 'Candidate name is required.', 422, $wantsJson);
}
if (!in_array($office_name, $allowedOffices, true)) {
    respond(false, 'Please select a valid office.', 422, $wantsJson);
}

$sheet_id = (int)($_POST['sheet_id'] ?? 0);
if ($sheet_id > 0) {
    $uid = (int)current_user_id();
    $st = $conn->prepare("SELECT id FROM sheets WHERE id = ? AND user_id = ? LIMIT 1");
    $st->bind_param('ii', $sheet_id, $uid);
    $st->execute();
    $own = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$own) {
        respond(false, 'That sheet is not yours.', 403, $wantsJson);
    }
}

function cleanRating($v): ?float {
    if ($v === null || $v === '' || !is_numeric($v)) return null;
    return max(0.0, min(10.0, (float)$v));
}

$groups = [
    'core' => ['cc1', 'cc2', 'cc3', 'cc4', 'cc5'],
    'org'  => ['oc1', 'oc2', 'oc3', 'oc4', 'oc5'],
    'lead' => ['lc1', 'lc2', 'lc3', 'lc4', 'lc5'],
    'func' => ['pcp1', 'pcp2', 'pcp3', 'pco4'],
];

$layoutRowIds = [];
foreach (['core', 'org', 'lead', 'func'] as $sectionKey) {
    $layoutRowIds[$sectionKey] = [];
    if (empty($templateLayout['sections'][$sectionKey]['standard'])) continue;
    foreach (($templateLayout['sections'][$sectionKey]['rows'] ?? []) as $layoutRow) {
        if (is_array($layoutRow) && isset($layoutRow['id'])) {
            $layoutRowIds[$sectionKey][(string)$layoutRow['id']] = true;
        }
    }
}
$hasLeadership = (int)$salaryGrade > BASIC_MAX_SG
    && in_array($positionLeadershipLevel, ['B', 'I'], true)
    && isset($templateLayout['sections']['lead']);

$ratings  = [];
$evidence = [];
foreach ($groups as $sectionKey => $codes) {
    foreach ($codes as $code) {
        $allowed = isset($layoutRowIds[$sectionKey][$code]);
        $ratings[$code] = $allowed ? cleanRating($_POST[$code] ?? null) : null;
        if (!$allowed) continue;
        $ev = mb_substr(trim($_POST['evidence_' . $code] ?? ''), 0, 1000);
        if ($ev !== '') $evidence[$code] = $ev;
    }
}

if (!$hasLeadership) {
    foreach (['lc1','lc2','lc3','lc4','lc5'] as $lc) {
        $ratings[$lc] = null;
        unset($evidence[$lc]);
    }
}

$customRows = [];
$customRatingsBySec = ['core' => [], 'org' => [], 'lead' => [], 'func' => []];

foreach ($templateLayout['sections'] as $sectionKey => $section) {
    $sectionKey = (string)$sectionKey;
    if (!is_array($section) || empty($section['custom']) || !is_array($section['rows'] ?? null)) continue;
    if ($sectionKey === 'lead' && !$hasLeadership) continue;
    foreach ($section['rows'] as $row) {
        $rowId = (string)($row['id'] ?? '');
        if ($rowId === '') continue;
        $rating = cleanRating($_POST[$rowId] ?? null);
        $evidenceValue = mb_substr(trim((string)($_POST['evidence_' . $rowId] ?? '')), 0, 1000);
        $sectionLevel = (string)($section['level'] ?? '');
        if ($sectionKey === 'lead') {
            $sectionLevel = $positionLeadershipLevel === 'I' ? 'Advanced' : 'Basic';
        } elseif ((int)$salaryGrade <= BASIC_MAX_SG) {
            $sectionLevel = 'Basic';
        } elseif (!in_array($sectionLevel, ['Basic', 'Advanced'], true)) {
            $sectionLevel = template_level_for_salary_grade((int)$salaryGrade);
        }
        $customRows[] = [
            'section' => $sectionKey,
            'title' => mb_substr(trim((string)($row['title'] ?? '')), 0, 200),
            'level' => $sectionLevel,
            'rating' => $rating,
            'evidence' => $evidenceValue,
        ];
        if ($rating !== null && isset($customRatingsBySec[$sectionKey])) $customRatingsBySec[$sectionKey][] = $rating;
    }
}

$customJson = count($customRows) > 0 ? json_encode($customRows, JSON_UNESCAPED_UNICODE) : null;

function groupAvg(array $codes, array $ratings, array $extra = []): ?float {
    $vals = [];
    foreach ($codes as $c) {
        if (isset($ratings[$c]) && $ratings[$c] !== null) $vals[] = $ratings[$c];
    }
    foreach ($extra as $v) {
        if ($v !== null) $vals[] = (float)$v;
    }
    return count($vals) > 0 ? array_sum($vals) / count($vals) : null;
}

$core_avg = groupAvg($groups['core'], $ratings, $customRatingsBySec['core']);
$org_avg  = groupAvg($groups['org'],  $ratings, $customRatingsBySec['org']);
$lead_avg = $hasLeadership ? groupAvg($groups['lead'], $ratings, $customRatingsBySec['lead']) : null;
$func_avg = groupAvg($groups['func'], $ratings, $customRatingsBySec['func']);

$psycho_score    = cell_avg($ratings, core_cell_codes());
$potential_score = cell_avg($ratings, right_cell_codes($hasLeadership));

$evidenceJson = count($evidence) > 0 ? json_encode($evidence, JSON_UNESCAPED_UNICODE) : null;

$columns = ['candidate_name', 'position_title', 'office_location', 'office_name', 'interview_date', 'rater_name',
            'core_avg', 'org_avg', 'lead_avg', 'func_avg', 'evidence_json', 'psycho_ave', 'potential_ave',
            'custom_json'];
$values  = [$candidate_name, $position_title, $office_location, $office_name, $interview_date, $rater_name,
            $core_avg, $org_avg, $lead_avg, $func_avg, $evidenceJson, $psycho_score, $potential_score,
            $customJson];
$types   = 'ssssss' . 'dddd' . 's' . 'dd' . 's';

foreach ($ratings as $code => $rating) {
    $columns[] = $code;
    $values[]  = $rating;
    $types    .= 'd';
}

$placeholders = implode(',', array_fill(0, count($columns), '?'));

try {
    $conn->begin_transaction();

    $find = $conn->prepare("SELECT id FROM candidates
        WHERE candidate_name = ? AND UPPER(TRIM(office_name)) = UPPER(?) AND TRIM(position_title) = ?
        ORDER BY id DESC LIMIT 1 FOR UPDATE");
    $find->bind_param('sss', $candidate_name, $office_name, $position_title);
    $find->execute();
    $existingCandidate = $find->get_result()->fetch_assoc();
    $find->close();

    if ($existingCandidate) {
        $candidateId = (int)$existingCandidate['id'];
        $isEdit = true;

        $setParts = [];
        foreach ($columns as $col) {
            $setParts[] = "`$col` = ?";
        }
        $setSql = "UPDATE candidates SET " . implode(', ', $setParts) . " WHERE id = ?";
        $upd = $conn->prepare($setSql);
        $typesWithId = $types . 'i';
        $valuesWithId = $values;
        $valuesWithId[] = $candidateId;
        $upd->bind_param($typesWithId, ...$valuesWithId);
        $upd->execute();
        $upd->close();
    } else {
        $sql = "INSERT INTO candidates (" . implode(',', $columns) . ") VALUES ($placeholders)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$values);
        $stmt->execute();
        $candidateId = $conn->insert_id;
        $stmt->close();
        $isEdit = false;
    }

    $stmtScore = $conn->prepare("INSERT INTO candidate_scores (candidate_id, rater, psycho, potential, rated_by, rated_at)
        VALUES (?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE psycho = VALUES(psycho), potential = VALUES(potential),
            rated_by = VALUES(rated_by), rated_at = VALUES(rated_at)");
    $ratedBy = (int)current_user_id();
    foreach ($selectedRaters as $rater) {
        $stmtScore->bind_param('isddi', $candidateId, $rater, $psycho_score, $potential_score, $ratedBy);
        $stmtScore->execute();
    }
    $stmtScore->close();

    $existingRaters = position_rater_order($conn, $office_name, $position_title);
    $merged         = array_values(array_unique(array_merge($existingRaters, $selectedRaters)));
    record_rater_order($conn, $office_name, $position_title, $merged);

    $conn->commit();
    $_SESSION['last_submit'] = time();

    if ($sheet_id > 0) {
        try {
            $st = $conn->prepare("UPDATE sheets SET status = 'submitted' WHERE id = ? AND user_id = ?");
            $uid = (int)current_user_id();
            $st->bind_param('ii', $sheet_id, $uid);
            $st->execute();
            $st->close();
        } catch (Throwable $e) {
            error_log('submit_score mark submitted failed: ' . $e->getMessage());
        }
    }

    respond(true, 'Saved.', 200, $wantsJson, [
        'candidate_id' => $candidateId,
    ]);
} catch (Throwable $e) {
    try { $conn->rollback(); } catch (Throwable $ignored) {}
    error_log('submit_score error: ' . $e->getMessage());
    respond(false, DEBUG ? $e->getMessage() : 'Could not save. Please try again.', 500, $wantsJson);
}