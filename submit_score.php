<?php
require_once 'db.php';
require_once __DIR__ . '/AuthSchema.php'; // verify_csrf() lives here
require_once 'auth.php';                  // session, login check, submit cooldown
require_once __DIR__ . '/Raters.php';

$wantsJson = strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;

function respond(bool $ok, string $message, int $code, bool $wantsJson): void {
    if ($wantsJson) {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode(['status' => $ok ? 'success' : 'error', 'message' => $message]);
    } elseif ($ok) {
        header('Location: index.php');
    } else {
        http_response_code($code);
        echo htmlspecialchars($message);
    }
    exit;
}

// index.php is login-only, so this endpoint is too
if (!is_logged_in()) {
    respond(false, 'Please log in again.', 401, $wantsJson);
}

ensure_schema($conn);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Method not allowed.', 405, $wantsJson);
}

if (!verify_csrf($_POST['csrf_token'] ?? null)) {
    respond(false, 'Your session expired. Please refresh the page.', 403, $wantsJson);
}

// Honeypot: real users never fill this hidden field
if (!empty($_POST['website'])) {
    respond(true, 'Saved.', 200, $wantsJson);
}

// Cooldown: 5 seconds between submissions per browser session
if (isset($_SESSION['last_submit']) && (time() - $_SESSION['last_submit']) < 5) {
    respond(false, 'Please wait a few seconds before submitting again.', 429, $wantsJson);
}

$allowedOffices = [
    'PENRO Iloilo', 'PENRO Capiz', 'PENRO Aklan', 'PENRO Antique',
    'PENRO Guimaras', 'PENRO Negros Occidental', 'Regional Office'
];

$candidate_name = mb_substr(trim($_POST['candidate_name'] ?? ''), 0, 255);
$position_title = mb_substr(trim($_POST['position_title'] ?? ''), 0, 255);
$office_name    = trim($_POST['office_name'] ?? '');
$interview_date = mb_substr(trim($_POST['interview_date'] ?? ''), 0, 100);
$rater_name     = mb_substr(trim($_POST['rater_name'] ?? ''), 0, 255);

if ($position_title === '') $position_title = 'LMO I';
if ($interview_date === '') $interview_date = date('F d, Y');

if ($candidate_name === '') {
    respond(false, 'Candidate name is required.', 422, $wantsJson);
}
if (!in_array($office_name, $allowedOffices, true)) {
    respond(false, 'Please select a valid office.', 422, $wantsJson);
}

// --- Which raters were picked, in the order they were checked ---
// index.php sends raters_order="CHIEF,ADMIN" (built client-side from click order).
// Any checked raters[] values not in it are appended afterwards (DOM order),
// so the form still works even if JS failed to set the hidden field.
$validRaters    = rater_list();
$selectedRaters = [];

foreach (explode(',', (string)($_POST['raters_order'] ?? '')) as $r) {
    $r = trim($r);
    if (in_array($r, $validRaters, true) && !in_array($r, $selectedRaters, true)) {
        $selectedRaters[] = $r;
    }
}
foreach ((array)($_POST['raters'] ?? []) as $r) {
    $r = trim((string)$r);
    if (in_array($r, $validRaters, true) && !in_array($r, $selectedRaters, true)) {
        $selectedRaters[] = $r;
    }
}

if (empty($selectedRaters)) {
    respond(false, 'Please select at least one rater.', 422, $wantsJson);
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

$ratings  = [];
$evidence = [];
foreach ($groups as $codes) {
    foreach ($codes as $code) {
        $ratings[$code] = cleanRating($_POST[$code] ?? null);
        $ev = mb_substr(trim($_POST['evidence_' . $code] ?? ''), 0, 1000);
        if ($ev !== '') {
            $evidence[$code] = $ev;
        }
    }
}

function groupAvg(array $codes, array $ratings): ?float {
    $vals = [];
    foreach ($codes as $c) {
        if ($ratings[$c] !== null) $vals[] = $ratings[$c];
    }
    return count($vals) > 0 ? array_sum($vals) / count($vals) : null;
}

$core_avg = groupAvg($groups['core'], $ratings);
$org_avg  = groupAvg($groups['org'],  $ratings);
$lead_avg = groupAvg($groups['lead'], $ratings);
$func_avg = groupAvg($groups['func'], $ratings);

// One "psycho" score and one "potential" score for this submission — the same
// two numbers get recorded under every rater that was selected, since the form
// only collects one set of competency ratings per submission.
function sideAvg(?float $a, ?float $b): ?float {
    $vals = array_filter([$a, $b], fn($v) => $v !== null);
    return count($vals) > 0 ? array_sum($vals) / count($vals) : null;
}
$psycho_score    = sideAvg($core_avg, $org_avg);
$potential_score = sideAvg($lead_avg, $func_avg);

$evidenceJson = count($evidence) > 0 ? json_encode($evidence, JSON_UNESCAPED_UNICODE) : null;

// Build the INSERT from fixed column lists (no user input in column names)
$columns = ['candidate_name', 'position_title', 'office_location', 'office_name', 'interview_date', 'rater_name',
            'core_avg', 'org_avg', 'lead_avg', 'func_avg', 'evidence_json'];
$values  = [$candidate_name, $position_title, $office_name, $office_name, $interview_date, $rater_name,
            $core_avg, $org_avg, $lead_avg, $func_avg, $evidenceJson];
$types   = 'ssssss' . 'dddd' . 's';

foreach ($ratings as $code => $rating) {
    $columns[] = $code;
    $values[]  = $rating;
    $types    .= 'd';
}

$placeholders = implode(',', array_fill(0, count($columns), '?'));
$sql = "INSERT INTO candidates (" . implode(',', $columns) . ") VALUES ($placeholders)";

try {
    $conn->begin_transaction();

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$values);
    $stmt->execute();
    $candidateId = $conn->insert_id;
    $stmt->close();

    $stmtScore = $conn->prepare("INSERT INTO candidate_scores (candidate_id, rater, psycho, potential)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE psycho = VALUES(psycho), potential = VALUES(potential)");
    foreach ($selectedRaters as $rater) {
        $stmtScore->bind_param('isdd', $candidateId, $rater, $psycho_score, $potential_score);
        $stmtScore->execute();
    }
    $stmtScore->close();

    // Keep the column order already saved for this office + position; append only new raters
    $existing = position_rater_order($conn, $office_name, $position_title);
    $merged   = array_values(array_unique(array_merge($existing, $selectedRaters)));
    record_rater_order($conn, $office_name, $position_title, $merged);

    $conn->commit();
    $_SESSION['last_submit'] = time();
    respond(true, 'Saved.', 200, $wantsJson);
} catch (Exception $e) {
    $conn->rollback();
    error_log('submit_score error: ' . $e->getMessage());
    respond(false, DEBUG ? $e->getMessage() : 'Could not save. Please try again.', 500, $wantsJson);
}
?>