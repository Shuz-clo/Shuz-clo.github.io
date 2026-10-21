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

ensure_auth_schema($conn);
purge_expired_tokens($conn);

require_login();
$scoringProfile = find_user_access_profile($conn, (int)current_user_id());
$initialCandidateGrade = $scoringProfile['salary_grade'] ?? null;
if (!is_int($initialCandidateGrade) || $initialCandidateGrade < 0 || $initialCandidateGrade > 25) {
    $initialCandidateGrade = null;
}

if (current_role() === 'admin' && !isset($_GET['template_id'])) {
    header('Location: results_2.php');
    exit;
}

if (current_role() !== 'admin') {
    require_role(['rater']);
}

$canSubmit = in_array(current_role(), ['admin', 'rater'], true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="chrome" content="nofill">
    <title>DENR Interview Scoring Sheet</title>
    <link rel="stylesheet" href="style.css">
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
        @media print { #positionDropdown { display: none !important; } }

        @media print {
            .btn-container, #positionBackBtn, .rate-warn, .template-selection-row { display: none !important; }
            #templateLoadStatus { display: none !important; }
            #raterDropdown > div { display: none !important; }
            #raterSummary::-webkit-details-marker { display: none; }
            #raterSummary { border-bottom: none !important; padding: 0 !important; }
            select { -webkit-appearance: none; -moz-appearance: none; appearance: none; background: none; border: none; }
            input[type="text"]::placeholder { color: transparent; }
        }

        .btn-container { margin-top: 20px; text-align: center; }

        button {
            padding: 8px 18px;
            font-size: 14px;
            font-weight: bold;
            cursor: pointer;
            margin: 0 5px;
        }
        tr.is-hidden { display: none; }
    </style>
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

<form id="scoringForm" action="submit_score.php" method="POST" autocomplete="off">
<input type="text" name="<?php echo HONEYPOT_FIELD; ?>" tabindex="-1" autocomplete="new-password" aria-hidden="true" style="position:absolute; left:-9999px; width:1px; height:1px;">
<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
<input type="hidden" name="custom_competencies_json" id="customCompetenciesJson" value="[]">
<input type="hidden" name="competency_template_id" id="competencyTemplateId" value="">

<table>
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
                       placeholder="Type or pick a position title" required
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
                <option value="Regional Office">Regional Office</option>
            </select>
        </td>
    </tr>
    <tr>
        <td class="info-cell"></td>
        <td colspan="2" class="info-cell" style="padding-bottom: 15px;"><span data-sheet-label="interview_date">Date:</span> <input type="text" name="interview_date" value="<?php echo date('F d, Y'); ?>" autocomplete="off" style="width: 70%; display: inline-block;"></td>
    </tr>
    <tr class="template-selection-row">
        <td class="info-cell"><span data-sheet-label="candidate_salary_grade">Candidate salary grade:</span></td>
        <td colspan="2" class="info-cell">
            <input type="number" name="salary_grade" id="salaryGrade" required
                   min="0" max="25" step="1" inputmode="numeric"
                   value="<?php echo $initialCandidateGrade === null ? '' : $initialCandidateGrade; ?>"
                   placeholder="Enter salary grade (0-25)" style="width: 70%; display: inline-block;">
        </td>
    </tr>
    <tr class="template-selection-row">
        <td class="info-cell"><span data-sheet-label="competency_template">Competency template:</span></td>
        <td colspan="2" class="info-cell">
            <select id="competencyTemplate" style="width: 70%; display: inline-block;">
                <option value="">-- Standard competencies --</option>
            </select>
            <span id="templateLoadStatus" role="status" style="font-size:12px;"></span>
        </td>
    </tr>
    <tr class="sec-core" id="sectionHeader_core">
        <th class="sec-header" id="coreHeadingText">Core Competencies</th>
        <th class="sec-header" id="coreLevelLabel">Evidence</th>
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
        <th class="sec-header" id="orgLevelLabel">Advanced</th>
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
        <th class="sec-header"><input type="text" id="lcHeadingText" value="Leadership Competencies" aria-label="Leadership section heading" maxlength="255" disabled><span id="lcHint" style="display:none; font-size:11px; font-weight:normal; opacity:0.85;"></span></th>
        <th class="sec-header"><input type="text" id="lcLevelLabel" value="Basic" aria-label="Leadership level label" maxlength="100" disabled></th>
        <th class="sec-header"></th>
    </tr>

    <tr class="leadership-row sec-right"><td class="comp-title">LC1 – STRATEGIC LEADERSHIP (THINKING STRATEGICALLY AND CREATIVELY)</td><td></td><td></td></tr>
    <tr class="leadership-row sec-right">
        <td id="lcDesc1">Develops office/ service’s strategies and plans based on the DENR’s mission/vision</td>
        <td><input type="text" name="evidence_lc1" autocomplete="off"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="lc1" placeholder="0.00" autocomplete="off"></td>
    </tr>

    <tr class="leadership-row sec-right"><td class="comp-title">LC2 – LEADING CHANGE</td><td></td><td></td></tr>
    <tr class="leadership-row sec-right">
        <td id="lcDesc2">Effectively delivers messages that require careful planning for the method used and the possible impact of the message (audience may be a large group, i.e., office, organization) Focus of communication is to relay information and to build motivation</td>
        <td><input type="text" name="evidence_lc2" autocomplete="off"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="lc2" placeholder="0.00" autocomplete="off"></td>
    </tr>

    <tr class="leadership-row sec-right"><td class="comp-title">LC3 – PEOPLE DEVELOPMENT (CREATING AND NURTURING A HIGH PERFORMING ORGANIZATION)</td><td></td><td></td></tr>
    <tr class="leadership-row sec-right">
        <td id="lcDesc3">Analyzes appropriateness of office software and equipment in the performance of assigned tasks. Develops information assets to achieve organizational goals</td>
        <td><input type="text" name="evidence_lc3" autocomplete="off"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="lc3" placeholder="0.00" autocomplete="off"></td>
    </tr>

    <tr class="leadership-row sec-right"><td class="comp-title">LC4 – PEOPLE PERFORMANCE MANAGEMENT (MANAGING PERFORMANCE AND COACHING FOR RESULTS)</td><td></td><td></td></tr>
    <tr class="leadership-row sec-right">
        <td id="lcDesc4">Leads in project management activities</td>
        <td><input type="text" name="evidence_lc4" autocomplete="off"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="lc4" placeholder="0.00" autocomplete="off"></td>
    </tr>

    <tr class="leadership-row sec-right"><td class="comp-title">LC5 – PARTNERSHIP AND NETWORKING (BUILDING COLLABORATIVE AND INCLUSIVE WORKING RELATIONSHIPS)</td><td></td><td></td></tr>
    <tr class="leadership-row sec-right">
        <td id="lcDesc5">Leads the practice of CSW in his/her office/unit</td>
        <td><input type="text" name="evidence_lc5" autocomplete="off"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="lc5" placeholder="0.00" autocomplete="off"></td>
    </tr>

    <tr class="sec-right" id="sectionHeader_func">
        <th class="sec-header" id="funcHeadingText">Functional Competencies</th>
        <th class="sec-header" id="funcLevelLabel">Advanced</th>
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
        <td class="transparent" colspan="2"><span data-sheet-label="raters">Raters:</span>
            <details id="raterDropdown" style="position: relative; display: inline-block; width: 60%; vertical-align: middle;">
                <summary id="raterSummary" style="cursor: pointer; border-bottom: 1px solid #000; padding: 4px; list-style: none; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">-- Select Raters --</summary>
                <input type="hidden" name="raters_order" id="ratersOrder" value="">
                <div style="position: absolute; left: 0; top: 100%; z-index: 20; background: #fff; border: 1px solid #999; box-shadow: 0 2px 6px rgba(0,0,0,0.2); padding: 6px 12px; min-width: 200px;">
<?php foreach (rater_list() as $raterLabel): ?>
    <label style="display: block; margin-bottom: 4px; white-space: nowrap;">
        <input type="checkbox" class="rater-check" name="raters[]" value="<?php echo htmlspecialchars($raterLabel); ?>" data-label="<?php echo htmlspecialchars($raterLabel); ?>">
        <?php echo htmlspecialchars($raterLabel); ?>
    </label>
<?php endforeach; ?>
                    <a href="#" onclick="toggleAllRaters(); return false;" style="font-size: 12px; display: inline-block; margin-top: 4px;">Select all / none</a>
                </div>
            </details>
        </td>
    </tr>
</table>

<div class="btn-container">
    <?php if ($canSubmit): ?>
        <button type="button" id="saveBtn" onclick="saveOnly()">Save</button>
    <?php endif; ?>
    <button type="button" onclick="window.print()">Print / Save as PDF</button>
    <button type="button" id="newSheetBtn" onclick="newSheet()" style="display: none;">New Sheet</button>
</div>
</form>

<script>
    const CAN_SUBMIT = <?php echo $canSubmit ? 'true' : 'false'; ?>;

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
        ALL_POSITIONS.forEach(title => { map[posCompact(title)] = title; });
        Object.keys(POSITION_ALIASES).forEach(k => {
            const canonical = POSITION_ALIASES[k];
            if (ALL_POSITIONS.includes(canonical) && !(k in map)) {
                map[k] = canonical;
            }
        });
        return map;
    })();

    function canonicalPositionTitle(typed) {
        const raw = String(typed || '').trim();
        if (raw === '') return '';
        const key = posCompact(raw);
        if (key in POSITION_LOOKUP) return POSITION_LOOKUP[key];
        return raw;
    }

    let activeIndex = -1;

    function renderPositionDropdown(filter) {
        const q = posCompact(filter);
        const matches = ALL_POSITIONS.filter(t => posCompact(t).includes(q));
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

    positionInput.addEventListener('focus', () => renderPositionDropdown(positionInput.value));
    positionInput.addEventListener('input', () => {
        activeIndex = -1;
        renderPositionDropdown(positionInput.value);
        applyFormLayout();
    });

    positionInput.addEventListener('keydown', (e) => {
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

    const LEADERSHIP_LEVELS = [
        ["Planning Officer V", "I"], ["Chief Administrative Officer", "I"], ["Attorney V", "I"],
        ["Development Management Officer V", "I"], ["Engineer V (Geodetic Engineer)", "I"],
        ["Chief Ecosystems Management Specialist", "I"], ["Provincial Environment & Natural Resources Officer", "I"],
        ["Community Environment & Natural Resources Officer", "I"], ["Community Environment & Natural Resources Officer (CENRO)", "I"],
        ["Senior Communications Development Officer", "B"], ["Planning Officer IV", "B"], ["Planning Officer III", "B"],
        ["Project Evaluation Officer III", "B"], ["Information Systems Analyst III", "B"],
        ["Supervising Administrative Officer", "B"], ["Administrative Officer V", "B"],
        ["Administrative Officer V (Budget Officer III)", "B"], ["Administrative Officer V (HRMO III)", "B"],
        ["Administrative Officer V (Cashier III)", "B"], ["Accountant III", "B"],
        ["Attorney IV", "B"], ["Attorney III", "B"], ["Attorney II", "B"],
        ["Development Management Officer IV", "B"], ["Development Management Officer III", "B"],
        ["Engineer IV (Geodetic Engineer)", "B"], ["Engineer III (Geodetic Engineer)", "B"], ["Engineer II (Geodetic Engineer)", "B"],
        ["Forester III", "B"], ["Supervising Ecosystems Management Specialist", "B"],
        ["Senior Ecosystems Management Specialist", "B"], ["Land Management Officer III", "B"]
    ];

    const LEADERSHIP_ALIASES = [
        ["ATY","V","I"], ["ATY","IV","B"], ["ATY","III","B"], ["ATY","II","B"],
        ["ATTY","V","I"], ["ATTY","IV","B"], ["ATTY","III","B"], ["ATTY","II","B"],
        ["DMO","V","I"], ["DMO","IV","B"], ["DMO","III","B"],
        ["ENG","V","I"], ["ENG","IV","B"], ["ENG","III","B"], ["ENG","II","B"],
        ["PLO","V","I"], ["PLO","IV","B"], ["PLO","III","B"],
        ["PEO","III","B"], ["LAMO","III","B"], ["FORST","III","B"], ["INFOSA","III","B"], ["ISA","III","B"],
        ["A","III","B"], ["ADOF","V","B"], ["AO","V","B"],
        ["SADOF","","B"], ["SAO","","B"], ["SVEMS","","B"], ["SREMS","","B"], ["SRCDO","","B"],
        ["CECMS","","I"], ["CADOF","","I"], ["CAO","","I"], ["CENRO","","I"],
        ["PENRO","","I"]
    ];
    const NUMERAL_DIGIT = { II: "2", III: "3", IV: "4", V: "5" };

    const LC_INTERMEDIATE = [
        "Ensures that others support the DENR’s mission, goals, agenda, climate, and policy",
        "Ensures the effective implementation of any change initiatives/programs of the DENR by guiding the team in the transition process",
        "Motivates, trains, coaches and mentors people towards achieving quality results",
        "Monitors employee performance regularly to help employee improve his/her performance",
        "Builds and develops local partnerships and networks and identifies to deliver or enhance work outcomes"
    ];

    function normPosition(s) {
        return String(s || '').replace(/&/g, ' AND ').replace(/\s+/g, ' ').trim().toUpperCase();
    }
    function compactPosition(s) {
        return normPosition(s).replace(/[^\p{L}\p{N}]/gu, '');
    }
    function compactNoParens(s) {
        return compactPosition(String(s || '').replace(/\([^)]*\)/g, ' '));
    }

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

    const LC_BASIC = [1, 2, 3, 4, 5].map(i => document.getElementById('lcDesc' + i).textContent);

    function currentPosition() {
        return positionInput ? positionInput.value.trim() : '';
    }

    function applyFormLayout() {
        for (let i = 1; i <= 5; i++) {
            document.getElementById('lcDesc' + i).textContent = LC_BASIC[i - 1];
        }

        document.querySelectorAll('tr.leadership-row').forEach(row => {
            row.classList.remove('is-hidden');
            row.querySelectorAll('input, select, textarea').forEach(el => {
                el.disabled = false;
            });
        });
        const canEditLabels = canEditTemplateLabels && !!competencyTemplateIdInput.value && !!leadSection;
        leadershipHeadingInput.disabled = !canEditLabels;
        leadershipLevelInput.disabled = !canEditLabels;
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
        setTimeout(() => { positionInput.value = ''; closePositionDropdown(); applyFormLayout(); }, 0);
    });

    let raterClickOrder = [];

    function selectedRaters() {
        return Array.from(document.querySelectorAll('input.rater-check:checked'));
    }

    function syncRaterOrder() {
        const checked = selectedRaters().map(c => c.dataset.label);
        raterClickOrder = raterClickOrder.filter(l => checked.includes(l));
        checked.forEach(l => { if (!raterClickOrder.includes(l)) raterClickOrder.push(l); });
    }

    function updateRaterSummary() {
        syncRaterOrder();
        const summary = document.getElementById('raterSummary');
        summary.textContent = raterClickOrder.length ? raterClickOrder.join(', ') : '-- Select Raters --';
        if (raterClickOrder.length) summary.removeAttribute('data-empty'); else summary.setAttribute('data-empty', '1');
        document.getElementById('ratersOrder').value = raterClickOrder.join(',');
    }

    function toggleAllRaters() {
        const boxes = Array.from(document.querySelectorAll('input.rater-check'));
        const allOn = boxes.every(b => b.checked);
        boxes.forEach(b => { b.checked = !allOn; });
        if (allOn) raterClickOrder = [];
        updateRaterSummary();
    }

    document.querySelectorAll('input.rater-check').forEach(b => b.addEventListener('change', updateRaterSummary));
    document.getElementById('scoringForm').addEventListener('reset', () => setTimeout(() => { raterClickOrder = []; updateRaterSummary(); }, 0));
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

        const form = document.getElementById('scoringForm');
        const btn  = document.getElementById('saveBtn');
        if (!form || !btn) return;

        positionInput.value = canonicalPositionTitle(positionInput.value);

        updateRaterSummary();
        syncCustomCompetencies();
        if (selectedRaters().length === 0) {
            alert('Please select at least one rater.');
            return;
        }

        if (!form.checkValidity()) {
            form.reportValidity();
            return;
        }

        btn.disabled = true;
        let savedOk = false;

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
                alert('Saved successfully! You can now print this sheet, or click "New Sheet" for the next one.');
            } else {
                alert(data.message || 'Failed to save.');
            }
        })
        .catch(err => alert('Error saving data:\n' + err.message))
        .finally(() => { if (!savedOk) btn.disabled = false; });
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
    const canEditTemplateLabels = <?php echo json_encode(current_role() === 'rater'); ?>;
    let selectedTemplateLayout = { sections: {} };
    let leadershipLabelsDirty = false;
    let pendingLeadershipLabelSave = Promise.resolve(true);
    let resumeFormSubmitAfterLabelSave = false;
    let leadershipLabelEditVersion = 0;

    const STD_SECTIONS = ['core', 'org', 'lead', 'func'];
    const LC_BASIC_DEFAULT = LC_BASIC.slice();
    const stdOriginals = {};

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
        const leadSection = sections.lead && sections.lead.standard ? sections.lead : null;
        leadershipHeadingInput.value = leadSection?.label || 'Leadership Competencies';
        leadershipLevelInput.value = leadSection?.level || 'Basic';
        const canEditLabels = canEditTemplateLabels && !!competencyTemplateIdInput.value
            && !!selectedTemplateLayout.sections?.lead?.standard;
        leadershipHeadingInput.disabled = !canEditLabels;
        leadershipLevelInput.disabled = !canEditLabels;
        const fromTemplate = {};
        let usesTemplate = false;
        STD_SECTIONS.forEach(key => {
            if (!sections[key]) return;
            usesTemplate = true;
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
            const response = await fetch('sheets_api.php', {
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
        if (resumeFormSubmitAfterLabelSave || !leadershipLabelsDirty) return;
        event.preventDefault();
        const form = event.currentTarget;
        const saved = await pendingLeadershipLabelSave;
        if (!saved) return;
        resumeFormSubmitAfterLabelSave = true;
        if (event.submitter) form.requestSubmit(event.submitter);
        else form.requestSubmit();
    });

    async function loadCompetencyTemplates() {
        const salaryGrade = salaryGradeSelect.value;
        competencyTemplateSelect.replaceChildren(new Option('-- Standard competencies --', ''));
        if (salaryGrade === '') return;
        const response = await fetch('sheets_api.php?action=template_list&salary_grade=' + encodeURIComponent(salaryGrade), { credentials: 'same-origin' });
        const data = await response.json();
        if (!response.ok || data.status !== 'success') {
            throw new Error(data.message || 'Unable to load competency templates.');
        }
        if (salaryGradeSelect.value !== salaryGrade) return;
        if (data.templates.length === 0) {
            templateLoadStatus.textContent = 'No shared sheets match your region, field, and both your profile and candidate salary grades. Check that the sheet is shared with raters and its grade range includes both grades.';
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
        }
    }

    function renderCustomCompetencies(layout) {
        selectedTemplateLayout = layout && layout.sections ? layout : { sections: {} };
        applyStandardRows(selectedTemplateLayout);
        customSectionsBody.replaceChildren();
        Object.entries(selectedTemplateLayout.sections).forEach(([sectionKey, section]) => {
            if (!section || !section.custom) return;
            const header = document.createElement('tr');
            header.className = 'custom-section-row';
            const sectionTitle = document.createElement('th');
            sectionTitle.className = 'sec-header';
            sectionTitle.textContent = section.label || 'Untitled section';
            const sectionLevel = document.createElement('th');
            sectionLevel.className = 'sec-header';
            sectionLevel.textContent = section.level || 'Advanced';
            const ratingHeading = document.createElement('th');
            ratingHeading.className = 'sec-header';
            ratingHeading.textContent = 'Rating';
            header.append(sectionTitle, sectionLevel, ratingHeading);
            customSectionsBody.appendChild(header);

            (section.rows || []).forEach(row => {
                if ((row.title || '').trim() !== '') {
                    const titleRow = document.createElement('tr');
                    titleRow.className = 'custom-section-row';
                    const titleCell = document.createElement('td');
                    titleCell.className = 'comp-title';
                    titleCell.textContent = row.title;
                    titleRow.append(titleCell, document.createElement('td'), document.createElement('td'));
                    customSectionsBody.appendChild(titleRow);
                }

                const competencyRow = document.createElement('tr');
                competencyRow.className = 'custom-section-row';
                const description = document.createElement('td');
                description.textContent = row.desc || '';
                const evidenceCell = document.createElement('td');
                const evidence = document.createElement('input');
                evidence.type = 'text';
                evidence.autocomplete = 'off';
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
        if (!templateId) {
            renderCustomCompetencies({ sections: {} });
            markEdited();
            return;
        }
        const salaryGrade = salaryGradeSelect.value;
        templateLoadStatus.textContent = 'Loading…';
        try {
            const response = await fetch('sheets_api.php?template_id=' + encodeURIComponent(templateId) + '&salary_grade=' + encodeURIComponent(salaryGrade), { credentials: 'same-origin' });
            const data = await response.json();
            if (!response.ok || data.status !== 'success') {
                throw new Error(data.message || 'Unable to load the selected template.');
            }
            if (salaryGradeSelect.value !== salaryGrade || competencyTemplateSelect.value !== templateId) return;
            competencyTemplateIdInput.value = String(data.template.id);
            renderCustomCompetencies(data.template.layout);
            templateLoadStatus.textContent = '';
            markEdited();
        } catch (error) {
            if (salaryGradeSelect.value !== salaryGrade || competencyTemplateSelect.value !== templateId) return;
            templateLoadStatus.textContent = error.message;
            competencyTemplateSelect.value = '';
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

    competencyTemplateSelect.addEventListener('change', () => selectCompetencyTemplate(competencyTemplateSelect.value));
    salaryGradeSelect.addEventListener('change', () => {
        competencyTemplateSelect.value = '';
        competencyTemplateIdInput.value = '';
        templateLoadStatus.textContent = '';
        renderCustomCompetencies({ sections: {} });
        loadCompetencyTemplates().catch(error => {
            templateLoadStatus.textContent = error.message;
        });
    });
    document.getElementById('scoringForm').addEventListener('reset', () => {
        setTimeout(() => {
            salaryGradeSelect.value = '';
            competencyTemplateSelect.value = '';
            competencyTemplateIdInput.value = '';
            templateLoadStatus.textContent = '';
            renderCustomCompetencies({ sections: {} });
        }, 0);
    });
    document.getElementById('scoringForm').addEventListener('input', event => {
        if (event.target.matches('[data-custom-rating]') && event.target.value !== '') {
            event.target.value = String(Math.min(10, Math.max(0, Number(event.target.value))));
        }
        if (event.target.matches('[data-custom-rating], [data-custom-evidence]')) markEdited();
    });

    applyFormLayout();
    if (salaryGradeSelect.value !== '') {
        loadCompetencyTemplates().catch(error => {
            templateLoadStatus.textContent = error.message;
        });
    }
</script>

</body>
</html>
