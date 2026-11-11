<?php
// ============================================================
// index.php — COMPLETE FIXED FILE
// ============================================================

// FORCE ERRORS TO SHOW
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once 'db.php';
require_once __DIR__ . '/AuthSchema.php';
require_once 'auth.php';
require_once __DIR__ . '/Raters.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/PositionsSchema.php';
require_once __DIR__ . '/AccessGroups.php';

ensure_auth_schema($conn);
ensure_positions_schema($conn);
purge_expired_tokens($conn);

require_login();
$scoringProfile = find_user_access_profile($conn, (int)current_user_id());
$selectedPositionTitle = trim((string)($_GET['position_title'] ?? ''));
if (mb_strlen($selectedPositionTitle) > 255) {
    $selectedPositionTitle = '';
}
$positionLookupTitle = $selectedPositionTitle;
if (preg_match('/^(.*?) \[RO: [^\]]+\]$/', $selectedPositionTitle, $roPositionMatch)) {
    $positionLookupTitle = trim($roPositionMatch[1]);
}

function show_position_access_error(string $title, string $message, ?int $salaryGrade = null): void {
    http_response_code(403);
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    $gradeMessage = $salaryGrade === null
        ? ''
        : '<p class="grade">Your assigned salary grade <strong>SG ' . $salaryGrade . '</strong> can only open matching positions.</p>';
    echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>' . $safeTitle . '</title>
    <link rel="stylesheet" href="ui-enhancements.css">
    <style>
        * { box-sizing: border-box; }
        body { min-height: 100vh; margin: 0; display: grid; place-items: center; padding: 24px; background: #f3f6f4; color: #202124; font-family: Arial, Helvetica, sans-serif; }
        main { width: min(100%, 520px); padding: 36px; text-align: center; background: #fff; border: 1px solid #e0e7e2; border-radius: 16px; box-shadow: 0 12px 36px rgba(22, 54, 36, .10); }
        .icon { width: 58px; height: 58px; margin: 0 auto 20px; display: grid; place-items: center; border-radius: 50%; background: #fff3e8; color: #a64b12; font-size: 30px; font-weight: 700; }
        h1 { margin: 0 0 12px; font-size: 24px; }
        p { margin: 0; color: #59635d; line-height: 1.6; }
        .grade { margin-top: 14px; padding: 12px; border-radius: 8px; background: #f4f8f5; color: #35443a; }
        .actions { margin-top: 24px; }
        a { display: inline-block; padding: 12px 18px; border-radius: 8px; background: #107c41; color: #fff; font-weight: 700; text-decoration: none; transition: background-color .15s ease, transform .15s ease; }
        a:hover { background: #0b6634; transform: translateY(-1px); }
        a:focus-visible { outline: 3px solid rgba(16, 124, 65, .3); outline-offset: 3px; }
        @media (max-width: 480px) { main { padding: 28px 22px; } h1 { font-size: 21px; } }
    </style>
</head>
<body>
    <main>
        <div class="icon" aria-hidden="true">!</div>
        <h1>' . $safeTitle . '</h1>
        <p>' . $safeMessage . '</p>
        ' . $gradeMessage . '
        <div class="actions"><a href="sheet_picker.php">Back to position list</a></div>
    </main>
</body>
</html>';
    exit;
}

$initialCandidateGrade = $scoringProfile['salary_grade'] ?? null;
if (!is_int($initialCandidateGrade) || $initialCandidateGrade < 0 || $initialCandidateGrade > 26) {
    $initialCandidateGrade = null;
}
if (isset($_GET['salary_grade'])) {
    $requestedGrade = filter_var($_GET['salary_grade'], FILTER_VALIDATE_INT);
    if ($requestedGrade !== false && $requestedGrade >= 0 && $requestedGrade <= 26) {
        $initialCandidateGrade = $requestedGrade;
    }
}

if (current_role() !== 'admin') {
    require_role(['rater']);
}

$initialTemplateId = 0;
if (current_role() === 'rater') {
    $profileGrade = $scoringProfile['salary_grade'] ?? null;
    $requestedGrade = filter_var($_GET['salary_grade'] ?? null, FILTER_VALIDATE_INT);
    $templateId = filter_var($_GET['template_id'] ?? null, FILTER_VALIDATE_INT);
    $hasPositionParameters = isset($_GET['template_id']) || isset($_GET['salary_grade']) || isset($_GET['position_title']);
    if (!access_salary_grade_is_valid($profileGrade)
        || !array_key_exists((string)($scoringProfile['region'] ?? ''), ACCESS_REGIONS)
        || !access_field_is_valid($scoringProfile['field_name'] ?? null)
        || ($hasPositionParameters && (
            $requestedGrade === false
            || $requestedGrade !== (int)$profileGrade
            || $selectedPositionTitle === ''
        ))) {
        show_position_access_error(
            'Position unavailable',
            'This link is missing valid position details or does not match the salary grade assigned to your account.',
            access_salary_grade_is_valid($profileGrade) ? (int)$profileGrade : null
        );
    }

    $scope = 'REGIONAL_OFFICE';
    if ($hasPositionParameters) {
        $positionStmt = $conn->prepare('SELECT 1 FROM positions WHERE office_scope = ? AND title = ? AND salary_grade = ? LIMIT 1');
        $positionStmt->bind_param('ssi', $scope, $positionLookupTitle, $requestedGrade);
        $positionStmt->execute();
        $positionIsAllowed = $positionStmt->get_result()->num_rows > 0;
        $positionStmt->close();
        if (!$positionIsAllowed) {
            show_position_access_error(
                'Position unavailable',
                'You can only open positions at the salary grade assigned to your account.',
                (int)$profileGrade
            );
        }
    }

    require_once __DIR__ . '/competency_template_schema.php';
    ensure_competency_template_schema($conn);
    if ($hasPositionParameters && $templateId !== false && $templateId > 0) {
        $templateTitle = 'SG ' . $requestedGrade . ' - ' . $selectedPositionTitle;
        $region = (string)$scoringProfile['region'];
        $fieldName = (string)$scoringProfile['field_name'];
        $templateStmt = $conn->prepare('SELECT 1 FROM competency_templates
            WHERE id = ? AND (title = ? OR title LIKE CONCAT(?, \' [RO:%\')) AND is_shared = 1
                AND min_salary_grade <= ? AND max_salary_grade >= ?
                AND (region IS NULL OR region = ?)
                AND (field_name IS NULL OR field_name = ?)
            LIMIT 1');
        $templateStmt->bind_param('issiiss', $templateId, $templateTitle, $templateTitle, $requestedGrade, $requestedGrade, $region, $fieldName);
        $templateStmt->execute();
        $templateIsAllowed = $templateStmt->get_result()->num_rows > 0;
        $templateStmt->close();
        if ($templateIsAllowed) $initialTemplateId = $templateId;
    }
    $initialCandidateGrade = (int)$profileGrade;
}

$canSubmit = in_array(current_role(), ['admin', 'rater'], true);
$availableRaterRoles = rater_list();
$basicLeadershipRows = leadership_rows_for_level('B');
$advancedLeadershipRows = leadership_rows_for_level('I');
$leadershipPositionLevels = array_map(
    static fn(string $title, string $level): array => [$title, $level],
    array_keys(leadership_level_titles()),
    array_values(leadership_level_titles())
);
$leadershipAliases = leadership_level_aliases();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="chrome" content="nofill">
    <title>DENR Interview Scoring Sheet</title>
    <style>
        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 13px;
            color: #000;
            background-color: #f8f9fa;
            margin: 20px;
        }

        .user-bar {
            max-width: 960px;
            margin: 0 auto 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 13px;
            color: #555;
        }
        .user-bar a {
            background: #dc3545;
            color: #fff;
            text-decoration: none;
            padding: 6px 14px;
            border-radius: 4px;
            font-weight: bold;
        }
        .user-bar a:hover { background: #b02a37; }
        @media print { .user-bar { display: none !important; } }

        .rate-warn {
            max-width: 960px;
            margin: 0 auto 10px;
            background: #fff6e0;
            border: 1px solid #f0dca0;
            color: #7a5a00;
            padding: 8px 12px;
            border-radius: 4px;
            font-size: 13px;
        }

        form {
            max-width: 960px;
            margin: 0 auto;
            background: #fff;
            padding: 20px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }

        .template-controls { max-width: 960px; margin: 0 auto 12px; padding: 12px 16px; background: #fff; border: 1px solid #d5dce2; }
        .template-controls label { display: inline-flex; align-items: center; gap: 8px; margin-right: 18px; }
        .template-controls input, .template-controls select { width: auto; min-width: 220px; padding: 6px 8px; border: 1px solid #aeb8c2; background: #fff; }
        #noScoringFormMessage { max-width: 960px; margin: 0 auto 12px; padding: 12px 16px; border: 1px solid #e1c47a; background: #fff6e0; color: #684f0c; }

        table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        col.col-desc { width: 58%; }
        col.col-ev   { width: 27%; }
        col.col-rate { width: 15%; }

        td, th {
            border: 1px solid #000;
            padding: 6px 8px;
            vertical-align: middle;
            word-wrap: break-word;
        }

        .header-cell { border: none !important; text-align: center; font-size: 13px; padding: 2px 0; }
        .header-title { border: none !important; text-align: center; font-weight: bold; font-size: 14px; padding: 10px 0; }
        .info-cell { border: none !important; padding: 4px 0; font-size: 13px; }

        input[type="text"], input[type="number"], select {
            width: 100%; padding: 4px; box-sizing: border-box;
            border: none; outline: none; background: transparent;
            font-family: inherit; font-size: inherit; color: #000;
        }

        input[type="number"] { text-align: center; font-weight: bold; }
        #lcHeadingText, #lcLevelLabel { text-align: center; font-weight: bold; }
        .sec-header input:enabled { cursor: text; }
        .sec-header input:enabled:focus { outline: 1px dashed #107c41; outline-offset: 2px; }

        .sec-header { background-color: #d9d9d9 !important; font-weight: bold !important; text-align: center !important; vertical-align: middle !important; }
        .comp-title { font-weight: bold; background-color: #fff; }
        .tpl-hidden { display: none !important; }
        .transparent { border: none !important; padding-top: 15px; }
        .secret-trigger { user-select: none; cursor: default; }

        .position-wrap {
            position: relative;
            display: inline-block;
            width: 70%;
            vertical-align: middle;
        }
        .position-wrap input#position_input {
            width: 100%;
            border-bottom: 1px solid #000;
            padding: 4px 2px;
        }
        #positionDropdown {
            display: none;
            position: absolute;
            top: 100%; left: 0; right: 0;
            z-index: 30;
            max-height: 260px;
            overflow-y: auto;
            background: #fff;
            border: 1px solid #999;
            box-shadow: 0 2px 8px rgba(0,0,0,0.18);
        }
        #positionDropdown.open { display: block; }
        #positionDropdown .pd-item {
            padding: 5px 8px; font-size: 12px; cursor: pointer;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        #positionDropdown .pd-item:hover,
        #positionDropdown .pd-item.active { background: #107c41; color: #fff; }
        #positionDropdown .pd-empty { padding: 6px 8px; font-size: 12px; color: #777; font-style: italic; }
        .rater-dropdown { position: relative; display: inline-block; width: min(100%, 360px); vertical-align: middle; }
        #raterSummary { cursor: pointer; border-bottom: 1px solid #000; padding: 4px; list-style: none; }
        #raterSummary::-webkit-details-marker { display: none; }
        .rater-options {
            position: absolute;
            left: 0;
            top: 100%;
            z-index: 20;
            min-width: 100%;
            max-height: 260px;
            overflow-y: auto;
            padding: 8px 12px;
            border: 1px solid #999;
            background: #fff;
            box-shadow: 0 2px 6px rgba(0,0,0,0.2);
        }
        .rater-options label { display: block; margin-bottom: 6px; white-space: nowrap; }
        .rater-position-note { display: block; margin-top: 3px; color: #555; }
        @media print { #positionDropdown { display: none !important; } }

        @media print {
            .btn-container, #positionBackBtn, .rate-warn, .template-selection-row { display: none !important; }
            body #templateControls { display: none !important; visibility: hidden !important; height: 0 !important; }
            #templateLoadStatus { display: none !important; }
            #raterDropdown > div { display: none !important; }
            #raterSummary { border-bottom: none !important; padding: 0 !important; }
            select { -webkit-appearance: none; -moz-appearance: none; appearance: none; background: none; border: none; }
            input[type="text"]::placeholder,
            input[type="number"]::placeholder { color: transparent; }
        }

        .btn-container { margin-top: 20px; text-align: center; }
        .pdf-status { margin: 12px auto 0; max-width: 700px; color: #7a1f16; }
        .pdf-status[hidden] { display: none !important; }
        .pdf-status button { min-height: 48px; margin: 6px; }
        .pdf-status-actions { display: flex; justify-content: center; flex-wrap: wrap; }

        .pdf-modal-backdrop {
            position: fixed;
            inset: 0;
            z-index: 1000;
            display: grid;
            place-items: center;
            padding: 16px;
            background: rgba(0, 0, 0, .48);
        }
        .pdf-modal-backdrop[hidden] { display: none !important; }
        .pdf-modal-backdrop { z-index: 1000; }
        .confirm-modal-backdrop { z-index: 1010; }
        .pdf-modal {
            width: min(100%, 440px);
            padding: 24px;
            border-radius: 12px;
            background: #fff;
            box-shadow: 0 16px 48px rgba(0, 0, 0, .25);
            color: #202124;
        }
        .pdf-modal h2 { margin: 0 0 12px; font-size: 22px; }
        .pdf-modal p { margin: 0; line-height: 1.5; }
        .pdf-modal-actions { display: flex; flex-direction: column; gap: 10px; margin-top: 22px; }
        .pdf-modal-actions button { min-height: 48px; margin: 0; border-radius: 7px; }
        .pdf-modal-primary { border: 0; background: #107c41; color: #fff; }
        .pdf-modal-primary:hover:not(:disabled) { background: #0b6634; }
        .pdf-modal-secondary { border: 1px solid #d5ddd7; background: #fff; color: #344139; }
        @media (max-width: 520px) {
            .pdf-modal-backdrop { padding: 0; place-items: end stretch; }
            .pdf-modal { width: 100%; padding: 24px 20px max(24px, env(safe-area-inset-bottom)); border-radius: 16px 16px 0 0; }
            .pdf-modal-actions button { width: 100%; }
        }
        @media print {
            .no-print, .pdf-modal-backdrop, .pdf-status { display: none !important; }
        }

        .pdf-render-root {
            position: fixed !important;
            top: 0 !important;
            left: -10000px !important;
            z-index: -1 !important;
            width: 1100px !important;
            max-width: none !important;
            pointer-events: none !important;
        }
        .pdf-render-root form {
            width: 1100px !important;
            max-width: none !important;
            height: auto !important;
            min-height: 0 !important;
            max-height: none !important;
            overflow: visible !important;
            margin: 0 !important;
            box-sizing: border-box !important;
            box-shadow: none !important;
        }
        .pdf-render-root table,
        .pdf-render-root tbody,
        .pdf-render-root tr {
            height: auto !important;
            max-height: none !important;
            overflow: visible !important;
        }
        .pdf-field {
            min-height: 1em;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
            word-break: break-word;
            vertical-align: middle;
        }
        .pdf-field-multiline {
            display: block !important;
            min-height: 1em;
            height: auto !important;
            max-height: none !important;
            overflow: visible !important;
        }

        .form-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin: -4px 0 16px;
            padding-bottom: 12px;
            border-bottom: 1px solid #e2e7e3;
        }
        .form-toolbar-group { display: flex; align-items: center; gap: 8px; }
        .form-toolbar a, .form-toolbar button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 36px;
            margin: 0;
            padding: 8px 13px;
            border: 1px solid #d5ddd7;
            border-radius: 7px;
            background: #fff;
            color: #344139;
            font: inherit;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
            transition: background-color .15s ease, border-color .15s ease, color .15s ease;
        }
        .form-toolbar a:hover, .form-toolbar button:hover:not(:disabled) {
            border-color: #107c41;
            background: #f3faf5;
            color: #0c6635;
        }
        .form-toolbar button:disabled { cursor: not-allowed; opacity: .48; }
        .form-toolbar .reset-button { color: #8d3a32; }
        .form-toolbar .reset-button:hover { border-color: #c8736b; background: #fff7f6; color: #8d3a32; }
        #draftSaveStatus { color: #52635a; font-size: 12px; }
        #draftUnavailableNotice { margin: 0 auto 12px; max-width: 960px; padding: 14px; border: 1px solid #e4c981; border-radius: 6px; background: #fff8e6; color: #654d0c; }
        #draftUnavailableNotice button { min-height: 48px; margin: 10px 0 0; }
        .resume-modal-backdrop { position: fixed; inset: 0; z-index: 1100; display: grid; place-items: center; padding: 16px; background: rgba(0,0,0,.48); }
        .resume-modal-backdrop[hidden] { display: none !important; }
        .resume-modal { width: min(100%, 440px); padding: 24px; border-radius: 12px; background: #fff; box-shadow: 0 16px 48px rgba(0,0,0,.25); color: #202124; }
        .resume-modal h2 { margin: 0 0 12px; font-size: 22px; }
        .resume-modal p { margin: 0; line-height: 1.5; }
        .resume-modal-actions { display: flex; flex-direction: column; gap: 10px; margin-top: 20px; }
        .resume-modal-actions button { min-height: 48px; margin: 0; }
        @media (max-width: 520px) { .resume-modal-backdrop { padding: 0; place-items: end stretch; } .resume-modal { width: 100%; padding: 24px 20px max(24px, env(safe-area-inset-bottom)); border-radius: 16px 16px 0 0; } }
        @media (max-width: 520px) {
            .form-toolbar { align-items: stretch; flex-direction: column; }
            .form-toolbar-group { justify-content: space-between; }
            .form-toolbar-group > * { flex: 1; }
        }
        @media print { .form-toolbar { display: none !important; } }

        button {
            padding: 8px 18px;
            font-size: 14px;
            font-weight: bold;
            cursor: pointer;
            margin: 0 5px;
        }
        tr.is-hidden { display: none; }
    </style>
    <link rel="stylesheet" href="ui-enhancements.css?v=<?php echo filemtime(__DIR__ . '/ui-enhancements.css'); ?>">
</head>
<body>

<?php if (is_logged_in()): ?>
<div class="user-bar">
    <span>Signed in as <strong><?php echo htmlspecialchars($_SESSION['username'] ?? ''); ?></strong> (<?php echo htmlspecialchars((string)current_role()); ?>)</span>
    <a href="logout.php">Log out</a>
</div>
<?php endif; ?>

<?php if (!$canSubmit): ?>
<div class="rate-warn">
    Your account is a Standard User account (view only). Ask an administrator for a Rater account to submit scores.
</div>
<?php endif; ?>

<?php if ($flashError = flash_get('error')): ?>
<div class="user-bar" style="background:#fdecea;border:1px solid #f5c2c0;color:#7a1f16;padding:8px 12px;border-radius:4px;">
    <?php echo htmlspecialchars($flashError); ?>
</div>
<?php endif; ?>

<div id="draftUnavailableNotice" role="status" aria-live="polite" hidden>
    <strong>This form is no longer available.</strong>
    <div>The shared template was removed or is no longer available for your position and account.</div>
    <button type="button" id="discardUnavailableDraftBtn">Discard draft</button>
</div>

<form id="scoringForm" action="submit_score.php" method="POST" autocomplete="off">
<input type="text" name="<?php echo HONEYPOT_FIELD; ?>" tabindex="-1" autocomplete="new-password" aria-hidden="true" style="position:absolute; left:-9999px; width:1px; height:1px;">
<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
<input type="hidden" name="custom_competencies_json" id="customCompetenciesJson" value="[]">
<input type="hidden" name="competency_template_id" id="competencyTemplateId" value="<?php echo (int)$initialTemplateId; ?>">
<div id="draftSaveStatus" role="status" aria-live="polite" style="margin:0 0 8px;color:#52635a;font-size:12px;"></div>
<?php if (current_role() === 'rater'): ?>
<input type="hidden" name="salary_grade" id="salaryGrade" value="<?php echo (int)$initialCandidateGrade; ?>">
<div class="form-toolbar">
    <a id="positionBackBtn" href="sheet_picker.php">← Back to position picker</a>
    <div class="form-toolbar-group">
        <button type="button" id="undoButton" onclick="undoLastChange()" disabled>Undo</button>
        <button type="reset" class="reset-button">Reset form</button>
    </div>
</div>
<?php endif; ?>

<div id="noScoringFormMessage" role="status" aria-live="polite" hidden>No scoring form is available for this position. Ask an administrator to generate it.</div>
<div id="templateControls" class="template-controls">
    <?php if (current_role() === 'admin'): ?>
    <label for="salaryGrade"><span data-sheet-label="candidate_salary_grade">Candidate salary grade:</span>
        <input type="number" name="salary_grade" id="salaryGrade" required min="0" max="26" step="1" inputmode="numeric"
               value="<?php echo $initialCandidateGrade === null ? '' : $initialCandidateGrade; ?>" placeholder="Enter salary grade (0-26)">
    </label>
    <?php endif; ?>
    <?php if (current_role() === 'admin'): ?>
    <label for="competencyTemplate"><span data-sheet-label="competency_template">Competency template:</span>
        <select id="competencyTemplate">
            <option value="">-- Select a designated form --</option>
        </select>
    </label>
    <?php else: ?>
    <select id="competencyTemplate" hidden aria-hidden="true" tabindex="-1"></select>
    <?php endif; ?>
    <span id="templateLoadStatus" role="status" aria-live="polite"></span>
</div>

<table id="scoringTable" hidden>
    <colgroup>
        <col class="col-desc">
        <col class="col-ev">
        <col class="col-rate">
    </colgroup>

    <tr><td colspan="3" class="header-cell" data-sheet-label="department">Department of Environment and Natural Resources</td></tr>
    <tr><td colspan="3" class="header-cell" data-sheet-label="region_line">Region VI, Western Visayas</td></tr>
    <tr><td colspan="3" class="header-cell" data-sheet-label="board">Regional Human Resource Merit Promotion and Selection Board (RHRMPSB)</td></tr>

    <tr><td colspan="3" class="header-title secret-trigger" data-sheet-label="sheet_title" ondblclick="goToAdminResults(event)">INTERVIEW SCORING SHEET</td></tr>

    <tr>
        <td class="info-cell"><span data-sheet-label="candidate_name">NAME:</span> <input type="text" name="candidate_name" placeholder="" required autocomplete="off" style="width: 75%; display: inline-block;"></td>
        <td colspan="2" class="info-cell"><span data-sheet-label="position">Position:</span>
            <span class="position-wrap">
                <input type="text" name="position_title" id="position_input"
                       value="<?php echo htmlspecialchars($selectedPositionTitle, ENT_QUOTES, 'UTF-8'); ?>"
                       placeholder="Type or pick a position title" required
                       <?php echo current_role() === 'rater' && $selectedPositionTitle !== '' ? 'readonly' : ''; ?>
                       autocomplete="off" spellcheck="false"
                       maxlength="255">
                <div id="positionDropdown" role="listbox"></div>
            </span>
        </td>
    </tr>
    <tr>
        <td class="info-cell"></td>
        <td colspan="2" class="info-cell"><span data-sheet-label="office">Office:</span>
            <select name="office_name" id="office_name" required style="width: 70%; display: inline-block;" onchange="applyFormLayout()">
                <option value="">-- Select Office / Branch --</option>
                <option value="PENRO Iloilo">PENRO Iloilo</option>
                <option value="PENRO Capiz">PENRO Capiz</option>
                <option value="PENRO Aklan">PENRO Aklan</option>
                <option value="PENRO Antique">PENRO Antique</option>
                <option value="PENRO Guimaras">PENRO Guimaras</option>
                <option value="PENRO Negros Occidental">PENRO Negros Occidental</option>
                <option value="Regional Office" <?php echo current_role() === 'rater' && $selectedPositionTitle !== '' ? 'selected' : ''; ?>>Regional Office</option>
            </select>
        </td>
    </tr>
    <tr>
        <td class="info-cell"></td>
        <td colspan="2" class="info-cell" style="padding-bottom: 15px;"><span data-sheet-label="interview_date">Date:</span> <input type="text" name="interview_date" value="<?php echo date('F d, Y'); ?>" autocomplete="off" style="width: 70%; display: inline-block;"></td>
    </tr>
    <tr class="sec-core" id="sectionHeader_core">
        <th class="sec-header" id="coreHeadingText">Core Competencies</th>
        <th class="sec-header" id="coreLevelLabel"></th>
        <th class="sec-header" id="coreRatingLabel">Rating</th>
    </tr>

    <tr class="sec-core"><td class="comp-title">CC1 - DISCIPLINE</td><td></td><td></td></tr>
    <tr class="sec-core">
        <td>Serves as a good role model on DENR values and ethics to staff/peers</td>
        <td><input type="text" name="evidence_cc1" autocomplete="off"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="cc1" placeholder="0.00" autocomplete="off"></td>
    </tr>

    <tr class="sec-core"><td class="comp-title">CC2 - EXCELLENCE</td><td></td><td></td></tr>
    <tr class="sec-core">
        <td>Anticipates, identifies and manages stakeholders’ standards and requirements towards excellent customer service through improving sense of responsibility and competence</td>
        <td><input type="text" name="evidence_cc2" autocomplete="off"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="cc2" placeholder="0.00" autocomplete="off"></td>
    </tr>

    <tr class="sec-core"><td class="comp-title">CC3 - NOBILITY</td><td></td><td></td></tr>
    <tr class="sec-core">
        <td>Influences others to observe virtue, goodness, honor, justness and decency in all situations</td>
        <td><input type="text" name="evidence_cc3" autocomplete="off"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="cc3" placeholder="0.00" autocomplete="off"></td>
    </tr>

    <tr class="sec-core"><td class="comp-title">CC4 - RESPONSIBILITY</td><td></td><td></td></tr>
    <tr class="sec-core">
        <td>Leads in the observance of the principle of transparency and accountability in the workplace</td>
        <td><input type="text" name="evidence_cc4" autocomplete="off"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="cc4" placeholder="0.00" autocomplete="off"></td>
    </tr>

    <tr class="sec-core"><td class="comp-title">CC5 - CARING FOR THE ENVIRONMENT AND NATURAL RESOURCES</td><td></td><td></td></tr>
    <tr class="sec-core">
        <td>Serves as a good role model in conserving and preserving the environment to peers and staff</td>
        <td><input type="text" name="evidence_cc5" autocomplete="off"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="cc5" placeholder="0.00" autocomplete="off"></td>
    </tr>

    <tr class="sec-right" id="sectionHeader_org">
        <th class="sec-header" id="orgHeadingText">Organizational Competencies</th>
        <th class="sec-header" id="orgLevelLabel"></th>
        <th class="sec-header" id="orgRatingLabel"></th>
    </tr>

    <tr class="sec-right"><td class="comp-title">OC1 - WRITING EFFECTIVELY</td><td></td><td></td></tr>
    <tr class="sec-right">
        <td>Produces written work from scratch with some guidance while complying to agreed or prescribed standards of communicating with the bureaucracy</td>
        <td><input type="text" name="evidence_oc1" autocomplete="off"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="oc1" placeholder="0.00" autocomplete="off"></td>
    </tr>

    <tr class="sec-right"><td class="comp-title">OC2 - SPEAKING EFFECTIVELY</td><td></td><td></td></tr>
    <tr class="sec-right">
        <td>Effectively delivers messages that require careful planning for the method used and the possible impact of the message (audience may be a large group, i.e., office, organization) Focus of communication is to relay information and to build motivation</td>
        <td><input type="text" name="evidence_oc2" autocomplete="off"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="oc2" placeholder="0.00" autocomplete="off"></td>
    </tr>

    <tr class="sec-right"><td class="comp-title">OC3 - TECHNOLOGY LITERACY AND MANAGING INFORMATION</td><td></td><td></td></tr>
    <tr class="sec-right">
        <td>Analyzes appropriateness of office software and equipment in the performance of the assigned tasks. Develops information assets to achieve organizational goals</td>
        <td><input type="text" name="evidence_oc3" autocomplete="off"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="oc3" placeholder="0.00" autocomplete="off"></td>
    </tr>

    <tr class="sec-right"><td class="comp-title">OC4 - PROJECT MANAGEMENT</td><td></td><td></td></tr>
    <tr class="sec-right">
        <td>Leads in project management activities</td>
        <td><input type="text" name="evidence_oc4" autocomplete="off"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="oc4" placeholder="0.00" autocomplete="off"></td>
    </tr>

    <tr class="sec-right"><td class="comp-title">OC5 - COMPLETE STAFF WORK (CSW)</td><td></td><td></td></tr>
    <tr class="sec-right">
        <td>Leads the practice of CSW in his/her office/unit</td>
        <td><input type="text" name="evidence_oc5" autocomplete="off"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="oc5" placeholder="0.00" autocomplete="off"></td>
    </tr>

    <tr class="leadership-row sec-right" id="sectionHeader_lead">
        <th class="sec-header"><input type="text" id="lcHeadingText" name="lc_heading" value="Leadership Competencies" aria-label="Leadership section heading" maxlength="255" disabled><span id="lcHint" style="display:none; font-size:11px; font-weight:normal; opacity:0.85;"></span></th>
        <th class="sec-header"><input type="text" id="lcLevelLabel" name="lc_level" value="" aria-label="Leadership level label" maxlength="100" disabled></th>
        <th class="sec-header"></th>
    </tr>

    <?php foreach ($basicLeadershipRows as $index => $row): $rowNumber = $index + 1; ?>
    <tr class="leadership-row sec-right"><td class="comp-title"><?php echo htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8'); ?></td><td></td><td></td></tr>
    <tr class="leadership-row sec-right">
        <td id="lcDesc<?php echo $rowNumber; ?>"><?php echo htmlspecialchars($row['desc'], ENT_QUOTES, 'UTF-8'); ?></td>
        <td><input type="text" name="evidence_lc<?php echo $rowNumber; ?>" autocomplete="off"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="lc<?php echo $rowNumber; ?>" placeholder="0.00" autocomplete="off"></td>
    </tr>
    <?php endforeach; ?>

    <tr class="sec-right" id="sectionHeader_func">
        <th class="sec-header" id="funcHeadingText">Functional Competencies</th>
        <th class="sec-header" id="funcLevelLabel"></th>
        <th class="sec-header" id="funcRatingLabel"></th>
    </tr>

    <tr class="sec-right"><td class="comp-title">PCP1 – PLANNING AND PROGRAMMING</td><td></td><td></td></tr>
    <tr class="sec-right">
        <td>Organizes the preparation of the PENRO operational plans</td>
        <td><input type="text" name="evidence_pcp1" autocomplete="off"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="pcp1" placeholder="0.00" autocomplete="off"></td>
    </tr>

    <tr class="sec-right"><td class="comp-title">PCP2 – MONITORING AND EVALUATION</td><td></td><td></td></tr>
    <tr class="sec-right">
        <td>Leads the preparation of monitoring and evaluation reports of all DENR-PENRO programs and projects</td>
        <td><input type="text" name="evidence_pcp2" autocomplete="off"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="pcp2" placeholder="0.00" autocomplete="off"></td>
    </tr>

    <tr class="sec-right"><td class="comp-title">PCP3 – STATISTICAL COORDINATION AND DATA RESEARCH</td><td></td><td></td></tr>
    <tr class="sec-right">
        <td>Leads the conduct of statistical coordination and data research activities</td>
        <td><input type="text" name="evidence_pcp3" autocomplete="off"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="pcp3" placeholder="0.00" autocomplete="off"></td>
    </tr>

    <tr class="sec-right"><td class="comp-title">PCO4 – RESOURCE MANAGEMENT AND RESTORATION/ REHABILITATION OF DEGRADED ECOSYSTEMS</td><td></td><td></td></tr>
    <tr class="sec-right">
        <td>Leads the characterization of ecosystem and use of planning tools and procedures</td>
        <td><input type="text" name="evidence_pco4" autocomplete="off"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="pco4" placeholder="0.00" autocomplete="off"></td>
    </tr>

    <tbody id="customSections"></tbody>

    <tr>
        <td class="transparent"><span data-sheet-label="rated_by">Rated by:</span> <input type="text" name="rater_name" placeholder="Rater Name" autocomplete="off" style="width: 70%; display: inline-block;"></td>
        <td class="transparent" colspan="2"><span data-sheet-label="raters">Rater position:</span>
            <input type="hidden" name="raters_order" id="ratersOrder" value="">
            <div id="raterPositionControl">
                <details id="raterDropdown" class="rater-dropdown">
                    <summary id="raterSummary" data-empty="1">-- Select Rater Position --</summary>
                    <div class="rater-options">
                        <?php foreach ($availableRaterRoles as $raterLabel): ?>
                            <label>
                                <input type="radio" class="rater-choice" name="raters[]" value="<?php echo htmlspecialchars($raterLabel, ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo htmlspecialchars($raterLabel, ENT_QUOTES, 'UTF-8'); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </details>
            </div>
        </td>
    </tr>
</table>

<div class="btn-container">
    <?php if ($canSubmit): ?>
        <button type="button" id="saveBtn" onclick="saveOnly()" disabled>Save</button>
    <?php endif; ?>
        <button type="button" id="savePdfBtn" class="no-print">Save as PDF</button>
        <button type="button" id="printBtn" onclick="window.print()">Print</button>
        <button type="button" id="newSheetBtn" onclick="newSheet()" style="display: none;">New Sheet</button>
        <div id="pdfStatus" class="pdf-status no-print" role="status" aria-live="polite" hidden>
            <span class="pdf-status-message"></span>
            <div class="pdf-status-actions">
                <button type="button" data-pdf-action="retry">Retry</button>
                <button type="button" data-pdf-action="share" hidden>Share / Open PDF</button>
                <button type="button" data-pdf-action="print">Print</button>
            </div>
        </div>
</div>
</form>

<div id="resumeDraftModal" class="resume-modal-backdrop no-print" hidden>
    <section class="resume-modal" role="dialog" aria-modal="true" aria-labelledby="resumeDraftTitle" aria-describedby="resumeDraftDescription">
        <h2 id="resumeDraftTitle">Continue your unfinished form?</h2>
        <p id="resumeDraftDescription"></p>
        <div class="resume-modal-actions">
            <button type="button" id="continueDraftBtn" class="pdf-modal-primary">Continue editing</button>
            <button type="button" id="discardDraftBtn" class="pdf-modal-secondary">Discard</button>
            <button type="button" id="notNowDraftBtn" class="pdf-modal-secondary">Not now</button>
        </div>
    </section>
</div>

<div id="savedPdfModal" class="pdf-modal-backdrop no-print" hidden>
        <section class="pdf-modal" role="dialog" aria-modal="true" aria-labelledby="savedPdfTitle" aria-describedby="savedPdfDescription">
            <h2 id="savedPdfTitle">Saved</h2>
            <p id="savedPdfDescription">Your scores were saved. Save a PDF copy of this form now, in case you need it later?</p>
            <div id="modalPdfStatus" class="pdf-status" role="status" aria-live="polite" hidden>
                <span class="pdf-status-message"></span>
                <div class="pdf-status-actions">
                    <button type="button" data-pdf-action="retry">Retry</button>
                    <button type="button" data-pdf-action="share" hidden>Share / Open PDF</button>
                    <button type="button" data-pdf-action="print">Print</button>
                </div>
            </div>
            <div class="pdf-modal-actions">
                <button type="button" id="modalSavePdfBtn" class="pdf-modal-primary">Save as PDF</button>
                <button type="button" id="modalClosePdfBtn" class="pdf-modal-secondary">Not now</button>
            </div>
        </section>
</div>

<div id="savedPdfConfirmModal" class="pdf-modal-backdrop confirm-modal-backdrop no-print" hidden>
    <section class="pdf-modal" role="dialog" aria-modal="true" aria-labelledby="savedPdfConfirmTitle" aria-describedby="savedPdfConfirmDescription">
        <h2 id="savedPdfConfirmTitle">Are you sure?</h2>
        <p id="savedPdfConfirmDescription">If you skip, you won't get a PDF copy of this form unless you print it yourself later.</p>
        <div class="pdf-modal-actions">
            <button type="button" id="pdfConfirmSaveBtn" class="pdf-modal-primary">No, save PDF</button>
            <button type="button" id="pdfConfirmSkipBtn" class="pdf-modal-secondary">Yes, skip</button>
        </div>
    </section>
</div>

<script>
    const CAN_SUBMIT = <?php echo $canSubmit ? 'true' : 'false'; ?>;
    const IS_ADMIN = <?php echo current_role() === 'admin' ? 'true' : 'false'; ?>;
    const CSRF_TOKEN = <?php echo json_encode(csrf_token()); ?>;
    const OPENED_WITHOUT_POSITION = <?php echo !isset($_GET['template_id']) && !isset($_GET['position_title']) && !isset($_GET['salary_grade']) ? 'true' : 'false'; ?>;
    const BASIC_MAX_SG = <?php echo BASIC_MAX_SG; ?>;
    const templateControls = document.getElementById('templateControls');
    window.addEventListener('beforeprint', () => { templateControls.hidden = true; });
    window.addEventListener('afterprint', () => { templateControls.hidden = false; });

    const savedPdfModal = document.getElementById('savedPdfModal');
    const savedPdfConfirmModal = document.getElementById('savedPdfConfirmModal');
    const savePdfButtons = [document.getElementById('savePdfBtn'), document.getElementById('modalSavePdfBtn')];
    const pdfStatuses = [document.getElementById('pdfStatus'), document.getElementById('modalPdfStatus')];
    let pdfBusy = false;
    let scoringSaveBusy = false;
    let pdfReminderSkipped = false;
    let pdfFile = null;
    let pdfFileName = '';
    let pdfBlob = null;
    let pdfBlobUrl = null;
    let pdfBlobUrlTimer = null;
    let pdfDataTimer = null;
    let html2canvasPromise = null;
    let jsPdfPromise = null;
    let pdfStatusMode = '';
    let pdfStatusText = '';

    function setPdfStatus(message, mode = '') {
        pdfStatusText = message;
        pdfStatusMode = mode;
        pdfStatuses.forEach(status => {
            if (!status) return;
            status.hidden = !message;
            status.querySelector('.pdf-status-message').textContent = message;
            status.querySelectorAll('[data-pdf-action]').forEach(button => {
                const action = button.dataset.pdfAction;
                button.hidden = (action === 'share' && mode !== 'share')
                    || (action === 'retry' && mode !== 'error')
                    || (action === 'print' && mode !== 'error');
            });
        });
    }

    function setPdfBusy(busy) {
        pdfBusy = busy;
        savePdfButtons.forEach(button => {
            if (!button) return;
            button.disabled = busy;
            button.textContent = busy ? 'Preparing PDF...' : 'Save as PDF';
        });
        if (busy) setPdfStatus('', '');
    }

    function showSavedPdfModal() {
        if (!CAN_SUBMIT || !savedPdfModal || pdfReminderSkipped) return;
        savedPdfConfirmModal.hidden = true;
        savedPdfModal.hidden = false;
        requestAnimationFrame(() => document.getElementById('modalSavePdfBtn').focus());
    }

    function dismissSavedPdfModal() {
        if (savedPdfModal.hidden) return;
        savedPdfModal.hidden = true;
        if (pdfReminderSkipped) return;
        savedPdfConfirmModal.hidden = false;
        requestAnimationFrame(() => document.getElementById('pdfConfirmSaveBtn').focus());
    }

    function skipSavedPdfReminder() {
        pdfReminderSkipped = true;
        savedPdfConfirmModal.hidden = true;
        savedPdfModal.hidden = true;
    }

    document.getElementById('modalClosePdfBtn').addEventListener('click', dismissSavedPdfModal);
    savedPdfModal.addEventListener('click', event => {
        if (event.target === savedPdfModal) dismissSavedPdfModal();
    });
    document.getElementById('pdfConfirmSaveBtn').addEventListener('click', () => {
        savedPdfConfirmModal.hidden = true;
        savedPdfModal.hidden = false;
        generateAndDeliverPdf();
    });
    document.getElementById('pdfConfirmSkipBtn').addEventListener('click', skipSavedPdfReminder);
    savedPdfConfirmModal.addEventListener('click', event => {
        if (event.target === savedPdfConfirmModal) skipSavedPdfReminder();
    });

    document.addEventListener('keydown', event => {
        if (event.key !== 'Escape') return;
        if (!savedPdfConfirmModal.hidden) {
            skipSavedPdfReminder();
        } else if (!savedPdfModal.hidden) {
            dismissSavedPdfModal();
        }
    });

    function loadPinnedScript(src, isReady, currentPromise) {
        if (isReady()) return Promise.resolve();
        if (currentPromise) return currentPromise;
        return new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = src;
            script.async = true;
            script.onload = () => isReady()
                ? resolve()
                : reject(new Error('The PDF library did not load correctly.'));
            script.onerror = () => reject(new Error('The PDF library could not be loaded. Check your internet connection.'));
            document.head.appendChild(script);
        });
    }

    function loadPdfLibraries() {
        if (!window.html2canvas) {
            html2canvasPromise = loadPinnedScript(
                'https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js',
                () => typeof window.html2canvas === 'function',
                html2canvasPromise
            ).catch(error => {
                html2canvasPromise = null;
                throw error;
            });
        }
        if (!window.jspdf?.jsPDF) {
            jsPdfPromise = loadPinnedScript(
                'https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js',
                () => !!window.jspdf?.jsPDF,
                jsPdfPromise
            ).catch(error => {
                jsPdfPromise = null;
                throw error;
            });
        }
        return Promise.all([html2canvasPromise || Promise.resolve(), jsPdfPromise || Promise.resolve()]);
    }

    function buildPdfClone() {
        const sourceForm = document.getElementById('scoringForm');
        const sourceTable = document.getElementById('scoringTable');
        if (!sourceForm || !sourceTable || sourceTable.hidden || !updateScoringAvailability()) {
            throw new Error('A scoring form must be selected before creating a PDF.');
        }

        const sourceControls = Array.from(sourceForm.querySelectorAll('input, select, textarea'));
        const cloneForm = sourceForm.cloneNode(true);
        const cloneControls = Array.from(cloneForm.querySelectorAll('input, select, textarea'));
        const renderRoot = document.createElement('div');
        renderRoot.className = 'pdf-render-root';
        renderRoot.appendChild(cloneForm);
        document.body.appendChild(renderRoot);

        try {
            cloneForm.querySelectorAll(
                '.form-toolbar, #templateControls, .btn-container, #positionDropdown, #noScoringFormMessage, ' +
                '.no-print, .is-hidden, .tpl-hidden, [hidden], [aria-hidden="true"], button'
            ).forEach(node => node.remove());

            const raterDropdown = cloneForm.querySelector('#raterDropdown');
            if (raterDropdown) {
                const summary = raterDropdown.querySelector('summary');
                Array.from(raterDropdown.children).forEach(child => {
                    if (child !== summary) child.remove();
                });
            }

            cloneForm.querySelectorAll('*').forEach(node => {
                if (getComputedStyle(node).display === 'none') node.remove();
            });

            cloneControls.forEach((cloneControl, index) => {
                const sourceControl = sourceControls[index];
                if (!sourceControl || !cloneControl.isConnected) return;
                if (sourceControl.type === 'hidden') {
                    cloneControl.remove();
                    return;
                }

                const field = document.createElement('span');
                field.className = 'pdf-field';
                if (sourceControl instanceof HTMLInputElement
                    && (sourceControl.type === 'checkbox' || sourceControl.type === 'radio')) {
                    field.classList.add('pdf-field-check');
                    field.textContent = sourceControl.checked ? '✓' : '□';
                    field.setAttribute('aria-label', sourceControl.checked ? 'Checked' : 'Not checked');
                } else if (sourceControl instanceof HTMLSelectElement) {
                    const selected = sourceControl.selectedOptions[0];
                    field.textContent = selected ? selected.textContent.trim() : '';
                } else {
                    field.textContent = sourceControl.value;
                }

                const style = getComputedStyle(sourceControl);
                field.style.display = style.display === 'inline' || style.display === 'inline-block'
                    ? 'inline-block'
                    : 'block';
                field.style.textAlign = style.textAlign;
                field.style.fontWeight = style.fontWeight;
                field.style.fontSize = style.fontSize;
                field.style.fontFamily = style.fontFamily;
                field.style.color = style.color;
                field.style.verticalAlign = style.verticalAlign;
                field.style.boxSizing = 'border-box';
                const inlineWidth = sourceControl.style.width;
                field.style.width = inlineWidth || '100%';
                if (field.classList.contains('pdf-field-check')) {
                    field.style.width = 'auto';
                    field.style.minWidth = '1em';
                    field.style.textAlign = 'center';
                }
                if (sourceControl instanceof HTMLTextAreaElement) {
                    field.classList.add('pdf-field-multiline');
                    field.style.whiteSpace = 'pre-wrap';
                }
                cloneControl.replaceWith(field);
            });

            const raterSummary = cloneForm.querySelector('#raterSummary');
            if (raterSummary) {
                raterSummary.style.whiteSpace = 'normal';
                raterSummary.style.overflow = 'visible';
                raterSummary.style.textOverflow = 'clip';
            }

            const cloneTable = cloneForm.querySelector('#scoringTable');
            if (!cloneTable) {
                throw new Error('The selected scoring table could not be cloned.');
            }
            Array.from(cloneForm.children).forEach(child => {
                if (child !== cloneTable) child.remove();
            });

            cloneForm.style.width = '1100px';
            cloneForm.style.maxWidth = 'none';
            cloneForm.style.height = 'auto';
            cloneForm.style.minHeight = '0';
            cloneForm.style.maxHeight = 'none';
            cloneForm.style.overflow = 'visible';
            cloneForm.style.margin = '0';
            cloneForm.style.boxSizing = 'border-box';
            cloneForm.style.boxShadow = 'none';
            cloneTable.style.height = 'auto';
            cloneTable.style.maxHeight = 'none';
            cloneTable.style.overflow = 'visible';
            return { renderRoot, cloneForm, cloneTable };
        } catch (error) {
            renderRoot.remove();
            throw error;
        }
    }

    function getPdfContentHeight(cloneTable) {
        const tableRect = cloneTable.getBoundingClientRect();
        const lastRowBottom = Array.from(cloneTable.querySelectorAll('tr'))
            .reduce((bottom, row) => Math.max(
                bottom,
                Math.ceil(row.getBoundingClientRect().bottom - tableRect.top)
            ), 0);
        return Math.ceil(Math.max(cloneTable.scrollHeight, tableRect.height, lastRowBottom));
    }

    function getPdfPages(cloneTable, maxStripHeight, maxPageHeight) {
        const totalHeight = getPdfContentHeight(cloneTable);
        const tableRect = cloneTable.getBoundingClientRect();
        const rowEnds = Array.from(cloneTable.querySelectorAll('tr'))
            .map(row => Math.ceil(row.getBoundingClientRect().bottom - tableRect.top))
            .filter(bottom => bottom > 0 && bottom <= totalHeight)
            .sort((a, b) => a - b);
        rowEnds.push(totalHeight);
        const pages = [];
        let pageStart = 0;

        while (pageStart < totalHeight) {
            const pageLimit = Math.min(pageStart + maxPageHeight, totalHeight);
            const pageCandidates = rowEnds.filter(bottom => bottom > pageStart && bottom <= pageLimit);
            const pageEnd = pageCandidates.length
                ? pageCandidates[pageCandidates.length - 1]
                : rowEnds.find(bottom => bottom > pageStart);
            if (!pageEnd || pageEnd - pageStart > maxPageHeight) {
                throw new Error('A form row is too tall to fit on a PDF page.');
            }

            const page = { slices: [], height: pageEnd - pageStart };
            let sliceStart = pageStart;
            while (sliceStart < pageEnd) {
                const sliceLimit = Math.min(sliceStart + maxStripHeight, pageEnd);
                const sliceCandidates = rowEnds.filter(bottom => bottom > sliceStart && bottom <= sliceLimit);
                const sliceEnd = sliceCandidates.length
                    ? sliceCandidates[sliceCandidates.length - 1]
                    : rowEnds.find(bottom => bottom > sliceStart);
                if (!sliceEnd || sliceEnd > pageEnd) {
                    throw new Error('Could not find a safe PDF strip boundary.');
                }
                page.slices.push({ y: sliceStart, height: sliceEnd - sliceStart });
                sliceStart = sliceEnd;
            }

            pages.push(page);
            pageStart = pageEnd;
        }
        return pages;
    }

    function pdfFilename(candidate, position) {
        const now = new Date();
        const date = [
            now.getFullYear(),
            String(now.getMonth() + 1).padStart(2, '0'),
            String(now.getDate()).padStart(2, '0')
        ].join('-');
        const clean = value => String(value || '')
            .normalize('NFKD')
            .replace(/[\u0300-\u036f]/g, '')
            .replace(/[^A-Za-z0-9_-]+/g, '_')
            .replace(/^_+|_+$/g, '');
        const parts = [clean(candidate), clean(position)].filter(Boolean);
        if (!parts.length) return 'scoring-form.pdf';
        const stem = [...parts, date].join('_').slice(0, 76).replace(/_+$/g, '');
        return (stem || 'scoring-form') + '.pdf';
    }

    function revokePdfBlobUrl() {
        if (pdfBlobUrlTimer) clearTimeout(pdfBlobUrlTimer);
        if (pdfBlobUrl) URL.revokeObjectURL(pdfBlobUrl);
        pdfBlobUrl = null;
        pdfBlobUrlTimer = null;
    }

    function discardPdfArtifacts() {
        revokePdfBlobUrl();
        if (pdfDataTimer) clearTimeout(pdfDataTimer);
        pdfDataTimer = null;
        pdfFile = null;
        pdfFileName = '';
        pdfBlob = null;
    }

    function ensurePdfBlobUrl() {
        if (!pdfBlob) throw new Error('The PDF is no longer available. Please create it again.');
        if (!pdfBlobUrl) {
            pdfBlobUrl = URL.createObjectURL(pdfBlob);
            pdfBlobUrlTimer = setTimeout(revokePdfBlobUrl, 60000);
        }
        return pdfBlobUrl;
    }

    function closeModalAfterDelivery() {
        savedPdfModal.hidden = true;
        savedPdfConfirmModal.hidden = true;
    }

    async function shareOrOpenPdf(retryShare = false) {
        if (!pdfBlob) {
            setPdfStatus('The PDF is no longer available. Please create it again.', 'error');
            return;
        }
        let canShareFile = false;
        if (pdfFile && typeof navigator.share === 'function' && typeof navigator.canShare === 'function') {
            try {
                canShareFile = navigator.canShare({ files: [pdfFile] });
            } catch (error) {
                canShareFile = false;
            }
        }
        if (canShareFile) {
            try {
                await navigator.share({ files: [pdfFile], title: pdfFileName });
                setPdfStatus('', '');
                closeModalAfterDelivery();
                return;
            } catch (error) {
                if (error && error.name === 'AbortError') return;
                if (error && error.name === 'NotAllowedError' && !retryShare) {
                    setPdfStatus('Your PDF is ready. Tap “Share / Open PDF” to share it from a fresh tap.', 'share');
                    return;
                }
                if (error && error.name !== 'NotAllowedError') throw error;
            }
        }

        const url = ensurePdfBlobUrl();
        if (retryShare) {
            const opened = window.open(url, '_blank', 'noopener');
            if (!opened) window.print();
            closeModalAfterDelivery();
            return;
        }

        const link = document.createElement('a');
        if ('download' in link) {
            link.href = url;
            link.download = pdfFileName;
            link.style.display = 'none';
            document.body.appendChild(link);
            link.click();
            link.remove();
            closeModalAfterDelivery();
            return;
        }

        const opened = window.open(url, '_blank', 'noopener');
        if (!opened) window.print();
        closeModalAfterDelivery();
    }

    async function generateAndDeliverPdf(retryShare = false) {
        if (pdfBusy) return;
        if (retryShare) {
            try {
                await shareOrOpenPdf(true);
            } catch (error) {
                setPdfStatus('Could not open or share the PDF. Use Print as a fallback.', 'error');
            }
            return;
        }

        setPdfBusy(true);
        discardPdfArtifacts();
        try {
            await loadPdfLibraries();
            const { renderRoot, cloneForm, cloneTable } = buildPdfClone();
            try {
                const jsPDF = window.jspdf.jsPDF;
                const candidate = document.querySelector('#scoringForm input[name="candidate_name"]')?.value.trim() || '';
                const position = document.getElementById('position_input')?.value.trim() || '';
                const cssPxToPt = 0.75;
                const marginPx = 12;
                const marginPt = marginPx * cssPxToPt;
                const pageWidthPt = (1100 + marginPx * 2) * cssPxToPt;
                const totalHeight = getPdfContentHeight(cloneTable);
                const pageMaxHeightPt = 14000;
                const maxPageContentHeight = Math.floor((pageMaxHeightPt - marginPt * 2) / cssPxToPt);
                const pages = getPdfPages(cloneTable, 1500, maxPageContentHeight);
                if (!pages.length || totalHeight !== pages.reduce((height, page) => height + page.height, 0)) {
                    throw new Error('Could not measure the form for PDF export.');
                }
                const onPhone = /Android|iPhone|iPad|iPod|Mobile/i.test(navigator.userAgent)
                    || window.matchMedia('(pointer: coarse)').matches;
                const scale = onPhone ? 1.5 : 2;
                const filename = pdfFilename(candidate, position);
                const firstPageHeightPt = (pages[0].height + marginPx * 2) * cssPxToPt;
                const firstPageOrientation = pageWidthPt > firstPageHeightPt ? 'landscape' : 'portrait';
                const pdf = new jsPDF({
                    orientation: firstPageOrientation,
                    unit: 'pt',
                    format: [pageWidthPt, firstPageHeightPt],
                    compress: true
                });
                const imageWidthPt = 1100 * cssPxToPt;

                for (let pageIndex = 0; pageIndex < pages.length; pageIndex++) {
                    const page = pages[pageIndex];
                    if (pageIndex > 0) {
                        const pageHeightPt = (page.height + marginPx * 2) * cssPxToPt;
                        const pageOrientation = pageWidthPt > pageHeightPt ? 'landscape' : 'portrait';
                        pdf.addPage([pageWidthPt, pageHeightPt], pageOrientation);
                    }
                    let pageOffsetPx = 0;
                    for (const slice of page.slices) {
                        const canvas = await window.html2canvas(cloneTable, {
                            y: slice.y,
                            height: slice.height,
                            width: 1100,
                            windowWidth: 1100,
                            windowHeight: Math.max(window.innerHeight, totalHeight),
                            scale,
                            backgroundColor: '#ffffff',
                            scrollX: 0,
                            scrollY: 0,
                            useCORS: true,
                            logging: false
                        });
                        try {
                            const image = canvas.toDataURL('image/jpeg', 0.92);
                            pdf.addImage(
                                image,
                                'JPEG',
                                marginPt,
                                marginPt + pageOffsetPx * cssPxToPt,
                                imageWidthPt,
                                slice.height * cssPxToPt
                            );
                        } finally {
                            canvas.width = 0;
                            canvas.height = 0;
                        }
                        pageOffsetPx += slice.height;
                    }
                }

                pdfBlob = pdf.output('blob');
                pdfFileName = filename;
                pdfFile = typeof File === 'function'
                    ? new File([pdfBlob], filename, { type: 'application/pdf' })
                    : null;
                pdfDataTimer = setTimeout(() => {
                    pdfFile = null;
                    pdfFileName = '';
                    pdfBlob = null;
                    pdfDataTimer = null;
                }, 60000);
                try {
                    await shareOrOpenPdf(false);
                } catch (error) {
                    setPdfStatus('Could not share or download the PDF. Use Print as a fallback.', 'error');
                }
            } finally {
                renderRoot.remove();
            }
        } catch (error) {
            const message = error instanceof Error && (
                error.message.startsWith('A scoring form')
                || error.message.startsWith('The PDF library')
            ) ? error.message : 'Could not create the PDF. Please try again.';
            setPdfStatus(message, 'error');
        } finally {
            setPdfBusy(false);
            if (pdfStatusText) setPdfStatus(pdfStatusText, pdfStatusMode);
        }
    }

    savePdfButtons.forEach(button => {
        if (button) button.addEventListener('click', () => generateAndDeliverPdf());
    });
    pdfStatuses.forEach(status => {
        if (!status) return;
        status.addEventListener('click', event => {
            const action = event.target.closest('[data-pdf-action]')?.dataset.pdfAction;
            if (action === 'retry') generateAndDeliverPdf();
            if (action === 'share') generateAndDeliverPdf(true);
            if (action === 'print') window.print();
        });
    });
    const ALL_POSITIONS = [
        "Accountant I", "Accountant II", "Accountant III", "Accounting Analyst",
        "Administrative Aide I", "Administrative Aide III", "Administrative Aide IV", "Administrative Aide VI", "Administrative Aide VI (Driver II)",
        "Administrative Assistant I", "Administrative Assistant I (Computer Operator I)", "Administrative Assistant II",
        "Administrative Assistant II (Bookkeeper)", "Administrative Assistant II (Budgeting Asst.)", "Administrative Assistant II (Senior Bookkeeper)",
        "Administrative Assistant III", "Administrative Assistant III (Computer Operator II)", "Administrative Assistant III (Senior Bookkeeper)",
        "Administrative Officer I", "Administrative Officer I (Cashier I)", "Administrative Officer I (Records Officer I)", "Administrative Officer I (Supply Officer I)",
        "Administrative Officer II", "Administrative Officer II (Budget Officer I)", "Administrative Officer III",
        "Administrative Officer IV", "Administrative Officer IV (Budget Officer II)", "Administrative Officer IV (HRMO II)",
        "Administrative Officer V", "Administrative Officer V (Budget Officer III)", "Administrative Officer V (Cashier III)", "Administrative Officer V (HRMO III)",
        "Attorney II", "Attorney III", "Attorney IV", "Attorney V",
        "Cartographer I", "Cartographer II", "Cartographer III", "Cartographer IV",
        "Chief Administrative Officer", "Chief Ecosystems Management Specialist",
        "Communication Development Officer I", "Communication Development Officer II",
        "Community Development Assistant II", "Community Development Officer II",
        "Community Environment & Natural Resources Officer", "Community Environment & Natural Resources Officer (CENRO)",
        "Credit Officer I",
        "Development Management Officer I", "Development Management Officer II", "Development Management Officer III", "Development Management Officer IV", "Development Management Officer V",
        "Draftsman I", "Draftsman II",
        "Ecosystems Management Specialist I", "Ecosystems Management Specialist II",
        "Engineer I (Geodetic Engineer)", "Engineer II (Geodetic Engineer)", "Engineer III (Geodetic Engineer)", "Engineer IV (Geodetic Engineer)", "Engineer V (Geodetic Engineer)",
        "Engineering Aide", "Forest Ranger",
        "Forest Technician I", "Forest Technician II",
        "Forester I", "Forester II", "Forester III",
        "Information Officer I", "Information Officer II",
        "Information Systems Analyst I", "Information Systems Analyst II", "Information Systems Analyst III",
        "Laborer I", "Land Management Inspector",
        "Land Management Officer I", "Land Management Officer II", "Land Management Officer III",
        "Legal Assistant II",
        "Mathematician Aide I", "Mathematician Aide II", "Mathematician I",
        "Park Attendant II", "Park Maintenance Foreman", "Park Operation Superintendent IV", "Park Operation Superintendent V",
        "Planning Officer I", "Planning Officer II", "Planning Officer III", "Planning Officer IV", "Planning Officer V",
        "Project Evaluation Officer I", "Project Evaluation Officer II", "Project Evaluation Officer III",
        "Provincial Environment & Natural Resources Officer",
        "Scaler", "Senior Communications Development Officer", "Senior Ecosystems Management Specialist",
        "Special Investigator I", "Statistician I", "Statistician II",
        "Supervising Administrative Officer", "Supervising Ecosystems Management Specialist",
        "Tracer", "Tree Marker", "Utility Worker I", "Zoology Technician"
    ];

    const positionInput    = document.getElementById('position_input');
    const positionDropdown = document.getElementById('positionDropdown');

    function posCompact(s) {
        return String(s || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
    }

    function normalizeSearchText(value) {
        const text = String(value || '').toLowerCase().trim();
        if (text === '') return '';
        const noAmp = text.replace(/&/g, 'and');
        const cleaned = noAmp.replace(/[^a-z0-9\s]/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();
        const romanMap = {
            'iv': '4', 'iii': '3', 'ii': '2', 'i': '1',
            'v': '5', 'vi': '6', 'vii': '7', 'viii': '8',
            'ix': '9', 'x': '10', 'xi': '11'
        };
        let normalized = cleaned;
        Object.entries(romanMap).forEach(([roman, digit]) => {
            normalized = normalized.replace(new RegExp('\\b' + roman + '\\b', 'g'), digit);
        });
        normalized = normalized
            .replace(/\battorney\b/g, 'atty')
            .replace(/\bplanning officer\b/g, 'plo')
            .replace(/\bproject evaluation officer\b/g, 'peo')
            .replace(/\bdevelopment management officer\b/g, 'dmo')
            .replace(/\badministrative officer\b/g, 'adof')
            .replace(/\badministrative assistant\b/g, 'adas')
            .replace(/\badministrative aide\b/g, 'ada')
            .replace(/\bforest technician\b/g, 'ft')
            .replace(/\bforester\b/g, 'for')
            .replace(/\bland management officer\b/g, 'lmo')
            .replace(/\bcommunity environment and natural resources officer\b/g, 'cenro')
            .replace(/\bprovincial environment and natural resources officer\b/g, 'penro')
            .replace(/\binformation systems analyst\b/g, 'isa')
            .replace(/\bcartographer\b/g, 'cg')
            .replace(/\bengineer\b/g, 'eng')
            .replace(/\becosystems management specialist\b/g, 'ems')
            .replace(/\bsupervising administrative officer\b/g, 'sao')
            .replace(/\bsupervising ecosystems management specialist\b/g, 'svems')
            .replace(/\bsenior ecosystems management specialist\b/g, 'srems')
            .replace(/\bsenior communications development officer\b/g, 'srcdo')
            .replace(/\bchief ecosystems management specialist\b/g, 'cecms')
            .replace(/\bchief administrative officer\b/g, 'caof')
            .replace(/\bcommunity environment and natural resources officer \(cenro\)\b/g, 'cenro')
            .replace(/\bgeodetic engineer\b/g, 'ge');
        return normalized.replace(/\s+/g, '').replace(/[^a-z0-9]/g, '');
    }

    const POSITION_ALIASES = {
        'ATY5': 'Attorney V', 'ATTY5': 'Attorney V', 'ATTORNEYV': 'Attorney V',
        'ATY4': 'Attorney IV', 'ATTY4': 'Attorney IV', 'ATTORNEYIV': 'Attorney IV',
        'ATY3': 'Attorney III', 'ATTY3': 'Attorney III', 'ATTORNEYIII': 'Attorney III',
        'ATY2': 'Attorney II', 'ATTY2': 'Attorney II', 'ATTORNEYII': 'Attorney II',
        'PLO1': 'Planning Officer I', 'PLO2': 'Planning Officer II',
        'PLO3': 'Planning Officer III', 'PLO4': 'Planning Officer IV', 'PLO5': 'Planning Officer V',
        'PEO1': 'Project Evaluation Officer I', 'PEO2': 'Project Evaluation Officer II', 'PEO3': 'Project Evaluation Officer III',
        'DMO1': 'Development Management Officer I', 'DMO2': 'Development Management Officer II', 'DMO3': 'Development Management Officer III',
        'DMO4': 'Development Management Officer IV', 'DMO5': 'Development Management Officer V',
        'ENG1': 'Engineer I (Geodetic Engineer)', 'ENG2': 'Engineer II (Geodetic Engineer)', 'ENG3': 'Engineer III (Geodetic Engineer)',
        'ENG4': 'Engineer IV (Geodetic Engineer)', 'ENG5': 'Engineer V (Geodetic Engineer)',
        'FOR1': 'Forester I', 'FORESTER1': 'Forester I', 'FOR2': 'Forester II', 'FORESTER2': 'Forester II',
        'FOR3': 'Forester III', 'FORESTER3': 'Forester III',
        'FORST1': 'Forester I', 'FORST2': 'Forester II', 'FORST3': 'Forester III',
        'FT1': 'Forest Technician I', 'FT2': 'Forest Technician II',
        'LMO1': 'Land Management Officer I', 'LMO2': 'Land Management Officer II', 'LMO3': 'Land Management Officer III',
        'LAMI': 'Land Management Inspector',
        'ADOF1': 'Administrative Officer I', 'ADOF2': 'Administrative Officer II', 'ADOF3': 'Administrative Officer III',
        'ADOF4': 'Administrative Officer IV', 'ADOF5': 'Administrative Officer V',
        'AO1': 'Administrative Officer I', 'AO2': 'Administrative Officer II', 'AO3': 'Administrative Officer III',
        'AO4': 'Administrative Officer IV', 'AO5': 'Administrative Officer V',
        'ADAS1': 'Administrative Assistant I', 'ADAS2': 'Administrative Assistant II', 'ADAS3': 'Administrative Assistant III',
        'ADA1': 'Administrative Aide I', 'ADA3': 'Administrative Aide III', 'ADA4': 'Administrative Aide IV', 'ADA6': 'Administrative Aide VI',
        'PENRO': 'Provincial Environment & Natural Resources Officer',
        'CENRO': 'Community Environment & Natural Resources Officer',
        'SVEMS': 'Supervising Ecosystems Management Specialist', 'SREMS': 'Senior Ecosystems Management Specialist',
        'EMS1': 'Ecosystems Management Specialist I', 'EMS2': 'Ecosystems Management Specialist II',
        'ECOMS1': 'Ecosystems Management Specialist I', 'ECOMS2': 'Ecosystems Management Specialist II',
        'CECMS': 'Chief Ecosystems Management Specialist',
        'CADOF': 'Chief Administrative Officer',
        'SAO': 'Supervising Administrative Officer', 'SADOF': 'Supervising Administrative Officer',
        'SRCDO': 'Senior Communications Development Officer',
        'LAMO1': 'Land Management Officer I', 'LAMO2': 'Land Management Officer II', 'LAMO3': 'Land Management Officer III',
        'INFOSA1': 'Information Systems Analyst I', 'INFOSA2': 'Information Systems Analyst II', 'INFOSA3': 'Information Systems Analyst III',
        'CGR1': 'Cartographer I', 'CGR2': 'Cartographer II', 'CGR3': 'Cartographer III', 'CGR4': 'Cartographer IV',
        'CARTO1': 'Cartographer I', 'CARTO2': 'Cartographer II', 'CARTO3': 'Cartographer III', 'CARTO4': 'Cartographer IV'
    };

    const POSITION_LOOKUP = (function () {
        const map = {};
        ALL_POSITIONS.forEach(title => {
            const compact = posCompact(title);
            map[compact] = title;
            map[normalizeSearchText(title)] = title;
        });
        Object.keys(POSITION_ALIASES).forEach(k => {
            const canonical = POSITION_ALIASES[k];
            if (ALL_POSITIONS.includes(canonical) && !(k in map)) {
                map[k] = canonical;
            }
            const normalized = normalizeSearchText(k);
            if (normalized && !(normalized in map)) map[normalized] = canonical;
        });
        return map;
    })();

    function positionSearchForms(title) {
        const forms = new Set([posCompact(title).toLowerCase(), normalizeSearchText(title)]);
        Object.keys(POSITION_ALIASES).forEach(alias => {
            if (POSITION_ALIASES[alias] === title) {
                forms.add(posCompact(alias).toLowerCase());
                forms.add(normalizeSearchText(alias));
            }
        });

        const variants = [String(title || ''), String(title || '').replace(/\([^)]*\)/g, ' ')];
        variants.forEach(variant => {
            const words = String(variant).toUpperCase().replace(/&/g, ' AND ')
                .replace(/[^A-Z0-9\s]/g, ' ').trim().split(/\s+/)
                .map(word => ({
                    I:'1', II:'2', III:'3', IV:'4', V:'5', VI:'6', VII:'7',
                    VIII:'8', IX:'9', X:'10', XI:'11'
                }[word] || word))
                .filter(word => word && word !== 'AND');
            if (!words.length) return;

            const suffix = /^\d+$/.test(words[words.length - 1]) ? words[words.length - 1] : '';
            const nameWords = suffix ? words.slice(0, -1) : words;
            if (!nameWords.length) return;

            forms.add(nameWords.map(word => word[0]).join('').toLowerCase() + suffix);
            for (let length = 2; length <= Math.min(nameWords[0].length, 5); length++) {
                const abbreviated = nameWords[0].slice(0, length)
                    + nameWords.slice(1).map(word => word[0]).join('');
                forms.add((abbreviated + suffix).toLowerCase());
            }
        });
        return Array.from(forms).filter(Boolean);
    }

    function positionMatchesSearch(title, query) {
        const compactQuery = posCompact(query).toLowerCase();
        const normalizedQuery = normalizeSearchText(query);
        if (!compactQuery) return true;
        return positionSearchForms(title).some(form =>
            form.includes(compactQuery) || (normalizedQuery && form.includes(normalizedQuery))
        );
    }

    function canonicalPositionTitle(typed) {
        const raw = String(typed || '').trim();
        if (raw === '') return '';
        const direct = posCompact(raw);
        if (direct in POSITION_LOOKUP) return POSITION_LOOKUP[direct];
        const normalized = normalizeSearchText(raw);
        if (normalized in POSITION_LOOKUP) return POSITION_LOOKUP[normalized];
        const matches = ALL_POSITIONS.filter(title => positionMatchesSearch(title, raw));
        if (matches.length === 1) return matches[0];
        return raw;
    }

    let activeIndex = -1;

    function renderPositionDropdown(filter) {
        const raw = String(filter || '');
        let sourcePositions = ALL_POSITIONS;
        if (RESTRICT_DROPDOWN_TO_API_LIST
            && document.getElementById('office_name').value === 'Regional Office'
            && positionApiTitles.length > 0) {
            sourcePositions = positionApiTitles;
        }
        const resolved = canonicalPositionTitle(raw);
        const matches = sourcePositions.includes(resolved)
            ? [resolved]
            : sourcePositions.filter(title => positionMatchesSearch(title, raw));
        positionDropdown.innerHTML = '';

        if (matches.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'pd-empty';
            empty.textContent = 'No matching position — type the title and it will be saved as-is.';
            positionDropdown.appendChild(empty);
            positionDropdown.classList.add('open');
            activeIndex = -1;
            return;
        }

        matches.slice(0, 80).forEach((title, i) => {
            const item = document.createElement('div');
            item.className = 'pd-item' + (i === activeIndex ? ' active' : '');
            item.setAttribute('role', 'option');
            item.textContent = title;
            item.addEventListener('mousedown', (e) => {
                e.preventDefault();
                positionInput.value = title;
                positionDropdown.classList.remove('open');
                activeIndex = -1;
                applyFormLayout();
            });
            positionDropdown.appendChild(item);
        });
        positionDropdown.classList.add('open');
    }

    function closePositionDropdown() {
        positionDropdown.classList.remove('open');
        activeIndex = -1;
    }

    positionInput.addEventListener('focus', () => {
        if (!positionInput.readOnly) renderPositionDropdown(positionInput.value);
    });
    positionInput.addEventListener('input', () => {
        activeIndex = -1;
        renderPositionDropdown(positionInput.value);
        applyFormLayout();
    });

    positionInput.addEventListener('keydown', (e) => {
        if (positionInput.readOnly) return;
        if (!positionDropdown.classList.contains('open')) {
            if (e.key === 'ArrowDown') { renderPositionDropdown(positionInput.value); e.preventDefault(); }
            return;
        }
        const items = positionDropdown.querySelectorAll('.pd-item');
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            activeIndex = Math.min(activeIndex + 1, items.length - 1);
            items.forEach((el, i) => el.classList.toggle('active', i === activeIndex));
            if (items[activeIndex]) items[activeIndex].scrollIntoView({ block: 'nearest' });
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            activeIndex = Math.max(activeIndex - 1, 0);
            items.forEach((el, i) => el.classList.toggle('active', i === activeIndex));
            if (items[activeIndex]) items[activeIndex].scrollIntoView({ block: 'nearest' });
        } else if (e.key === 'Enter') {
            if (activeIndex >= 0 && items[activeIndex]) {
                e.preventDefault();
                positionInput.value = items[activeIndex].textContent;
                closePositionDropdown();
                applyFormLayout();
            } else {
                positionInput.value = canonicalPositionTitle(positionInput.value);
                closePositionDropdown();
                applyFormLayout();
            }
        } else if (e.key === 'Escape') {
            closePositionDropdown();
        }
    });

    positionInput.addEventListener('blur', () => {
        if (positionInput.readOnly) return;
        setTimeout(() => {
            if (!positionDropdown.contains(document.activeElement)) {
                positionInput.value = canonicalPositionTitle(positionInput.value);
                closePositionDropdown();
                applyFormLayout();
            }
        }, 120);
    });

    document.addEventListener('click', (e) => {
        if (!positionDropdown.contains(e.target) && e.target !== positionInput) {
            closePositionDropdown();
        }
    });

    const LEADERSHIP_LEVELS = <?php echo json_encode($leadershipPositionLevels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const LEADERSHIP_ALIASES = <?php echo json_encode($leadershipAliases, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const NUMERAL_DIGIT = { II: "2", III: "3", IV: "4", V: "5" };
    let POSITION_SG_MAP = {};
    let positionApiTitles = [];
    let lastAutoSgPosition = '';
    const RESTRICT_DROPDOWN_TO_API_LIST = false;

    const LEADERSHIP_BASIC_ROWS = <?php echo json_encode($basicLeadershipRows, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const LEADERSHIP_ADVANCED_ROWS = <?php echo json_encode($advancedLeadershipRows, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

    function normPosition(s) {
        return String(s || '').replace(/&/g, ' AND ').replace(/\s+/g, ' ').trim().toUpperCase();
    }
    function compactPosition(s) {
        return normPosition(s).replace(/[^\p{L}\p{N}]/gu, '');
    }
    function templatePositionTitle(title, prefix) {
        const position = String(title || '').startsWith(prefix) ? String(title).slice(prefix.length) : '';
        return (['SG 6 - ', 'SG 7 - ', 'SG 8 - ', 'SG 10 - ', 'SG 11 - ', 'SG 18 - ', 'SG 19 - ', 'SG 20 - ', 'SG 21 - ', 'SG 22 - ', 'SG 23 - '].includes(prefix))
            ? position.replace(/\s+\[Field Office\]$/i, '').replace(/\s+\[RO: [^\]]+\]$/i, '').trim()
            : position.trim();
    }
    function compactNoParens(s) {
        return compactPosition(String(s || '').replace(/\([^)]*\)/g, ' '));
    }

    function salaryGradeFor(position) {
        const full = compactPosition(position);
        if (full === '') return null;
        if (Object.prototype.hasOwnProperty.call(POSITION_SG_MAP, full)) return POSITION_SG_MAP[full];
        const base = compactNoParens(position);
        return Object.prototype.hasOwnProperty.call(POSITION_SG_MAP, base) ? POSITION_SG_MAP[base] : null;
    }

    function autofillSalaryGrade() {
        const office = document.getElementById('office_name');
        if (!office || office.value !== 'Regional Office') {
            lastAutoSgPosition = '';
            return;
        }
        const key = compactPosition(currentPosition());
        if (key === '' || key === lastAutoSgPosition) return;
        const salaryGrade = salaryGradeFor(currentPosition());
        if (salaryGrade === null) return;
        const input = document.getElementById('salaryGrade');
        if (!input) return;
        input.value = String(salaryGrade);
        lastAutoSgPosition = key;
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }

    document.getElementById('office_name').addEventListener('change', () => {
        lastAutoSgPosition = '';
        autofillSalaryGrade();
    });

    const LEADERSHIP_LEVEL_MAP = (function () {
        const map = {};
        LEADERSHIP_LEVELS.forEach(([t, lvl]) => { map[compactPosition(t)] = lvl; });
        LEADERSHIP_LEVELS.forEach(([t, lvl]) => {
            if (/\(\s*selected items\s*\)/i.test(t)) return;
            const base = compactNoParens(t);
            if (!(base in map)) map[base] = lvl;
        });
        LEADERSHIP_ALIASES.forEach(([abbr, numeral, lvl]) => {
            const keys = [abbr + numeral];
            if (numeral && NUMERAL_DIGIT[numeral]) keys.push(abbr + NUMERAL_DIGIT[numeral]);
            keys.forEach(k => { if (!(k in map)) map[k] = lvl; });
        });
        return map;
    })();

    function leadershipLevelFor(position) {
        const full = compactPosition(position);
        if (full === '') return null;
        if (full in LEADERSHIP_LEVEL_MAP) return LEADERSHIP_LEVEL_MAP[full];
        const base = compactNoParens(position);
        return (base !== '' && base in LEADERSHIP_LEVEL_MAP) ? LEADERSHIP_LEVEL_MAP[base] : null;
    }

    const LC_BASIC = LEADERSHIP_BASIC_ROWS.map(row => row.desc);
    const LC_ADVANCED = LEADERSHIP_ADVANCED_ROWS.map(row => row.desc);

    function currentPosition() {
        return positionInput ? positionInput.value.trim() : '';
    }

    function applyFormLayout() {
        autofillSalaryGrade();
        const candidateGrade = Number(salaryGradeSelect.value);
        const templateLead = selectedTemplateLayout.sections?.lead;
        const positionLeadershipLevel = leadershipLevelFor(currentPosition());
        const showLeadership = Number.isInteger(candidateGrade)
            && candidateGrade > BASIC_MAX_SG
            && !!templateLead
            && (positionLeadershipLevel === 'B' || positionLeadershipLevel === 'I');
        const leadershipDescriptions = positionLeadershipLevel === 'I'
            ? LC_ADVANCED
            : (positionLeadershipLevel === 'B' ? LC_BASIC_DEFAULT : LC_BASIC);
        for (let i = 1; i <= 5; i++) {
            document.getElementById('lcDesc' + i).textContent = leadershipDescriptions[i - 1];
        }
        const leadershipLabel = positionLeadershipLevel === 'I' ? 'Advanced'
            : (positionLeadershipLevel === 'B' ? 'Basic' : (templateLead?.level || ''));
        leadershipLevelInput.value = leadershipLabel;

        document.querySelectorAll('tr.leadership-row').forEach(row => {
            row.classList.toggle('is-hidden', !showLeadership);
            row.querySelectorAll('input, select, textarea').forEach(el => {
                el.disabled = !showLeadership || row.classList.contains('tpl-hidden');
                if (!showLeadership && (el.name.startsWith('lc') || el.name.startsWith('evidence_lc') || el.dataset.section === 'lead')) {
                    el.value = '';
                }
            });
        });
        const leadSection = templateLead?.standard ? templateLead : null;
        const canEditLabels = canEditTemplateLabels && !!competencyTemplateIdInput.value && !!leadSection && showLeadership;
        leadershipHeadingInput.disabled = !canEditLabels;
        leadershipLevelInput.disabled = true;
        updateScoringAvailability();
        if (typeof markEdited === 'function') markEdited();
    }

    document.getElementById('scoringForm').addEventListener('input', e => {
        const el = e.target;
        if (el.type === 'number' && /^(cc|oc|lc|pcp|pco)\d$/.test(el.name || '')) {
            if (el.value !== '' && parseFloat(el.value) > 10) el.value = '10';
            if (el.value !== '' && parseFloat(el.value) < 0)  el.value = '0';
        }
    });

    document.getElementById('scoringForm').addEventListener('keydown', e => {
        if (e.key === 'Enter' && e.target.tagName !== 'TEXTAREA' && e.target.tagName !== 'BUTTON') {
            e.preventDefault();
        }
    });

    document.getElementById('scoringForm').addEventListener('reset', () => {
        setTimeout(() => {
            if (IS_ADMIN) {
                positionInput.value = '';
                lastAutoSgPosition = '';
            } else {
                positionInput.value = <?php echo json_encode($selectedPositionTitle, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
                lastAutoSgPosition = positionInput.value;
            }
            closePositionDropdown();
            applyFormLayout();
        }, 0);
    });

    const undoButton = document.getElementById('undoButton');
    const undoHistory = [];
    const lastControlStates = new WeakMap();
    let restoringUndoState = false;

    function readControlState(control) {
        return {
            value: control.value,
            checked: 'checked' in control ? control.checked : undefined
        };
    }

    function sameControlState(a, b) {
        return a && b && a.value === b.value && a.checked === b.checked;
    }

    function updateUndoButton() {
        if (!undoButton) return;
        undoButton.disabled = undoHistory.length === 0;
        undoButton.textContent = undoHistory.length > 0 ? 'Undo (' + undoHistory.length + ')' : 'Undo';
    }

    function clearUndoHistory() {
        undoHistory.length = 0;
        document.querySelectorAll('#scoringForm input, #scoringForm select, #scoringForm textarea').forEach(control => {
            lastControlStates.set(control, readControlState(control));
        });
        updateUndoButton();
    }

    function recordUndoChange(event) {
        if (restoringUndoState || !undoButton) return;
        const control = event.target;
        if (!(control instanceof HTMLInputElement || control instanceof HTMLSelectElement || control instanceof HTMLTextAreaElement)
            || control.type === 'hidden'
            || control.type === 'button'
            || control.type === 'submit'
            || control.type === 'reset') return;

        const previous = lastControlStates.get(control);
        const current = readControlState(control);
        if (previous && !sameControlState(previous, current)) {
            undoHistory.push({ control, state: previous });
            updateUndoButton();
        }
        lastControlStates.set(control, current);
    }

    function undoLastChange() {
        const entry = undoHistory.pop();
        if (!entry || !entry.control.isConnected) {
            updateUndoButton();
            return;
        }

        restoringUndoState = true;
        entry.control.value = entry.state.value;
        if (entry.state.checked !== undefined) entry.control.checked = entry.state.checked;
        lastControlStates.set(entry.control, readControlState(entry.control));
        entry.control.dispatchEvent(new Event('input', { bubbles: true }));
        entry.control.dispatchEvent(new Event('change', { bubbles: true }));
        restoringUndoState = false;
        updateUndoButton();
    }

    if (undoButton) {
        const scoringForm = document.getElementById('scoringForm');
        scoringForm.querySelectorAll('input, select, textarea').forEach(control => {
            lastControlStates.set(control, readControlState(control));
        });
        scoringForm.addEventListener('focusin', event => {
            if (event.target instanceof HTMLInputElement || event.target instanceof HTMLSelectElement || event.target instanceof HTMLTextAreaElement) {
                if (!lastControlStates.has(event.target)) lastControlStates.set(event.target, readControlState(event.target));
            }
        });
        scoringForm.addEventListener('input', recordUndoChange);
        scoringForm.addEventListener('change', recordUndoChange);
        scoringForm.addEventListener('reset', () => setTimeout(clearUndoHistory, 0));
    }

    function selectedRaters() {
        const selected = document.querySelector('#raterPositionControl input[name="raters[]"]:checked');
        return selected ? [selected.value] : [];
    }

    function updateRaterSummary() {
        const selected = selectedRaters();
        const summary = document.getElementById('raterSummary');
        if (summary) {
            summary.textContent = selected.length ? selected[0] : '-- Select Rater Position --';
            if (selected.length) summary.removeAttribute('data-empty');
            else summary.setAttribute('data-empty', '1');
        }
        const orderInput = document.getElementById('ratersOrder');
        orderInput.value = selected.length ? selected[0] : '';
        orderInput.defaultValue = '';
    }

    document.querySelectorAll('input.rater-choice').forEach(radio => {
        radio.addEventListener('change', () => {
            updateRaterSummary();
            const dropdown = document.getElementById('raterDropdown');
            if (dropdown) dropdown.open = false;
        });
    });
    document.getElementById('scoringForm').addEventListener('reset', () => setTimeout(updateRaterSummary, 0));
    updateRaterSummary();
    document.addEventListener('click', e => {
        const dd = document.getElementById('raterDropdown');
        if (dd && !dd.contains(e.target)) dd.open = false;
    });

    function goToAdminResults(event) {
        if (event.altKey) {
            window.location.href = 'results_2.php';
        }
    }

    function saveOnly() {
        if (!CAN_SUBMIT) {
            alert('Your account is view-only. Ask an administrator for a Rater account.');
            return;
        }
        if (scoringSaveBusy) return;
        if (!updateScoringAvailability()) return;

        const form = document.getElementById('scoringForm');
        const btn  = document.getElementById('saveBtn');
        if (!form || !btn) return;

        positionInput.value = canonicalPositionTitle(positionInput.value);

        updateRaterSummary();
        syncCustomCompetencies();
        const selectedRaterPositions = selectedRaters();
        if (selectedRaterPositions.length !== 1) {
            alert('Choose one rater position before saving.');
            return;
        }

        if (!form.checkValidity()) {
            form.reportValidity();
            return;
        }

        btn.disabled = true;
        scoringSaveBusy = true;
        let savedOk = false;
        const submittedDraftState = JSON.stringify(collectDraftState());

        fetch('submit_score.php', {
            method: 'POST',
            body: new FormData(form),
            headers: { 'Accept': 'application/json' }
        })
        .then(response => {
            const ct = response.headers.get('content-type') || '';
            if (!ct.includes('application/json')) {
                return response.text().then(text => {
                    throw new Error('Server returned HTML instead of JSON. The PHP error is: ' + text.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 300));
                });
            }
            return response.json().then(data => ({ ok: response.ok, data }));
        })
        .then(({ ok, data }) => {
            if (ok && data.status === 'success') {
                savedOk = true;
                markSaved();
                showSavedPdfModal();
                persistDraftSnapshot(submittedDraftState);
            } else {
                alert(data.message || 'Failed to save.');
            }
        })
        .catch(err => alert('Error saving data:\n' + err.message))
        .finally(() => {
            scoringSaveBusy = false;
            if (!savedOk) btn.disabled = false;
        });
    }

    function markSaved() {
        const btn = document.getElementById('saveBtn');
        if (btn) { btn.textContent = 'Saved ✓'; btn.disabled = true; }
        const nsb = document.getElementById('newSheetBtn');
        if (nsb) nsb.style.display = 'inline-block';
    }

    function markEdited() {
        const btn = document.getElementById('saveBtn');
        if (btn && btn.textContent !== 'Save') { btn.textContent = 'Save'; btn.disabled = false; }
    }

    function newSheet() {
        document.getElementById('scoringForm').reset();
        document.getElementById('newSheetBtn').style.display = 'none';
        markEdited();
        window.scrollTo(0, 0);
    }

    const competencyTemplateSelect = document.getElementById('competencyTemplate');
    const salaryGradeSelect = document.getElementById('salaryGrade');
    const competencyTemplateIdInput = document.getElementById('competencyTemplateId');
    const customSectionsBody = document.getElementById('customSections');
    const customCompetenciesInput = document.getElementById('customCompetenciesJson');
    const templateLoadStatus = document.getElementById('templateLoadStatus');
    const leadershipHeadingInput = document.getElementById('lcHeadingText');
    const leadershipLevelInput = document.getElementById('lcLevelLabel');
    const scoringTable = document.getElementById('scoringTable');
    const noScoringFormMessage = document.getElementById('noScoringFormMessage');
    const canEditTemplateLabels = <?php echo json_encode(current_role() === 'rater'); ?>;
    let selectedTemplateLayout = { sections: {} };
    let loadedTemplateMeta = null;
    let leadershipLabelsDirty = false;
    let pendingLeadershipLabelSave = Promise.resolve(true);
    let resumeFormSubmitAfterLabelSave = false;
    let leadershipLabelEditVersion = 0;

    const STD_SECTIONS = ['core', 'org', 'lead', 'func'];
    const LC_BASIC_DEFAULT = LC_BASIC.slice();
    const stdOriginals = {};

    function updateScoringAvailability() {
        const salaryGrade = Number(salaryGradeSelect.value);
        const templateTitle = String(loadedTemplateMeta?.title || '');
        const prefix = 'SG ' + salaryGrade + ' - ';
        const templatePosition = templatePositionTitle(templateTitle, prefix);
        const titleMatches = templatePosition !== ''
            && compactPosition(templatePosition) === compactPosition(currentPosition());
        const available = !!competencyTemplateIdInput.value
            && !!loadedTemplateMeta
            && Number.isInteger(salaryGrade)
            && salaryGrade >= Number(loadedTemplateMeta.min_salary_grade)
            && salaryGrade <= Number(loadedTemplateMeta.max_salary_grade)
            && (Number(loadedTemplateMeta.is_shared) === 1 || IS_ADMIN)
            && titleMatches
            && Object.keys(selectedTemplateLayout.sections || {}).length > 0;
        scoringTable.hidden = !available;
        noScoringFormMessage.hidden = available;
        const saveButton = document.getElementById('saveBtn');
        if (saveButton) saveButton.disabled = !available || !CAN_SUBMIT;
        return available;
    }

    function stdRowMap() {
        const map = {};
        document.querySelectorAll('tr.sec-core input[type="number"][name], tr.sec-right input[type="number"][name]').forEach(input => {
            const descRow = input.closest('tr');
            const titleRow = descRow ? descRow.previousElementSibling : null;
            if (titleRow && titleRow.querySelector('.comp-title')) map[input.name] = { titleRow, descRow };
        });
        return map;
    }

    function applyStandardRows(layout) {
        const sections = (layout && layout.sections) || {};
        document.getElementById('sectionHeader_core').classList.toggle('tpl-hidden', !sections.core?.standard);
        document.getElementById('sectionHeader_org').classList.toggle('tpl-hidden', !sections.org?.standard);
        document.getElementById('coreLevelLabel').textContent = sections.core?.standard ? (sections.core.level || '') : '';
        document.getElementById('orgLevelLabel').textContent = sections.org?.standard ? (sections.org.level || '') : '';
        document.getElementById('funcLevelLabel').textContent = sections.func?.standard ? (sections.func.level || '') : '';
        const functionalHeader = document.getElementById('sectionHeader_func');
        if (functionalHeader) {
            functionalHeader.classList.toggle('tpl-hidden', !sections.func?.standard);
        }
        const leadSection = sections.lead && sections.lead.standard ? sections.lead : null;
        leadershipHeadingInput.value = leadSection?.label || '';
        leadershipLevelInput.value = leadSection?.level || '';
        const canEditLabels = canEditTemplateLabels && !!competencyTemplateIdInput.value
            && !!selectedTemplateLayout.sections?.lead?.standard;
        leadershipHeadingInput.disabled = !canEditLabels;
        leadershipLevelInput.disabled = !canEditLabels;
        const fromTemplate = {};
        const usesTemplate = !!competencyTemplateIdInput.value;
        STD_SECTIONS.forEach(key => {
            if (!sections[key]?.standard) return;
            (sections[key].rows || []).forEach(row => { fromTemplate[row.id] = row; });
        });

        const rows = stdRowMap();
        Object.keys(rows).forEach(id => {
            const { titleRow, descRow } = rows[id];
            const titleCell = titleRow.querySelector('.comp-title');
            const descCell = descRow.cells[0];
            const isLead = /^lc\d$/.test(id);
            if (!stdOriginals[id]) {
                stdOriginals[id] = {
                    title: titleCell.textContent,
                    desc: isLead ? LC_BASIC_DEFAULT[Number(id.slice(2)) - 1] : descCell.textContent
                };
            }
            const row = fromTemplate[id];
            const hide = usesTemplate && !row;
            titleRow.classList.toggle('tpl-hidden', hide);
            descRow.classList.toggle('tpl-hidden', hide);
            titleRow.querySelectorAll('input, select, textarea').forEach(input => { input.disabled = hide; });
            descRow.querySelectorAll('input, select, textarea').forEach(input => { input.disabled = hide; });
            titleCell.textContent = row ? (row.title || '') : stdOriginals[id].title;
            const desc = row ? (row.desc || '') : stdOriginals[id].desc;
            if (isLead) LC_BASIC[Number(id.slice(2)) - 1] = desc;
            else descCell.textContent = desc;
        });
        applyFormLayout();
    }

    function updateLeadershipLabelsInLayout() {
        const leadSection = selectedTemplateLayout.sections?.lead;
        if (!leadSection) return;
        leadSection.label = leadershipHeadingInput.value.trim();
        leadSection.level = leadershipLevelInput.value.trim();
        leadershipLabelsDirty = true;
        leadershipLabelEditVersion++;
        templateLoadStatus.textContent = 'Unsaved sheet heading changes';
    }

    async function saveLeadershipLabels() {
        if (!leadershipLabelsDirty) return true;
        const templateId = competencyTemplateIdInput.value;
        if (!templateId || !selectedTemplateLayout.sections?.lead) {
            templateLoadStatus.textContent = 'Choose a sheet with a Leadership section before saving these labels.';
            return false;
        }
        const heading = leadershipHeadingInput.value.trim();
        const level = leadershipLevelInput.value.trim();
        if (!heading || !level) {
            templateLoadStatus.textContent = 'Both Leadership heading fields must have a value.';
            return false;
        }
        selectedTemplateLayout.sections.lead.label = heading;
        selectedTemplateLayout.sections.lead.level = level;
        const savedVersion = leadershipLabelEditVersion;
        try {
            const response = await fetch('template_api.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'template_update_leadership_labels',
                    id: Number(templateId),
                    salary_grade: salaryGradeSelect.value,
                    label: heading,
                    level,
                    csrf_token: <?php echo json_encode(csrf_token()); ?>
                })
            });
            const data = await response.json();
            if (!response.ok || data.status !== 'success') {
                throw new Error(data.message || 'Unable to save the Leadership heading changes.');
            }
            if (savedVersion === leadershipLabelEditVersion) {
                leadershipLabelsDirty = false;
                templateLoadStatus.textContent = 'Sheet headings saved for everyone.';
                return true;
            }
            return saveLeadershipLabels();
        } catch (error) {
            templateLoadStatus.textContent = error.message;
            return false;
        }
    }

    [leadershipHeadingInput, leadershipLevelInput].forEach(input => {
        input.addEventListener('input', updateLeadershipLabelsInLayout);
        input.addEventListener('change', () => {
            pendingLeadershipLabelSave = pendingLeadershipLabelSave.then(saveLeadershipLabels);
        });
    });

    document.getElementById('scoringForm').addEventListener('submit', async event => {
        const form = event.currentTarget;
        if (!CAN_SUBMIT) return;
        if (leadershipLabelsDirty && !resumeFormSubmitAfterLabelSave) {
            event.preventDefault();
            const saved = await pendingLeadershipLabelSave;
            if (!saved) return;
            resumeFormSubmitAfterLabelSave = true;
            if (event.submitter) form.requestSubmit(event.submitter);
            else form.requestSubmit();
            return;
        }
        resumeFormSubmitAfterLabelSave = false;
        event.preventDefault();
        saveOnly();
    });

    async function loadCompetencyTemplates() {
        const salaryGrade = salaryGradeSelect.value;
        competencyTemplateSelect.replaceChildren(new Option('-- Select a designated form --', ''));
        if (salaryGrade === '') return;
        const manageTemplates = IS_ADMIN ? '&manage_templates=1' : '';
        const response = await fetch('template_api.php?action=template_list&salary_grade=' + encodeURIComponent(salaryGrade) + manageTemplates, { credentials: 'same-origin' });
        const data = await response.json();
        if (!response.ok || data.status !== 'success') {
            throw new Error(data.message || 'Unable to load competency templates.');
        }
        if (salaryGradeSelect.value !== salaryGrade) return;
        if (data.templates.length === 0) {
            templateLoadStatus.textContent = 'No shared sheets match your region, field, and candidate salary grade. Check that the sheet is shared with raters and its grade range includes this grade.';
        } else {
            templateLoadStatus.textContent = '';
        }
        data.templates.forEach(template => {
            const option = document.createElement('option');
            option.value = String(template.id);
            option.textContent = template.title;
            competencyTemplateSelect.appendChild(option);
        });
        const requestedTemplateId = new URLSearchParams(window.location.search).get('template_id');
        if (requestedTemplateId) {
            const requestedTemplateAvailable = Array.from(competencyTemplateSelect.options)
                .some(option => option.value === requestedTemplateId);
            if (requestedTemplateAvailable) {
                competencyTemplateSelect.value = requestedTemplateId;
                await selectCompetencyTemplate(requestedTemplateId);
            } else {
                templateLoadStatus.textContent = 'This sheet is not available for the selected candidate salary grade. Choose a grade within the sheet range or select another sheet.';
            }
        } else if (IS_ADMIN) {
            const position = currentPosition();
            const exactPositionKey = compactPosition(position);
            const basePositionKey = compactNoParens(position);
            if (exactPositionKey !== '') {
                const prefix = 'SG ' + salaryGrade + ' - ';
                const matchingTemplate = data.templates.find(template => {
                    const title = String(template.title || '');
                    if (!title.startsWith(prefix)) return false;
                    const templatePosition = templatePositionTitle(title, prefix);
                    return compactPosition(templatePosition) === exactPositionKey;
                }) || data.templates.find(template => {
                    const title = String(template.title || '');
                    if (!title.startsWith(prefix)) return false;
                    return compactNoParens(templatePositionTitle(title, prefix)) === basePositionKey;
                });
                if (matchingTemplate) {
                    competencyTemplateSelect.value = String(matchingTemplate.id);
                    await selectCompetencyTemplate(String(matchingTemplate.id));
                }
            }
        }
    }

    function renderCustomCompetencies(layout) {
        selectedTemplateLayout = layout && layout.sections ? layout : { sections: {} };
        applyStandardRows(selectedTemplateLayout);
        customSectionsBody.replaceChildren();
        Object.entries(selectedTemplateLayout.sections).forEach(([sectionKey, section]) => {
            if (!section || !section.custom) return;
            const header = document.createElement('tr');
            header.className = 'custom-section-row' + (sectionKey === 'lead' ? ' leadership-row template-custom-leadership-row' : '');
            const sectionTitle = document.createElement('th');
            sectionTitle.className = 'sec-header';
            sectionTitle.textContent = section.label || 'Untitled section';
            const sectionLevel = document.createElement('th');
            sectionLevel.className = 'sec-header';
            const positionLeadershipLevel = sectionKey === 'lead' ? leadershipLevelFor(currentPosition()) : null;
            sectionLevel.textContent = positionLeadershipLevel === 'I' ? 'Advanced'
                : (positionLeadershipLevel === 'B' ? 'Basic' : (section.level || ''));
            const ratingHeading = document.createElement('th');
            ratingHeading.className = 'sec-header';
            ratingHeading.textContent = 'Rating';
            header.append(sectionTitle, sectionLevel, ratingHeading);
            customSectionsBody.appendChild(header);

            (section.rows || []).forEach(row => {
                if ((row.title || '').trim() !== '') {
                    const titleRow = document.createElement('tr');
                    titleRow.className = 'custom-section-row' + (sectionKey === 'lead' ? ' leadership-row template-custom-leadership-row' : '');
                    const titleCell = document.createElement('td');
                    titleCell.className = 'comp-title';
                    titleCell.textContent = row.title;
                    titleRow.append(titleCell, document.createElement('td'), document.createElement('td'));
                    customSectionsBody.appendChild(titleRow);
                }

                const competencyRow = document.createElement('tr');
                competencyRow.className = 'custom-section-row' + (sectionKey === 'lead' ? ' leadership-row template-custom-leadership-row' : '');
                const description = document.createElement('td');
                description.textContent = row.desc || '';
                const evidenceCell = document.createElement('td');
                const evidence = document.createElement('input');
                evidence.type = 'text';
                evidence.autocomplete = 'off';
                evidence.name = 'evidence_' + row.id;
                evidence.dataset.customEvidence = '1';
                evidence.dataset.section = sectionKey;
                evidence.dataset.rowId = row.id;
                evidence.setAttribute('aria-label', 'Evidence for ' + (row.title || 'custom competency'));
                evidenceCell.appendChild(evidence);
                const ratingCell = document.createElement('td');
                const rating = document.createElement('input');
                rating.type = 'number';
                rating.step = '0.01';
                rating.min = '0';
                rating.max = '10';
                rating.placeholder = '0.00';
                rating.name = row.id;
                rating.dataset.customRating = '1';
                rating.dataset.section = sectionKey;
                rating.dataset.rowId = row.id;
                rating.setAttribute('aria-label', 'Rating for ' + (row.title || 'custom competency'));
                ratingCell.appendChild(rating);
                competencyRow.append(description, evidenceCell, ratingCell);
                customSectionsBody.appendChild(competencyRow);
            });
        });
        customCompetenciesInput.value = '[]';
    }

    async function selectCompetencyTemplate(templateId) {
        templateLoadStatus.textContent = '';
        competencyTemplateIdInput.value = '';
        loadedTemplateMeta = null;
        if (!templateId) {
            renderCustomCompetencies({ sections: {} });
            markEdited();
            return;
        }
        const salaryGrade = salaryGradeSelect.value;
        templateLoadStatus.textContent = 'Loading…';
        try {
            const manageTemplates = IS_ADMIN ? '&manage_templates=1' : '';
            const response = await fetch('template_api.php?template_id=' + encodeURIComponent(templateId) + '&salary_grade=' + encodeURIComponent(salaryGrade) + manageTemplates, { credentials: 'same-origin' });
            const data = await response.json();
            if (!response.ok || data.status !== 'success') {
                throw new Error(data.message || 'Unable to load the selected template.');
            }
            if (salaryGradeSelect.value !== salaryGrade || competencyTemplateSelect.value !== templateId) return;
            const title = String(data.template.title || '');
            const prefix = 'SG ' + salaryGrade + ' - ';
            if (!title.startsWith(prefix)
                || Number(salaryGrade) < Number(data.template.min_salary_grade)
                || Number(salaryGrade) > Number(data.template.max_salary_grade)
                || (Number(data.template.is_shared) !== 1 && !IS_ADMIN)) {
                throw new Error('No scoring form is available for this position. Ask an administrator to generate it.');
            }
            const templatePosition = templatePositionTitle(title, prefix);
            if (IS_ADMIN && currentPosition() === '') positionInput.value = templatePosition;
            if (compactPosition(templatePosition) !== compactPosition(currentPosition())) {
                throw new Error('No scoring form is available for this position. Ask an administrator to generate it.');
            }
            competencyTemplateIdInput.value = String(data.template.id);
            loadedTemplateMeta = data.template;
            renderCustomCompetencies(data.template.layout);
            templateLoadStatus.textContent = '';
            markEdited();
        } catch (error) {
            if (salaryGradeSelect.value !== salaryGrade || competencyTemplateSelect.value !== templateId) return;
            templateLoadStatus.textContent = error.message;
            competencyTemplateSelect.value = '';
            loadedTemplateMeta = null;
            renderCustomCompetencies({ sections: {} });
        }
    }

    function syncCustomCompetencies() {
        const sections = Object.entries(selectedTemplateLayout.sections || {}).filter(([, section]) => section.custom).map(([sectionKey, section]) => ({
            id: sectionKey,
            label: section.label || '',
            level: section.level || '',
            rows: (section.rows || []).map(row => {
                const selector = '[data-section="' + CSS.escape(sectionKey) + '"][data-row-id="' + CSS.escape(row.id) + '"]';
                const evidence = customSectionsBody.querySelector('[data-custom-evidence]' + selector);
                const rating = customSectionsBody.querySelector('[data-custom-rating]' + selector);
                return {
                    id: row.id,
                    title: row.title || '',
                    desc: row.desc || '',
                    evidence: evidence ? evidence.value : '',
                    rating: rating ? rating.value : ''
                };
            })
        }));
        customCompetenciesInput.value = JSON.stringify(sections);
    }

    const draftStatus = document.getElementById('draftSaveStatus');
    const draftUnavailableNotice = document.getElementById('draftUnavailableNotice');
    const resumeDraftModal = document.getElementById('resumeDraftModal');
    let draftDirty = false;
    let draftSaveTimer = null;
    let draftIsReadOnly = false;
    let activeDraftId = null;
    let draftChangeVersion = 0;

    async function draftRequest(action, extra = {}, keepalive = false) {
        const response = await fetch('draft_api.php', {
            method: 'POST',
            credentials: 'same-origin',
            keepalive,
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ action, csrf_token: CSRF_TOKEN, ...extra })
        });
        const data = await response.json();
        if (!response.ok || data.status !== 'success') {
            throw new Error(data.message || 'Could not save this form.');
        }
        return data;
    }

    function collectDraftState() {
        const fields = {};
        document.querySelectorAll('#scoringForm input[name], #scoringForm select[name], #scoringForm textarea[name]')
            .forEach(control => {
                if (['csrf_token', <?php echo json_encode(HONEYPOT_FIELD); ?>].includes(control.name)) return;
                let state;
                if (control instanceof HTMLInputElement
                    && (control.type === 'checkbox' || control.type === 'radio')) {
                    state = { type: control.type, value: control.value, checked: control.checked };
                } else {
                    state = { type: control.tagName.toLowerCase(), value: control.value };
                }
                if (!fields[control.name]) fields[control.name] = [];
                fields[control.name].push(state);
            });
        return {
            fields,
            template_updated_at: loadedTemplateMeta?.updated_at || null
        };
    }

    function formHasUserData() {
        const controls = Array.from(document.querySelectorAll(
            '#scoringForm input[name], #scoringForm select[name], #scoringForm textarea[name]'
        ));
        return controls.some(control => {
            if (control.disabled || ['csrf_token', <?php echo json_encode(HONEYPOT_FIELD); ?>,
                'custom_competencies_json', 'competency_template_id', 'salary_grade', 'lc_heading', 'lc_level',
                'interview_date'].includes(control.name)) return false;
            if (control instanceof HTMLInputElement && (control.type === 'radio' || control.type === 'checkbox')) {
                return control.checked;
            }
            return String(control.value || '').trim() !== '';
        });
    }

    function draftTimestampLabel(value) {
        const date = value ? new Date(String(value).replace(' ', 'T')) : new Date();
        return Number.isNaN(date.getTime()) ? '' : date.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    }

    async function saveDraftNow(force = false, keepalive = false, formJsonOverride = null) {
        if (!CAN_SUBMIT || draftIsReadOnly || (!draftDirty && !force)
            || (formJsonOverride === null && !formHasUserData())) return false;
        const savedVersion = draftChangeVersion;
        const formJson = formJsonOverride ?? JSON.stringify(collectDraftState());
        if (new TextEncoder().encode(formJson).length > 204800) {
            if (draftStatus) draftStatus.textContent = 'Could not save draft';
            return false;
        }
        if (draftStatus) draftStatus.textContent = 'Saving draft…';
        try {
            const result = await draftRequest('save_draft', { form_json: formJson }, keepalive);
            activeDraftId = result.id;
            draftDirty = draftChangeVersion !== savedVersion;
            if (draftDirty && !draftSaveTimer) {
                draftSaveTimer = setTimeout(() => {
                    draftSaveTimer = null;
                    saveDraftNow();
                }, 8000);
            }
            if (draftStatus) draftStatus.textContent = 'Draft saved ' + draftTimestampLabel(result.saved_at);
            return true;
        } catch (error) {
            if (draftStatus) draftStatus.textContent = 'Could not save draft';
            return false;
        }
    }

    async function persistDraftSnapshot(formJson) {
        if (draftSaveTimer) clearTimeout(draftSaveTimer);
        draftSaveTimer = null;
        draftDirty = true;
        const saved = await saveDraftNow(true, false, formJson);
        if (!saved) return false;
        try {
            await draftRequest('mark_saved');
            if (draftStatus) draftStatus.textContent = 'Saved to My recent forms';
            return true;
        } catch (error) {
            if (draftStatus) draftStatus.textContent = 'Could not save recent form';
            return false;
        }
    }

    function scheduleDraftSave() {
        if (!CAN_SUBMIT || draftIsReadOnly) return;
        draftChangeVersion++;
        draftDirty = true;
        if (draftSaveTimer) clearTimeout(draftSaveTimer);
        draftSaveTimer = setTimeout(() => {
            draftSaveTimer = null;
            saveDraftNow();
        }, 8000);
    }

    function restoreDraftFields(formState) {
        if (!formState || !formState.fields) return;
        Object.entries(formState.fields).forEach(([name, savedStates]) => {
            if (['csrf_token', <?php echo json_encode(HONEYPOT_FIELD); ?>, 'custom_competencies_json'].includes(name)) return;
            const controls = Array.from(document.querySelectorAll('#scoringForm [name]'))
                .filter(control => control.name === name);
            if (!controls.length || !Array.isArray(savedStates)) return;
            controls.forEach(control => {
                const state = savedStates.find(item =>
                    (control.type === 'radio' || control.type === 'checkbox')
                        ? item.value === control.value
                        : true
                );
                if (!state) return;
                if (control instanceof HTMLInputElement && (control.type === 'radio' || control.type === 'checkbox')) {
                    control.checked = Boolean(state.checked);
                } else {
                    control.value = state.value == null ? '' : String(state.value);
                }
            });
        });
        updateRaterSummary();
        document.querySelectorAll('#scoringForm input, #scoringForm select, #scoringForm textarea').forEach(control => {
            lastControlStates.set(control, readControlState(control));
        });
    }

    function setSavedFormReadOnly() {
        draftIsReadOnly = true;
        document.body.classList.add('saved-form-readonly');
        document.getElementById('templateControls').hidden = true;
        document.querySelectorAll('#scoringForm input, #scoringForm select, #scoringForm textarea').forEach(control => {
            control.disabled = true;
        });
        ['saveBtn', 'newSheetBtn', 'undoButton'].forEach(id => {
            const button = document.getElementById(id);
            if (button) button.hidden = true;
        });
        document.querySelectorAll('#scoringForm button[type="reset"]').forEach(button => { button.hidden = true; });
        if (draftStatus) draftStatus.textContent = 'Read-only saved form';
    }

    async function restoreStoredForm(data, readOnly) {
        if (!data || !data.form_json) return;
        let state;
        try {
            state = JSON.parse(data.form_json);
        } catch (error) {
            if (templateLoadStatus) templateLoadStatus.textContent = 'This saved form could not be restored.';
            return;
        }

        const savedFields = state.fields || {};
        const savedGrade = savedFields.salary_grade?.[0]?.value;
        if (savedGrade !== undefined && savedGrade !== null && savedGrade !== '') salaryGradeSelect.value = String(savedGrade);
        const savedPosition = savedFields.position_title?.[0]?.value;
        if (savedPosition !== undefined) positionInput.value = String(savedPosition);
        if (!IS_ADMIN && positionInput.value !== '') positionInput.readOnly = true;
        const savedOffice = savedFields.office_name?.[0]?.value;
        if (savedOffice !== undefined) document.getElementById('office_name').value = String(savedOffice);

        if (data.template_available === false) {
            draftUnavailableNotice.hidden = false;
            document.getElementById('discardUnavailableDraftBtn').hidden = readOnly;
            if (templateLoadStatus) templateLoadStatus.textContent = 'This form is no longer available.';
            return;
        }

        if (salaryGradeSelect.value !== '') await loadCompetencyTemplates();
        const templateId = savedFields.competency_template_id?.[0]?.value;
        if (templateId) {
            const optionExists = Array.from(competencyTemplateSelect.options).some(option => option.value === String(templateId));
            if (!optionExists) {
                draftUnavailableNotice.hidden = false;
                document.getElementById('discardUnavailableDraftBtn').hidden = readOnly;
                if (templateLoadStatus) templateLoadStatus.textContent = 'This form is no longer available.';
                return;
            }
            competencyTemplateSelect.value = String(templateId);
            await selectCompetencyTemplate(String(templateId));
            if (!loadedTemplateMeta) {
                draftUnavailableNotice.hidden = false;
                document.getElementById('discardUnavailableDraftBtn').hidden = readOnly;
                return;
            }
        }

        applyFormLayout();
        restoreDraftFields(state);
        if (data.template_changed && templateLoadStatus) {
            templateLoadStatus.textContent = 'This form was updated. Existing matching fields were restored.';
        }
        if (readOnly) setSavedFormReadOnly();
        else {
            activeDraftId = data.draft?.id ?? null;
            draftDirty = false;
        }
        if (new URLSearchParams(window.location.search).get('export_pdf') === '1') {
            setTimeout(() => generateAndDeliverPdf(), 250);
        }
    }

    function showResumePrompt(draft) {
        if (!draft || !resumeDraftModal) return;
        const description = document.getElementById('resumeDraftDescription');
        const edited = draftTimestampLabel(draft.updated_at);
        description.textContent = [
            draft.candidate_name || 'Unnamed candidate',
            draft.position_title || 'Position not selected',
            'Last edited ' + (edited || draft.updated_at)
        ].join(' · ');
        resumeDraftModal.hidden = false;
        document.getElementById('continueDraftBtn').focus();
    }

    document.getElementById('continueDraftBtn').addEventListener('click', () => {
        window.location.href = 'index.php?resume_draft=1';
    });
    document.getElementById('notNowDraftBtn').addEventListener('click', () => {
        resumeDraftModal.hidden = true;
    });
    document.getElementById('discardDraftBtn').addEventListener('click', async () => {
        if (!activeDraftId || !window.confirm('Discard this draft? This cannot be undone.')) return;
        try {
            await draftRequest('discard_draft', { id: activeDraftId });
            activeDraftId = null;
            resumeDraftModal.hidden = true;
            if (draftStatus) draftStatus.textContent = '';
        } catch (error) {
            document.getElementById('resumeDraftDescription').textContent = 'Could not discard the draft. Please try again.';
        }
    });

    document.getElementById('discardUnavailableDraftBtn').addEventListener('click', async () => {
        if (!window.confirm('Discard this draft? This cannot be undone.')) return;
        try {
            await draftRequest('discard_draft', { id: activeDraftId });
            window.location.href = 'sheet_picker.php';
        } catch (error) {
            if (templateLoadStatus) templateLoadStatus.textContent = 'Could not discard the draft. Please try again.';
        }
    });

    document.getElementById('scoringForm').addEventListener('input', scheduleDraftSave);
    document.getElementById('scoringForm').addEventListener('change', scheduleDraftSave);
    document.getElementById('scoringForm').addEventListener('reset', () => {
        if (draftSaveTimer) clearTimeout(draftSaveTimer);
        draftSaveTimer = null;
        if (!activeDraftId || draftIsReadOnly) return;
        draftRequest('discard_draft', { id: activeDraftId }).catch(() => {
            if (draftStatus) draftStatus.textContent = 'Could not discard draft';
        });
        activeDraftId = null;
        draftDirty = false;
    });
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') saveDraftNow(false, true);
    });
    window.addEventListener('pagehide', () => saveDraftNow(false, true));

    async function initializeDraftFeatures() {
        const params = new URLSearchParams(window.location.search);
        const savedId = params.get('saved_id');
        const shouldResume = params.get('resume_draft') === '1';
        if (savedId) {
            const data = await draftRequest('load', { id: savedId });
            activeDraftId = Number(savedId);
            await restoreStoredForm(data, true);
            return;
        }
        if (shouldResume) {
            const data = await draftRequest('get_draft');
            if (data.draft) {
                activeDraftId = data.draft.id;
                await restoreStoredForm(data, false);
            }
            return;
        }
        if (OPENED_WITHOUT_POSITION) {
            const data = await draftRequest('get_draft');
            if (data.draft && data.prompt_allowed) {
                activeDraftId = data.draft.id;
                showResumePrompt(data.draft);
            }
        }
    }

    competencyTemplateSelect.addEventListener('change', () => selectCompetencyTemplate(competencyTemplateSelect.value));
    salaryGradeSelect.addEventListener('change', () => {
        competencyTemplateSelect.value = '';
        competencyTemplateIdInput.value = '';
        loadedTemplateMeta = null;
        templateLoadStatus.textContent = '';
        renderCustomCompetencies({ sections: {} });
        loadCompetencyTemplates().catch(error => {
            templateLoadStatus.textContent = error.message;
        });
    });
    fetch('positions_api.php?action=list&scope=REGIONAL_OFFICE', { credentials: 'same-origin' })
        .then(response => response.ok ? response.json() : null)
        .then(data => {
            if (!data || data.status !== 'success' || !Array.isArray(data.positions)) return;
            data.positions.forEach(position => {
                const fullKey = compactPosition(position.title);
                const baseKey = compactNoParens(position.title);
                const grade = Number(position.salary_grade);
                if (fullKey && Number.isInteger(grade)) POSITION_SG_MAP[fullKey] = grade;
                if (position.title) positionApiTitles.push(position.title);
                if (position.leadership_level === 'B' || position.leadership_level === 'I') {
                    if (fullKey && !(fullKey in LEADERSHIP_LEVEL_MAP)) LEADERSHIP_LEVEL_MAP[fullKey] = position.leadership_level;
                    if (baseKey && !(baseKey in LEADERSHIP_LEVEL_MAP)) LEADERSHIP_LEVEL_MAP[baseKey] = position.leadership_level;
                }
            });
            applyFormLayout();
        })
        .catch(() => {});
    document.getElementById('scoringForm').addEventListener('reset', () => {
        setTimeout(() => {
            if (IS_ADMIN) {
                salaryGradeSelect.value = '';
            } else {
                salaryGradeSelect.value = <?php echo json_encode((int)($initialCandidateGrade ?? 0)); ?>;
            }
            competencyTemplateSelect.value = '';
            competencyTemplateIdInput.value = '';
            loadedTemplateMeta = null;
            templateLoadStatus.textContent = '';
            renderCustomCompetencies({ sections: {} });
            if (!IS_ADMIN && salaryGradeSelect.value !== '') {
                loadCompetencyTemplates().catch(error => {
                    templateLoadStatus.textContent = error.message;
                });
            }
        }, 0);
    });
    document.getElementById('scoringForm').addEventListener('input', event => {
        if (event.target.matches('[data-custom-rating]') && event.target.value !== '') {
            event.target.value = String(Math.min(10, Math.max(0, Number(event.target.value))));
        }
        if (event.target.matches('[data-custom-rating], [data-custom-evidence]')) markEdited();
    });

    applyFormLayout();
    const initialTemplateLoad = salaryGradeSelect.value !== ''
        ? loadCompetencyTemplates()
        : Promise.resolve();
    initialTemplateLoad
        .then(initializeDraftFeatures)
        .catch(error => {
            if (templateLoadStatus) templateLoadStatus.textContent = error.message || 'Could not load this form.';
        });
</script>

</body>
</html>
