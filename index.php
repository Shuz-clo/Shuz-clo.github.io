<?php
require_once 'db.php';
require_once __DIR__ . '/AuthSchema.php';
require_once 'auth.php';
require_once __DIR__ . '/Raters.php';
require_once __DIR__ . '/config.php';

ensure_auth_schema($conn);
purge_expired_tokens($conn);

require_login();

if (current_role() === 'admin') {
    header('Location: results_2.php');
    exit;
}

require_role(['rater', 'user']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DENR Interview Scoring Sheet</title>
    <link rel="stylesheet" href="style.css"><?php
require_once 'db.php';
require_once __DIR__ . '/AuthSchema.php';
require_once 'auth.php';
require_once __DIR__ . '/Raters.php';
require_once __DIR__ . '/config.php';

ensure_auth_schema($conn);
purge_expired_tokens($conn);

// Must be logged in (anonymous visitors are sent to login.php)
require_login();

// Admins manage results from results_2.php, not this form.
if (current_role() === 'admin') {
    header('Location: results_2.php');
    exit;
}

// Only raters and standard users may use the scoring form
require_role(['rater', 'user']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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

        .header-cell {
            border: none !important;
            text-align: center;
            font-size: 13px;
            padding: 2px 0;
        }

        .header-title {
            border: none !important;
            text-align: center;
            font-weight: bold;
            font-size: 14px;
            padding: 10px 0;
        }

        .info-cell {
            border: none !important;
            padding: 4px 0;
            font-size: 13px;
        }

        input[type="text"], input[type="number"], select {
            width: 100%;
            padding: 4px;
            box-sizing: border-box;
            border: none;
            outline: none;
            background: transparent;
            font-family: inherit;
            font-size: inherit;
            color: #000;
        }

        input[type="number"] {
            text-align: center;
            font-weight: bold;
        }

        .sec-header {
            background-color: #d9d9d9 !important;
            font-weight: bold !important;
            text-align: center !important;
            vertical-align: middle !important;
        }

        .ev-label {
            background-color: #d9d9d9 !important;
            font-weight: bold !important;
            text-align: center !important;
        }

        .comp-title {
            font-weight: bold;
            background-color: #fff;
        }

        .transparent {
            border: none !important;
            padding-top: 15px;
        }

        .secret-trigger {
            user-select: none;
            cursor: default;
        }

        /* --- Position autocomplete --- */
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
            top: 100%;
            left: 0;
            right: 0;
            z-index: 30;
            max-height: 260px;
            overflow-y: auto;
            background: #fff;
            border: 1px solid #999;
            box-shadow: 0 2px 8px rgba(0,0,0,0.18);
        }
        #positionDropdown.open { display: block; }
        #positionDropdown .pd-item {
            padding: 5px 8px;
            font-size: 12px;
            cursor: pointer;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        #positionDropdown .pd-item:hover,
        #positionDropdown .pd-item.active {
            background: #107c41;
            color: #fff;
        }
        #positionDropdown .pd-empty {
            padding: 6px 8px;
            font-size: 12px;
            color: #777;
            font-style: italic;
        }
        @media print {
            #positionDropdown { display: none !important; }
        }

        @media print {
            .btn-container, #positionBackBtn { display: none !important; }
            #raterDropdown > div { display: none !important; }
            #raterSummary::-webkit-details-marker { display: none; }
            #raterSummary { border-bottom: none !important; padding: 0 !important; }
            select { -webkit-appearance: none; -moz-appearance: none; appearance: none; background: none; border: none; }
            input[type="text"]::placeholder { color: transparent; }
        }

        .btn-container {
            margin-top: 20px;
            text-align: center;
        }

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

<?php if ($flashError = flash_get('error')): ?>
<div class="user-bar" style="background:#fdecea;border:1px solid #f5c2c0;color:#7a1f16;padding:8px 12px;border-radius:4px;">
    <?php echo htmlspecialchars($flashError); ?>
</div>
<?php endif; ?>

<form id="scoringForm" action="submit_score.php" method="POST">
<!-- Honeypot (bots fill this, humans never see it) -->
<input type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute; left:-9999px; width:1px; height:1px;">
<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">

<table>
    <colgroup>
        <col class="col-desc">
        <col class="col-ev">
        <col class="col-rate">
    </colgroup>

    <!-- Top Branding Headers -->
    <tr><td colspan="3" class="header-cell">Department of Environment and Natural Resources</td></tr>
    <tr><td colspan="3" class="header-cell">Region VI, Western Visayas</td></tr>
    <tr><td colspan="3" class="header-cell">Regional Human Resource Merit Promotion and Selection Board (RHRMPSB)</td></tr>

    <!-- Alt + Double-Click Trigger on INTERVIEW SCORING SHEET -->
    <tr><td colspan="3" class="header-title secret-trigger" ondblclick="goToAdminResults(event)">INTERVIEW SCORING SHEET</td></tr>

    <!-- Candidate Info -->
    <tr>
        <td class="info-cell">NAME: <input type="text" name="candidate_name" placeholder="" required style="width: 75%; display: inline-block;"></td>
        <td colspan="2" class="info-cell">Position:
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
        <td colspan="2" class="info-cell">Office:
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
        <td colspan="2" class="info-cell" style="padding-bottom: 15px;">Date: <input type="text" name="interview_date" value="<?php echo date('F d, Y'); ?>" style="width: 70%; display: inline-block;"></td>
    </tr>

    <!-- ===== Core Competencies ===== -->
    <tr class="sec-core">
        <th class="sec-header">Core Competencies</th>
        <th class="sec-header">Evidence</th>
        <th class="sec-header">Rating</th>
    </tr>

    <tr class="sec-core">
        <td class="comp-title">CC1 - DISCIPLINE</td>
        <td class="ev-label">Advanced</td>
        <td></td>
    </tr>
    <tr class="sec-core">
        <td>Serves as a good role model on DENR values and ethics to staff/peers</td>
        <td><input type="text" name="evidence_cc1"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="cc1" placeholder="0.00"></td>
    </tr>

    <tr class="sec-core">
        <td class="comp-title">CC2 - EXCELLENCE</td>
        <td></td>
        <td></td>
    </tr>
    <tr class="sec-core">
        <td>Anticipates, identifies and manages stakeholders’ standards and requirements towards excellent customer service through improving sense of responsibility and competence</td>
        <td><input type="text" name="evidence_cc2"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="cc2" placeholder="0.00"></td>
    </tr>

    <tr class="sec-core">
        <td class="comp-title">CC3 - NOBILITY</td>
        <td></td>
        <td></td>
    </tr>
    <tr class="sec-core">
        <td>Influences others to observe virtue, goodness, honor, justness and decency in all situations</td>
        <td><input type="text" name="evidence_cc3"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="cc3" placeholder="0.00"></td>
    </tr>

    <tr class="sec-core">
        <td class="comp-title">CC4 - RESPONSIBILITY</td>
        <td></td>
        <td></td>
    </tr>
    <tr class="sec-core">
        <td>Leads in the observance of the principle of transparency and accountability in the workplace</td>
        <td><input type="text" name="evidence_cc4"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="cc4" placeholder="0.00"></td>
    </tr>

    <tr class="sec-core">
        <td class="comp-title">CC5 - CARING FOR THE ENVIRONMENT AND NATURAL RESOURCES</td>
        <td></td>
        <td></td>
    </tr>
    <tr class="sec-core">
        <td>Serves as a good role model in conserving and preserving the environment to peers and staff</td>
        <td><input type="text" name="evidence_cc5"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="cc5" placeholder="0.00"></td>
    </tr>

    <!-- ===== Organizational Competencies ===== -->
    <tr class="sec-right">
        <th class="sec-header">Organizational Competencies</th>
        <th class="sec-header">Advanced</th>
        <th class="sec-header"></th>
    </tr>

    <tr class="sec-right">
        <td class="comp-title">OC1 - WRITING EFFECTIVELY</td>
        <td></td>
        <td></td>
    </tr>
    <tr class="sec-right">
        <td>Produces written work from scratch with some guidance while complying to agreed or prescribed standards of communicating with the bureaucracy</td>
        <td><input type="text" name="evidence_oc1"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="oc1" placeholder="0.00"></td>
    </tr>

    <tr class="sec-right">
        <td class="comp-title">OC2 - SPEAKING EFFECTIVELY</td>
        <td></td>
        <td></td>
    </tr>
    <tr class="sec-right">
        <td>Effectively delivers messages that require careful planning for the method used and the possible impact of the message (audience may be a large group, i.e., office, organization) Focus of communication is to relay information and to build motivation</td>
        <td><input type="text" name="evidence_oc2"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="oc2" placeholder="0.00"></td>
    </tr>

    <tr class="sec-right">
        <td class="comp-title">OC3 - TECHNOLOGY LITERACY AND MANAGING INFORMATION</td>
        <td></td>
        <td></td>
    </tr>
    <tr class="sec-right">
        <td>Analyzes appropriateness of office software and equipment in the performance of the assigned tasks. Develops information assets to achieve organizational goals</td>
        <td><input type="text" name="evidence_oc3"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="oc3" placeholder="0.00"></td>
    </tr>

    <tr class="sec-right">
        <td class="comp-title">OC4 - PROJECT MANAGEMENT</td>
        <td></td>
        <td></td>
    </tr>
    <tr class="sec-right">
        <td>Leads in project management activities</td>
        <td><input type="text" name="evidence_oc4"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="oc4" placeholder="0.00"></td>
    </tr>

    <tr class="sec-right">
        <td class="comp-title">OC5 - COMPLETE STAFF WORK (CSW)</td>
        <td></td>
        <td></td>
    </tr>
    <tr class="sec-right">
        <td>Leads the practice of CSW in his/her office/unit</td>
        <td><input type="text" name="evidence_oc5"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="oc5" placeholder="0.00"></td>
    </tr>

    <!-- ===== Leadership Competencies ===== -->
    <tr class="leadership-row sec-right">
        <th class="sec-header"><span id="lcHeadingText">Leadership Competencies</span><span id="lcHint" style="display:none; font-size:11px; font-weight:normal; opacity:0.85;"></span></th>
        <th class="sec-header" id="lcLevelLabel">Basic</th>
        <th class="sec-header"></th>
    </tr>

    <tr class="leadership-row sec-right">
        <td class="comp-title">LC1 – STRATEGIC LEADERSHIP (THINKING STRATEGICALLY AND CREATIVELY)</td>
        <td></td>
        <td></td>
    </tr>
    <tr class="leadership-row sec-right">
        <td id="lcDesc1">Develops office/ service’s strategies and plans based on the DENR’s mission/vision</td>
        <td><input type="text" name="evidence_lc1"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="lc1" placeholder="0.00"></td>
    </tr>

    <tr class="leadership-row sec-right">
        <td class="comp-title">LC2 – LEADING CHANGE</td>
        <td></td>
        <td></td>
    </tr>
    <tr class="leadership-row sec-right">
        <td id="lcDesc2">Effectively delivers messages that require careful planning for the method used and the possible impact of the message (audience may be a large group, i.e., office, organization) Focus of communication is to relay information and to build motivation</td>
        <td><input type="text" name="evidence_lc2"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="lc2" placeholder="0.00"></td>
    </tr>

    <tr class="leadership-row sec-right">
        <td class="comp-title">LC3 – PEOPLE DEVELOPMENT (CREATING AND NURTURING A HIGH PERFORMING ORGANIZATION)</td>
        <td></td>
        <td></td>
    </tr>
    <tr class="leadership-row sec-right">
        <td id="lcDesc3">Analyzes appropriateness of office software and equipment in the performance of assigned tasks. Develops information assets to achieve organizational goals</td>
        <td><input type="text" name="evidence_lc3"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="lc3" placeholder="0.00"></td>
    </tr>

    <tr class="leadership-row sec-right">
        <td class="comp-title">LC4 – PEOPLE PERFORMANCE MANAGEMENT (MANAGING PERFORMANCE AND COACHING FOR RESULTS)</td>
        <td></td>
        <td></td>
    </tr>
    <tr class="leadership-row sec-right">
        <td id="lcDesc4">Leads in project management activities</td>
        <td><input type="text" name="evidence_lc4"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="lc4" placeholder="0.00"></td>
    </tr>

    <tr class="leadership-row sec-right">
        <td class="comp-title">LC5 – PARTNERSHIP AND NETWORKING (BUILDING COLLABORATIVE AND INCLUSIVE WORKING RELATIONSHIPS)</td>
        <td></td>
        <td></td>
    </tr>
    <tr class="leadership-row sec-right">
        <td id="lcDesc5">Leads the practice of CSW in his/her office/unit</td>
        <td><input type="text" name="evidence_lc5"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="lc5" placeholder="0.00"></td>
    </tr>

    <!-- ===== Functional Competencies ===== -->
    <tr class="sec-right">
        <th class="sec-header">Functional Competencies</th>
        <th class="sec-header">Advanced</th>
        <th class="sec-header"></th>
    </tr>

    <tr class="sec-right">
        <td class="comp-title">PCP1 – PLANNING AND PROGRAMMING</td>
        <td></td>
        <td></td>
    </tr>
    <tr class="sec-right">
        <td>Organizes the preparation of the PENRO operational plans</td>
        <td><input type="text" name="evidence_pcp1"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="pcp1" placeholder="0.00"></td>
    </tr>

    <tr class="sec-right">
        <td class="comp-title">PCP2 – MONITORING AND EVALUATION</td>
        <td></td>
        <td></td>
    </tr>
    <tr class="sec-right">
        <td>Leads the preparation of monitoring and evaluation reports of all DENR-PENRO programs and projects</td>
        <td><input type="text" name="evidence_pcp2"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="pcp2" placeholder="0.00"></td>
    </tr>

    <tr class="sec-right">
        <td class="comp-title">PCP3 – STATISTICAL COORDINATION AND DATA RESEARCH</td>
        <td></td>
        <td></td>
    </tr>
    <tr class="sec-right">
        <td>Leads the characterization of ecosystem and use of planning tools and procedures</td>
        <td><input type="text" name="evidence_pcp3"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="pcp3" placeholder="0.00"></td>
    </tr>

    <tr class="sec-right">
        <td class="comp-title">PCO4 – RESOURCE MANAGEMENT AND RESTORATION/ REHABILITATION OF DEGRADED ECOSYSTEMS</td>
        <td></td>
        <td></td>
    </tr>
    <tr class="sec-right">
        <td>Leads the conduct of statistical coordination and data research activities</td>
        <td><input type="text" name="evidence_pco4"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="pco4" placeholder="0.00"></td>
    </tr>

    <!-- Footer -->
    <tr>
        <td class="transparent">Rated by: <input type="text" name="rater_name" placeholder="Rater Name" style="width: 70%; display: inline-block;"></td>
        <td class="transparent" colspan="2">Raters:
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
    <button type="button" id="saveBtn" onclick="saveOnly()">Save</button>
    <button type="button" onclick="window.print()">Print / Save as PDF</button>
    <button type="button" id="newSheetBtn" onclick="newSheet()" style="display: none;">New Sheet</button>
</div>
</form>

<script>
    // =========================================================================
    // ALL_POSITIONS: every position title that appears in the two plantilla
    // Excel files (POSITIONS_COMPETENCY LEVEL.xlsx, Interview Rating_2026.xlsx).
    // The "(scrap & build)", "(Continued service accredited by CSC)" and
    // similar vacancy artifacts are intentionally stripped out — those aren't
    // positions you can actually interview for.
    // Edit this one array to add/remove positions; the dropdown and the
    // canonical lookup both read from it.
    // =========================================================================
    const ALL_POSITIONS = [
        "Accountant I",
        "Accountant II",
        "Accountant III",
        "Accounting Analyst",
        "Administrative Aide I",
        "Administrative Aide III",
        "Administrative Aide IV",
        "Administrative Aide VI",
        "Administrative Aide VI (Driver II)",
        "Administrative Assistant I",
        "Administrative Assistant I (Computer Operator I)",
        "Administrative Assistant II",
        "Administrative Assistant II (Bookkeeper)",
        "Administrative Assistant II (Budgeting Asst.)",
        "Administrative Assistant II (Senior Bookkeeper)",
        "Administrative Assistant III",
        "Administrative Assistant III (Computer Operator II)",
        "Administrative Assistant III (Senior Bookkeeper)",
        "Administrative Officer I",
        "Administrative Officer I (Cashier I)",
        "Administrative Officer I (Records Officer I)",
        "Administrative Officer I (Supply Officer I)",
        "Administrative Officer II",
        "Administrative Officer II (Budget Officer I)",
        "Administrative Officer III",
        "Administrative Officer IV",
        "Administrative Officer IV (Budget Officer II)",
        "Administrative Officer IV (HRMO II)",
        "Administrative Officer V",
        "Administrative Officer V (Budget Officer III)",
        "Administrative Officer V (Cashier III)",
        "Administrative Officer V (HRMO III)",
        "Attorney II",
        "Attorney III",
        "Attorney IV",
        "Attorney V",
        "Cartographer I",
        "Cartographer II",
        "Cartographer III",
        "Cartographer IV",
        "Chief Administrative Officer",
        "Chief Ecosystems Management Specialist",
        "Communication Development Officer I",
        "Communication Development Officer II",
        "Community Development Assistant II",
        "Community Development Officer II",
        "Community Environment & Natural Resources Officer",
        "Community Environment & Natural Resources Officer (CENRO)",
        "Credit Officer I",
        "Development Management Officer I",
        "Development Management Officer II",
        "Development Management Officer III",
        "Development Management Officer IV",
        "Development Management Officer V",
        "Draftsman I",
        "Draftsman II",
        "Ecosystems Management Specialist I",
        "Ecosystems Management Specialist II",
        "Engineer I (Geodetic Engineer)",
        "Engineer II (Geodetic Engineer)",
        "Engineer III (Geodetic Engineer)",
        "Engineer IV (Geodetic Engineer)",
        "Engineer V (Geodetic Engineer)",
        "Engineering Aide",
        "Forest Ranger",
        "Forest Technician I",
        "Forest Technician II",
        "Forester I",
        "Forester II",
        "Forester III",
        "Information Officer I",
        "Information Officer II",
        "Information Systems Analyst I",
        "Information Systems Analyst II",
        "Information Systems Analyst III",
        "Laborer I",
        "Land Management Inspector",
        "Land Management Officer I",
        "Land Management Officer II",
        "Land Management Officer III",
        "Legal Assistant II",
        "Mathematician Aide I",
        "Mathematician Aide II",
        "Mathematician I",
        "Park Attendant II",
        "Park Maintenance Foreman",
        "Park Operation Superintendent IV",
        "Park Operation Superintendent V",
        "Planning Officer I",
        "Planning Officer II",
        "Planning Officer III",
        "Planning Officer IV",
        "Planning Officer V",
        "Project Evaluation Officer I",
        "Project Evaluation Officer II",
        "Project Evaluation Officer III",
        "Provincial Environment & Natural Resources Officer",
        "Scaler",
        "Senior Communications Development Officer",
        "Senior Ecosystems Management Specialist",
        "Special Investigator I",
        "Statistician I",
        "Statistician II",
        "Supervising Administrative Officer",
        "Supervising Ecosystems Management Specialist",
        "Tracer",
        "Tree Marker",
        "Utility Worker I",
        "Zoology Technician"
    ];

    // =========================================================================
    // Position autocomplete
    // =========================================================================
    const positionInput    = document.getElementById('position_input');
    const positionDropdown = document.getElementById('positionDropdown');

    // Letters + digits only, uppercase. Used to match "plo iii" == "PLO III" == "PLOIII".
    function posCompact(s) {
        return String(s || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
    }

    // Short forms people commonly type -> canonical title from ALL_POSITIONS.
    // (Any typed variant that doesn't match one of these still gets stored as-is.)
    const POSITION_ALIASES = {
        'ATY5': 'Attorney V', 'ATTY5': 'Attorney V', 'ATTORNEYV': 'Attorney V',
        'ATY4': 'Attorney IV', 'ATTY4': 'Attorney IV', 'ATTORNEYIV': 'Attorney IV',
        'ATY3': 'Attorney III', 'ATTY3': 'Attorney III', 'ATTORNEYIII': 'Attorney III',
        'ATY2': 'Attorney II', 'ATTY2': 'Attorney II', 'ATTORNEYII': 'Attorney II',
        'PLO1': 'Planning Officer I', 'PLO2': 'Planning Officer II',
        'PLO3': 'Planning Officer III', 'PLO4': 'Planning Officer IV', 'PLO5': 'Planning Officer V',
        'PEO1': 'Project Evaluation Officer I',
        'PEO2': 'Project Evaluation Officer II',
        'PEO3': 'Project Evaluation Officer III',
        'DMO1': 'Development Management Officer I',
        'DMO2': 'Development Management Officer II',
        'DMO3': 'Development Management Officer III',
        'DMO4': 'Development Management Officer IV',
        'DMO5': 'Development Management Officer V',
        'ENG1': 'Engineer I (Geodetic Engineer)',
        'ENG2': 'Engineer II (Geodetic Engineer)',
        'ENG3': 'Engineer III (Geodetic Engineer)',
        'ENG4': 'Engineer IV (Geodetic Engineer)',
        'ENG5': 'Engineer V (Geodetic Engineer)',
        'FOR1': 'Forester I', 'FORESTER1': 'Forester I',
        'FOR2': 'Forester II', 'FORESTER2': 'Forester II',
        'FOR3': 'Forester III', 'FORESTER3': 'Forester III',
        'FORST1': 'Forester I', 'FORST2': 'Forester II', 'FORST3': 'Forester III',
        'FT1': 'Forest Technician I', 'FT2': 'Forest Technician II',
        'LMO1': 'Land Management Officer I',
        'LMO2': 'Land Management Officer II',
        'LMO3': 'Land Management Officer III',
        'LAMI': 'Land Management Inspector',
        'ADOF1': 'Administrative Officer I',
        'ADOF2': 'Administrative Officer II',
        'ADOF3': 'Administrative Officer III',
        'ADOF4': 'Administrative Officer IV',
        'ADOF5': 'Administrative Officer V',
        'AO1': 'Administrative Officer I',
        'AO2': 'Administrative Officer II',
        'AO3': 'Administrative Officer III',
        'AO4': 'Administrative Officer IV',
        'AO5': 'Administrative Officer V',
        'ADAS1': 'Administrative Assistant I',
        'ADAS2': 'Administrative Assistant II',
        'ADAS3': 'Administrative Assistant III',
        'ADA1': 'Administrative Aide I',
        'ADA3': 'Administrative Aide III',
        'ADA4': 'Administrative Aide IV',
        'ADA6': 'Administrative Aide VI',
        'PENRO': 'Provincial Environment & Natural Resources Officer',
        'CENRO': 'Community Environment & Natural Resources Officer',
        'SVEMS': 'Supervising Ecosystems Management Specialist',
        'SREMS': 'Senior Ecosystems Management Specialist',
        'EMS1': 'Ecosystems Management Specialist I',
        'EMS2': 'Ecosystems Management Specialist II',
        'ECOMS1': 'Ecosystems Management Specialist I',
        'ECOMS2': 'Ecosystems Management Specialist II',
        'CECMS': 'Chief Ecosystems Management Specialist',
        'CADOF': 'Chief Administrative Officer',
        'SAO': 'Supervising Administrative Officer',
        'SADOF': 'Supervising Administrative Officer',
        'SRCDO': 'Senior Communications Development Officer',
        'LAMO1': 'Land Management Officer I',
        'LAMO2': 'Land Management Officer II',
        'LAMO3': 'Land Management Officer III',
        'INFOSA1': 'Information Systems Analyst I',
        'INFOSA2': 'Information Systems Analyst II',
        'INFOSA3': 'Information Systems Analyst III',
        'CGR1': 'Cartographer I', 'CGR2': 'Cartographer II',
        'CGR3': 'Cartographer III', 'CGR4': 'Cartographer IV',
        'CARTO1': 'Cartographer I', 'CARTO2': 'Cartographer II',
        'CARTO3': 'Cartographer III', 'CARTO4': 'Cartographer IV'
    };

    // Build a lookup keyed by compact form, so the user's typed variant resolves
    // to the exact title from ALL_POSITIONS when it's recognisable.
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

    // "atty 5", "ATTY-5", "atty5" all become "ATTY5" -> look up -> "Attorney V".
    // A title that isn't in the list is returned unchanged.
    function canonicalPositionTitle(typed) {
        const raw = String(typed || '').trim();
        if (raw === '') return '';
        const key = posCompact(raw);
        if (key in POSITION_LOOKUP) return POSITION_LOOKUP[key];
        return raw;
    }

    // ---- Dropdown behaviour ----
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
                // mousedown fires before blur; preventDefault keeps the input focused.
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
                // No highlight: accept whatever was typed (canonicalise if possible)
                positionInput.value = canonicalPositionTitle(positionInput.value);
                closePositionDropdown();
                applyFormLayout();
            }
        } else if (e.key === 'Escape') {
            closePositionDropdown();
        }
    });

    // Snap to the exact canonical title when the user leaves the field.
    positionInput.addEventListener('blur', () => {
        // Delay so a click on a dropdown item still registers.
        setTimeout(() => {
            if (!positionDropdown.contains(document.activeElement)) {
                positionInput.value = canonicalPositionTitle(positionInput.value);
                closePositionDropdown();
                applyFormLayout();
            }
        }, 120);
    });

    // Close on outside click
    document.addEventListener('click', (e) => {
        if (!positionDropdown.contains(e.target) && e.target !== positionInput) {
            closePositionDropdown();
        }
    });

    // =========================================================================
    // Leadership level rules
    // =========================================================================
    const LEADERSHIP_HIDDEN_POSITIONS = ["SVEMS"];

    const LEADERSHIP_LEVELS = [
        // INTERMEDIATE
        ["Planning Officer V", "I"],
        ["Chief Administrative Officer", "I"],
        ["Attorney V", "I"],
        ["Development Management Officer V", "I"],
        ["Engineer V (Geodetic Engineer)", "I"],
        ["Chief Ecosystems Management Specialist", "I"],
        ["Provincial Environment & Natural Resources Officer", "I"],
        ["Community Environment & Natural Resources Officer", "I"],
        ["Community Environment & Natural Resources Officer (CENRO)", "I"],
        // BASIC
        ["Senior Communications Development Officer", "B"],
        ["Planning Officer IV", "B"],
        ["Planning Officer III", "B"],
        ["Project Evaluation Officer III", "B"],
        ["Information Systems Analyst III", "B"],
        ["Supervising Administrative Officer", "B"],
        ["Administrative Officer V", "B"],
        ["Administrative Officer V (Budget Officer III)", "B"],
        ["Administrative Officer V (HRMO III)", "B"],
        ["Administrative Officer V (Cashier III)", "B"],
        ["Accountant III", "B"],
        ["Attorney IV", "B"],
        ["Attorney III", "B"],
        ["Attorney II", "B"],
        ["Development Management Officer IV", "B"],
        ["Development Management Officer III", "B"],
        ["Engineer IV (Geodetic Engineer)", "B"],
        ["Engineer III (Geodetic Engineer)", "B"],
        ["Engineer II (Geodetic Engineer)", "B"],
        ["Forester III", "B"],
        ["Supervising Ecosystems Management Specialist", "B"],
        ["Senior Ecosystems Management Specialist", "B"],
        ["Land Management Officer III", "B"]
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
        const position = currentPosition();
        const level = leadershipLevelFor(position);
        const noLeadership = (position === '') || LEADERSHIP_HIDDEN_POSITIONS.includes(position.toUpperCase()) || (level === null);

        const intermediate = (level === 'I');
        document.getElementById('lcHeadingText').textContent = intermediate ? 'Leadership Competencies' : 'Leadership Competencies';
        document.getElementById('lcLevelLabel').textContent = intermediate ? 'Advanced' : 'Basic';
        for (let i = 1; i <= 5; i++) {
            document.getElementById('lcDesc' + i).textContent = intermediate ? LC_INTERMEDIATE[i - 1] : LC_BASIC[i - 1];
        }

        document.querySelectorAll('tr.leadership-row').forEach(row => {
            row.classList.toggle('is-hidden', noLeadership);
            row.querySelectorAll('input, select, textarea').forEach(el => {
                if (noLeadership) el.value = '';
                el.disabled = noLeadership;
            });
        });
        if (typeof markEdited === 'function') markEdited();
    }

    // =========================================================================
    // Rating cells: clamp to 0..10 while typing
    // =========================================================================
    document.getElementById('scoringForm').addEventListener('input', e => {
        const el = e.target;
        if (el.type === 'number' && /^(cc|oc|lc|pcp|pco)\d$/.test(el.name || '')) {
            if (el.value !== '' && parseFloat(el.value) > 10) el.value = '10';
            if (el.value !== '' && parseFloat(el.value) < 0)  el.value = '0';
        }
    });

    // Restore default layout after a form reset
    document.getElementById('scoringForm').addEventListener('reset', () => {
        setTimeout(() => { positionInput.value = ''; closePositionDropdown(); applyFormLayout(); }, 0);
    });

    // =========================================================================
    // Rater checkbox dropdown (keeps the order they were ticked)
    // =========================================================================
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

    // Secret Admin Shortcut: Hold ALT while double-clicking "INTERVIEW SCORING SHEET"
    function goToAdminResults(event) {
        if (event.altKey) {
            window.location.href = 'results_2.php';
        }
    }

    // Save button: saves in the background without redirecting
    function saveOnly() {
        const form = document.getElementById('scoringForm');
        const btn  = document.getElementById('saveBtn');

        // Snap to the canonical title before submitting, so aliases like "atty 5" get stored as "Attorney V"
        positionInput.value = canonicalPositionTitle(positionInput.value);

        updateRaterSummary();
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
        .then(response =>
            response.json()
                .then(data => ({ ok: response.ok, data }))
                .catch(() => ({ ok: false, data: { message: 'Unexpected server response.' } }))
        )
        .then(({ ok, data }) => {
            if (ok && data.status === 'success') {
                savedOk = true;
                markSaved();
                alert('Saved successfully! You can now print this sheet, or click "New Sheet" for the next one.');
            } else {
                alert(data.message || 'Failed to save.');
            }
        })
        .catch(() => alert('Error saving data. Check your connection.'))
        .finally(() => { if (!savedOk) btn.disabled = false; });
    }

    function markSaved() {
        const btn = document.getElementById('saveBtn');
        btn.textContent = 'Saved ✓';
        btn.disabled = true;
        document.getElementById('newSheetBtn').style.display = 'inline-block';
    }

    function markEdited() {
        const btn = document.getElementById('saveBtn');
        if (btn.textContent !== 'Save') { btn.textContent = 'Save'; btn.disabled = false; }
    }

    function newSheet() {
        document.getElementById('scoringForm').reset();
        document.getElementById('newSheetBtn').style.display = 'none';
        markEdited();
        window.scrollTo(0, 0);
    }

    applyFormLayout();

</script>

</body>
</html>
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

        .header-cell {
            border: none !important;
            text-align: center;
            font-size: 13px;
            padding: 2px 0;
        }

        .header-title {
            border: none !important;
            text-align: center;
            font-weight: bold;
            font-size: 14px;
            padding: 10px 0;
        }

        .info-cell {
            border: none !important;
            padding: 4px 0;
            font-size: 13px;
        }

        input[type="text"], input[type="number"], select {
            width: 100%;
            padding: 4px;
            box-sizing: border-box;
            border: none;
            outline: none;
            background: transparent;
            font-family: inherit;
            font-size: inherit;
            color: #000;
        }

        input[type="number"] {
            text-align: center;
            font-weight: bold;
        }

        .sec-header {
            background-color: #d9d9d9 !important;
            font-weight: bold !important;
            text-align: center !important;
            vertical-align: middle !important;
        }

        .ev-label {
            background-color: #d9d9d9 !important;
            font-weight: bold !important;
            text-align: center !important;
        }

        .comp-title {
            font-weight: bold;
            background-color: #fff;
        }

        .transparent {
            border: none !important;
            padding-top: 15px;
        }

        .secret-trigger { 
            user-select: none; 
            cursor: default; 
        }

        /* Position autocomplete wrapper */
        .pos-wrap {
            position: relative;
            display: inline-block;
            width: 75%;
            vertical-align: middle;
        }
        .pos-wrap input {
            width: 100%;
            display: inline-block;
            border-bottom: 1px solid #000;
            background: transparent;
            padding: 4px 0;
        }
        .pos-dropdown {
            display: none;
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            z-index: 50;
            background: #fff;
            border: 1px solid #999;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            max-height: 280px;
            overflow-y: auto;
            margin-top: 2px;
        }
        .pos-dropdown.open { display: block; }
        .pos-item {
            padding: 7px 10px;
            cursor: pointer;
            font-size: 13px;
            border-bottom: 1px solid #eee;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .pos-item:last-child { border-bottom: none; }
        .pos-item:hover,
        .pos-item.highlight {
            background: #e8f5e9;
            color: #107c41;
        }
        .pos-item mark {
            background: #fff59d;
            padding: 0;
            font-weight: bold;
        }
        .pos-empty {
            padding: 8px 10px;
            color: #888;
            font-size: 12px;
            font-style: italic;
        }
        .pos-hint {
            padding: 6px 10px;
            background: #fff6e0;
            color: #7a5a00;
            font-size: 11px;
            border-top: 1px solid #f0dca0;
        }

       @media print {
    .btn-container, .pos-dropdown { display: none !important; }
    #raterDropdown > div { display: none !important; }
    #raterSummary::-webkit-details-marker { display: none; }
    #raterSummary { border-bottom: none !important; padding: 0 !important; }
    select { -webkit-appearance: none; -moz-appearance: none; appearance: none; background: none; border: none; }
    input[type="text"]::placeholder { color: transparent; }
}

        .btn-container {
            margin-top: 20px;
            text-align: center;
        }

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

<?php if ($flashError = flash_get('error')): ?>
<div class="user-bar" style="background:#fdecea;border:1px solid #f5c2c0;color:#7a1f16;padding:8px 12px;border-radius:4px;">
    <?php echo htmlspecialchars($flashError); ?>
</div>
<?php endif; ?>

<form id="scoringForm" action="submit_score.php" method="POST">
<input type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute; left:-9999px; width:1px; height:1px;">
<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">

<table>
    <colgroup>
        <col class="col-desc">
        <col class="col-ev">
        <col class="col-rate">
    </colgroup>

    <tr><td colspan="3" class="header-cell">Department of Environment and Natural Resources</td></tr>
    <tr><td colspan="3" class="header-cell">Region VI, Western Visayas</td></tr>
    <tr><td colspan="3" class="header-cell">Regional Human Resource Merit Promotion and Selection Board (RHRMPSB)</td></tr>       
    
    <tr><td colspan="3" class="header-title secret-trigger" ondblclick="goToAdminResults(event)">INTERVIEW SCORING SHEET</td></tr>

    <tr>
        <td class="info-cell">NAME: <input type="text" name="candidate_name" placeholder="" required style="width: 75%; display: inline-block;"></td>
        <td colspan="2" class="info-cell">Position: 
            <span class="pos-wrap">
                <input type="text" name="position_title" id="position_custom" autocomplete="off" placeholder="Type to search a position..." maxlength="255" required>
                <div class="pos-dropdown" id="positionDropdown"></div>
            </span>
        </td>
    </tr>
    <tr>
        <td class="info-cell"></td>
        <td colspan="2" class="info-cell">Office: 
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
        <td colspan="2" class="info-cell" style="padding-bottom: 15px;">Date: <input type="text" name="interview_date" value="<?php echo date('F d, Y'); ?>" style="width: 70%; display: inline-block;"></td>
    </tr>

    <tr class="sec-core">
        <th class="sec-header">Core Competencies</th>
        <th class="sec-header">Evidence</th>
        <th class="sec-header">Rating</th>
    </tr>

    <tr class="sec-core">
        <td class="comp-title">CC1 - DISCIPLINE</td>
        <td class="ev-label">Advanced</td>
        <td></td>
    </tr>
    <tr class="sec-core">
        <td>Serves as a good role model on DENR values and ethics to staff/peers</td>
        <td><input type="text" name="evidence_cc1"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="cc1" placeholder="0.00"></td>
    </tr>

    <tr class="sec-core">
        <td class="comp-title">CC2 - EXCELLENCE</td>
        <td></td>
        <td></td>
    </tr>
    <tr class="sec-core">
        <td>Anticipates, identifies and manages stakeholders’ standards and requirements towards excellent customer service through improving sense of responsibility and competence</td>
        <td><input type="text" name="evidence_cc2"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="cc2" placeholder="0.00"></td>
    </tr>

    <tr class="sec-core">
        <td class="comp-title">CC3 - NOBILITY</td>
        <td></td>
        <td></td>
    </tr>
    <tr class="sec-core">
        <td>Influences others to observe virtue, goodness, honor, justness and decency in all situations</td>
        <td><input type="text" name="evidence_cc3"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="cc3" placeholder="0.00"></td>
    </tr>

    <tr class="sec-core">
        <td class="comp-title">CC4 - RESPONSIBILITY</td>
        <td></td>
        <td></td>
    </tr>
    <tr class="sec-core">
        <td>Leads in the observance of the principle of transparency and accountability in the workplace</td>
        <td><input type="text" name="evidence_cc4"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="cc4" placeholder="0.00"></td>
    </tr>

    <tr class="sec-core">
        <td class="comp-title">CC5 - CARING FOR THE ENVIRONMENT AND NATURAL RESOURCES</td>
        <td></td>
        <td></td>
    </tr>
    <tr class="sec-core">
        <td>Serves as a good role model in conserving and preserving the environment to peers and staff</td>
        <td><input type="text" name="evidence_cc5"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="cc5" placeholder="0.00"></td>
    </tr>

    <tr class="sec-right">
        <th class="sec-header">Organizational Competencies</th>
        <th class="sec-header">Advanced</th>
        <th class="sec-header"></th>
    </tr>

    <tr class="sec-right">
        <td class="comp-title">OC1 - WRITING EFFECTIVELY</td>
        <td></td>
        <td></td>
    </tr>
    <tr class="sec-right">
        <td>Produces written work from scratch with some guidance while complying to agreed or prescribed standards of communicating with the bureaucracy</td>
        <td><input type="text" name="evidence_oc1"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="oc1" placeholder="0.00"></td>
    </tr>

    <tr class="sec-right">
        <td class="comp-title">OC2 - SPEAKING EFFECTIVELY</td>
        <td></td>
        <td></td>
    </tr>
    <tr class="sec-right">
        <td>Effectively delivers messages that require careful planning for the method used and the possible impact of the message (audience may be a large group, i.e., office, organization) Focus of communication is to relay information and to build motivation</td>
        <td><input type="text" name="evidence_oc2"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="oc2" placeholder="0.00"></td>
    </tr>

    <tr class="sec-right">
        <td class="comp-title">OC3 - TECHNOLOGY LITERACY AND MANAGING INFORMATION</td>
        <td></td>
        <td></td>
    </tr>
    <tr class="sec-right">
        <td>Analyzes appropriateness of office software and equipment in the performance of assigned tasks. Develops information assets to achieve organizational goals</td>
        <td><input type="text" name="evidence_oc3"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="oc3" placeholder="0.00"></td>
    </tr>

    <tr class="sec-right">
        <td class="comp-title">OC4 - PROJECT MANAGEMENT</td>
        <td></td>
        <td></td>
    </tr>
    <tr class="sec-right">
        <td>Leads in project management activities</td>
        <td><input type="text" name="evidence_oc4"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="oc4" placeholder="0.00"></td>
    </tr>

    <tr class="sec-right">
        <td class="comp-title">OC5 - COMPLETE STAFF WORK (CSW)</td>
        <td></td>
        <td></td>
    </tr>
    <tr class="sec-right">
        <td>Leads the practice of CSW in his/her office/unit</td>
        <td><input type="text" name="evidence_oc5"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="oc5" placeholder="0.00"></td>
    </tr>

    <tr class="leadership-row sec-right">
        <th class="sec-header"><span id="lcHeadingText">Leadership Competencies</span><span id="lcHint" style="display:none; font-size:11px; font-weight:normal; opacity:0.85;"></span></th>
        <th class="sec-header" id="lcLevelLabel">Basic</th>
        <th class="sec-header"></th>
    </tr>

    <tr class="leadership-row sec-right">
        <td class="comp-title">LC1 – STRATEGIC LEADERSHIP (THINKING STRATEGICALLY AND CREATIVELY)</td>
        <td></td>
        <td></td>
    </tr>
    <tr class="leadership-row sec-right">
        <td id="lcDesc1">Develops office/ service’s strategies and plans based on the DENR’s mission/vision</td>
        <td><input type="text" name="evidence_lc1"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="lc1" placeholder="0.00"></td>
    </tr>

    <tr class="leadership-row sec-right">
        <td class="comp-title">LC2 – LEADING CHANGE</td>
        <td></td>
        <td></td>
    </tr>
    <tr class="leadership-row sec-right">
        <td id="lcDesc2">Effectively delivers messages that require careful planning for the method used and the possible impact of the message (audience may be a large group, i.e., office, organization) Focus of communication is to relay information and to build motivation</td>
        <td><input type="text" name="evidence_lc2"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="lc2" placeholder="0.00"></td>
    </tr>

    <tr class="leadership-row sec-right">
        <td class="comp-title">LC3 – PEOPLE DEVELOPMENT (CREATING AND NURTURING A HIGH PERFORMING ORGANIZATION)</td>
        <td></td>
        <td></td>
    </tr>
    <tr class="leadership-row sec-right">
        <td id="lcDesc3">Analyzes appropriateness of office software and equipment in the performance of assigned tasks. Develops information assets to achieve organizational goals</td>
        <td><input type="text" name="evidence_lc3"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="lc3" placeholder="0.00"></td>
    </tr>

    <tr class="leadership-row sec-right">
        <td class="comp-title">LC4 – PEOPLE PERFORMANCE MANAGEMENT (MANAGING PERFORMANCE AND COACHING FOR RESULTS)</td>
        <td></td>
        <td></td>
    </tr>
    <tr class="leadership-row sec-right">
        <td id="lcDesc4">Leads in project management activities</td>
        <td><input type="text" name="evidence_lc4"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="lc4" placeholder="0.00"></td>
    </tr>

    <tr class="leadership-row sec-right">
        <td class="comp-title">LC5 – PARTNERSHIP AND NETWORKING (BUILDING COLLABORATIVE AND INCLUSIVE WORKING RELATIONSHIPS)</td>
        <td></td>
        <td></td>
    </tr>
    <tr class="leadership-row sec-right">
        <td id="lcDesc5">Leads the practice of CSW in his/her office/unit</td>
        <td><input type="text" name="evidence_lc5"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="lc5" placeholder="0.00"></td>
    </tr>

    <tr class="sec-right">
        <th class="sec-header">Functional Competencies</th>
        <th class="sec-header">Advanced</th>
        <th class="sec-header"></th>
    </tr>

    <tr class="sec-right">
        <td class="comp-title">PCP1 – PLANNING AND PROGRAMMING</td>
        <td></td>
        <td></td>
    </tr>
    <tr class="sec-right">
        <td>Organizes the preparation of the PENRO operational plans</td>
        <td><input type="text" name="evidence_pcp1"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="pcp1" placeholder="0.00"></td>
    </tr>

    <tr class="sec-right">
        <td class="comp-title">PCP2 – MONITORING AND EVALUATION</td>
        <td></td>
        <td></td>
    </tr>
    <tr class="sec-right">
        <td>Leads the preparation of monitoring and evaluation reports of all DENR-PENRO programs and projects</td>
        <td><input type="text" name="evidence_pcp2"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="pcp2" placeholder="0.00"></td>
    </tr>

    <tr class="sec-right">
        <td class="comp-title">PCP3 – STATISTICAL COORDINATION AND DATA RESEARCH</td>
        <td></td>
        <td></td>
    </tr>
    <tr class="sec-right">
        <td>Leads the characterization of ecosystem and use of planning tools and procedures</td>
        <td><input type="text" name="evidence_pcp3"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="pcp3" placeholder="0.00"></td>
    </tr>

    <tr class="sec-right">
        <td class="comp-title">PCO4 – RESOURCE MANAGEMENT AND RESTORATION/ REHABILITATION OF DEGRADED ECOSYSTEMS</td>
        <td></td>
        <td></td>
    </tr>
    <tr class="sec-right">
        <td>Leads the conduct of statistical coordination and data research activities</td>
        <td><input type="text" name="evidence_pco4"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="pco4" placeholder="0.00"></td>
    </tr>

    <tr>
        <td class="transparent">Rated by: <input type="text" name="rater_name" placeholder="Rater Name" style="width: 70%; display: inline-block;"></td>
        <td class="transparent" colspan="2">Raters:
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
    <button type="button" id="saveBtn" onclick="saveOnly()">Save</button>
    <button type="button" onclick="window.print()">Print / Save as PDF</button>
    <button type="button" id="newSheetBtn" onclick="newSheet()" style="display: none;">New Sheet</button>
</div>
</form>

<script>
    // ============================================================
    // MASTER POSITION LIST
    // ============================================================
    const ALL_POSITIONS = [
        // --- Attorney ---
        "Attorney II", "Attorney III", "Attorney IV", "Attorney V",
        // --- Planning Officer ---
        "Planning Officer I", "Planning Officer II", "Planning Officer III", "Planning Officer IV", "Planning Officer V",
        // --- Project Evaluation Officer ---
        "Project Evaluation Officer I", "Project Evaluation Officer II", "Project Evaluation Officer III",
        // --- Development Management Officer ---
        "Development Management Officer I", "Development Management Officer II",
        "Development Management Officer III", "Development Management Officer IV", "Development Management Officer V",
        // --- Engineer (Geodetic) ---
        "Engineer I (Geodetic Engineer)", "Engineer II (Geodetic Engineer)", "Engineer III (Geodetic Engineer)",
        "Engineer IV (Geodetic Engineer)", "Engineer V (Geodetic Engineer)",
        // --- Forester ---
        "Forester I", "Forester II", "Forester III", "Forester IV", "Forester V",
        // --- Land Management Officer ---
        "Land Management Officer I", "Land Management Officer II", "Land Management Officer III", "Land Management Officer IV",
        // --- Administrative ---
        "Administrative Officer I", "Administrative Officer II", "Administrative Officer III",
        "Administrative Officer IV", "Administrative Officer V",
        "Administrative Officer V (Budget Officer III)",
        "Administrative Officer V (HRMO III)",
        "Administrative Officer V (Cashier III)",
        "Administrative Assistant I", "Administrative Assistant II", "Administrative Assistant III",
        "Administrative Aide I", "Administrative Aide III", "Administrative Aide IV", "Administrative Aide VI",
        "Supervising Administrative Officer",
        "Chief Administrative Officer",
        // --- Accountant ---
        "Accountant I", "Accountant II", "Accountant III",
        // --- Information Systems ---
        "Information Systems Analyst I", "Information Systems Analyst II", "Information Systems Analyst III",
        // --- Ecosystems Management ---
        "Ecosystems Management Specialist I", "Ecosystems Management Specialist II",
        "Senior Ecosystems Management Specialist",
        "Supervising Ecosystems Management Specialist",
        "Chief Ecosystems Management Specialist",
        // --- Communications / Information ---
        "Communication Development Officer I", "Communication Development Officer II",
        "Senior Communications Development Officer",
        "Information Officer I", "Information Officer II",
        // --- Cartographer ---
        "Cartographer I", "Cartographer II", "Cartographer III", "Cartographer IV",
        // --- Forest / Forest Technician ---
        "Forest Technician I", "Forest Technician II",
        "Forest Ranger",
        "Tree Marker", "Scaler", "Tracer",
        // --- Land Management ---
        "Land Management Inspector",
        // --- Community Development ---
        "Community Development Officer I", "Community Development Officer II",
        "Community Development Assistant I", "Community Development Assistant II",
        // --- Special Investigator ---
        "Special Investigator I",
        // --- Statistician ---
        "Statistician I", "Statistician II", "Statistician III",
        // --- Mathematician ---
        "Mathematician I", "Mathematician Aide I", "Mathematician Aide II",
        // --- Engineering Aide ---
        "Engineering Aide",
        // --- Park / Protected Area ---
        "Park Operations Superintendent V",
        "Park Maintenance Foreman", "Park Attendant II",
        // --- PENRO / CENRO ---
        "Provincial Environment & Natural Resources Officer",
        "Community Environment & Natural Resources Officer",
        "Community Environment & Natural Resources Officer (CENRO)",
    ].sort((a, b) => a.localeCompare(b));

    const LEADERSHIP_HIDDEN_POSITIONS = ["SVEMS"];
    const LEADERSHIP_HIDDEN = {};

    const LEADERSHIP_LEVELS = [
        ["Planning Officer V", "I"],
        ["Chief Administrative Officer", "I"],
        ["Attorney V", "I"],
        ["Development Management Officer V", "I"],
        ["Engineer V (Geodetic Engineer)", "I"],
        ["Chief Ecosystems Management Specialist", "I"],
        ["Provincial Environment & Natural Resources Officer", "I"],
        ["Community Environment & Natural Resources Officer", "I"],
        ["Community Environment & Natural Resources Officer (CENRO)", "I"],

        ["Senior Communications Development Officer", "B"],
        ["Planning Officer IV", "B"],
        ["Planning Officer III", "B"],
        ["Project Evaluation Officer III", "B"],
        ["Information Systems Analyst III", "B"],
        ["Supervising Administrative Officer", "B"],
        ["Administrative Officer V", "B"],
        ["Administrative Officer V (Budget Officer III)", "B"],
        ["Administrative Officer V (HRMO III)", "B"],
        ["Administrative Officer V (Cashier III)", "B"],
        ["Accountant III", "B"],
        ["Attorney IV", "B"],
        ["Attorney III", "B"],
        ["Attorney II", "B"],
        ["Development Management Officer IV", "B"],
        ["Development Management Officer III", "B"],
        ["Engineer IV (Geodetic Engineer)", "B"],
        ["Engineer III (Geodetic Engineer)", "B"],
        ["Engineer II (Geodetic Engineer)", "B"],
        ["Forester III", "B"],
        ["Supervising Ecosystems Management Specialist", "B"],
        ["Senior Ecosystems Management Specialist", "B"],
        ["Land Management Officer III", "B"]
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
    const DIGIT_NUMERAL = { "2": "II", "3": "III", "4": "IV", "5": "V" };

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
            if (numeral && DIGIT_NUMERAL[numeral]) keys.push(abbr + DIGIT_NUMERAL[numeral]);
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

    // ============================================================
    // POSITION AUTOCOMPLETE DROPDOWN
    // ============================================================
    const positionInput = document.getElementById('position_custom');
    const positionDropdown = document.getElementById('positionDropdown');
    let highlightedIndex = -1;
    let currentMatches = [];

    function openDropdown() {
        positionDropdown.classList.add('open');
    }
    function closeDropdown() {
        positionDropdown.classList.remove('open');
        highlightedIndex = -1;
    }

    function escapeHtml(s) {
        return String(s ?? '').replace(/[&<>"']/g, ch => ({
            '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
        }[ch]));
    }

    function highlightMatch(text, query) {
        if (!query) return escapeHtml(text);
        const idx = text.toUpperCase().indexOf(query.toUpperCase());
        if (idx === -1) return escapeHtml(text);
        return escapeHtml(text.slice(0, idx)) +
               '<mark>' + escapeHtml(text.slice(idx, idx + query.length)) + '</mark>' +
               escapeHtml(text.slice(idx + query.length));
    }

    function renderDropdown(query) {
        const q = String(query || '').trim();

        let matches;
        if (q === '') {
            matches = ALL_POSITIONS.slice(0, 50);
        } else {
            const qUpper = q.toUpperCase();
            matches = ALL_POSITIONS.filter(p => p.toUpperCase().includes(qUpper));
            matches.sort((a, b) => {
                const aStarts = a.toUpperCase().startsWith(qUpper) ? 0 : 1;
                const bStarts = b.toUpperCase().startsWith(qUpper) ? 0 : 1;
                if (aStarts !== bStarts) return aStarts - bStarts;
                return a.localeCompare(b);
            });
        }

        currentMatches = matches;
        highlightedIndex = matches.length > 0 ? 0 : -1;

        if (matches.length === 0) {
            positionDropdown.innerHTML = `
                <div class="pos-empty">No matching position.</div>
                <div class="pos-hint">You can still type a custom title and press Save — but exact matches work best.</div>
            `;
            openDropdown();
            return;
        }

        let html = matches.slice(0, 60).map((p, i) =>
            `<div class="pos-item${i === 0 ? ' highlight' : ''}" data-value="${escapeHtml(p)}">${highlightMatch(p, q)}</div>`
        ).join('');

        if (matches.length > 60) {
            html += `<div class="pos-empty">…and ${matches.length - 60} more. Keep typing to narrow down.</div>`;
        }

        positionDropdown.innerHTML = html;
        openDropdown();
    }

    positionDropdown.addEventListener('mousedown', (e) => {
        const item = e.target.closest('.pos-item');
        if (!item) return;
        e.preventDefault();
        positionInput.value = item.getAttribute('data-value');
        closeDropdown();
        applyFormLayout();
    });

    positionInput.addEventListener('input', () => {
        renderDropdown(positionInput.value);
        applyFormLayout();
    });

    positionInput.addEventListener('focus', () => {
        renderDropdown(positionInput.value);
    });

    positionInput.addEventListener('blur', () => {
        setTimeout(closeDropdown, 150);
    });

    positionInput.addEventListener('keydown', (e) => {
        if (!positionDropdown.classList.contains('open')) {
            if (e.key === 'ArrowDown') { renderDropdown(positionInput.value); e.preventDefault(); }
            return;
        }
        const items = positionDropdown.querySelectorAll('.pos-item');
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            highlightedIndex = Math.min(highlightedIndex + 1, items.length - 1);
            updateHighlight(items);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            highlightedIndex = Math.max(highlightedIndex - 1, 0);
            updateHighlight(items);
        } else if (e.key === 'Enter') {
            if (highlightedIndex >= 0 && items[highlightedIndex]) {
                e.preventDefault();
                positionInput.value = items[highlightedIndex].getAttribute('data-value');
                closeDropdown();
                applyFormLayout();
            }
        } else if (e.key === 'Escape') {
            closeDropdown();
        }
    });

    function updateHighlight(items) {
        items.forEach((it, i) => it.classList.toggle('highlight', i === highlightedIndex));
        if (items[highlightedIndex]) {
            items[highlightedIndex].scrollIntoView({ block: 'nearest' });
        }
    }

    // ============================================================
    // FORM LAYOUT / LEADERSHIP VISIBILITY
    // ============================================================
    function leadershipHidden(office, position) {
        const pos = String(position || '').trim();
        if (pos === '') return true;
        if (LEADERSHIP_HIDDEN_POSITIONS.includes(pos.toUpperCase())) return true;
        const rule = LEADERSHIP_HIDDEN[office];
        if (rule === "*" || (Array.isArray(rule) && rule.includes(pos))) return true;
        return leadershipLevelFor(pos) === null;
    }

    function currentPosition() {
        const custom = document.getElementById('position_custom');
        return custom ? custom.value.trim() : '';
    }

    function applyFormLayout() {
        const office = document.getElementById('office_name').value;
        const position = currentPosition();
        const noLeadership = leadershipHidden(office, position);

        const level = noLeadership ? null : leadershipLevelFor(position);
        const intermediate = (level === 'I');
        document.getElementById('lcHeadingText').textContent = 'Leadership Competencies';
        document.getElementById('lcLevelLabel').textContent = intermediate ? 'Advanced' : 'Basic';
        for (let i = 1; i <= 5; i++) {
            document.getElementById('lcDesc' + i).textContent = intermediate ? LC_INTERMEDIATE[i - 1] : LC_BASIC[i - 1];
        }
        document.getElementById('lcHint').style.display = (!noLeadership && position !== '' && level === null) ? 'block' : 'none';

        document.querySelectorAll('tr.leadership-row').forEach(row => {
            row.classList.toggle('is-hidden', noLeadership);
            row.querySelectorAll('input, select, textarea').forEach(el => {
                if (noLeadership) el.value = '';
                el.disabled = noLeadership;
            });
        });
        if (typeof markEdited === 'function') markEdited();
    }

    document.getElementById('scoringForm').addEventListener('reset', () => {
        setTimeout(() => {
            document.getElementById('position_custom').value = '';
            closeDropdown();
            applyFormLayout();
        }, 0);
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
        const pdd = document.getElementById('positionDropdown');
        const pwrap = document.querySelector('.pos-wrap');
        if (pdd && pwrap && !pwrap.contains(e.target)) {
            closeDropdown();
        }
    });

    function goToAdminResults(event) {
        if (event.altKey) {
            window.location.href = 'results_2.php';
        }
    }

    function saveOnly() {
        const form = document.getElementById('scoringForm');
        const btn  = document.getElementById('saveBtn');

        updateRaterSummary();
        closeDropdown();
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
        .then(response =>
            response.json()
                .then(data => ({ ok: response.ok, data }))
                .catch(() => ({ ok: false, data: { message: 'Unexpected server response.' } }))
        )
        .then(({ ok, data }) => {
            if (ok && data.status === 'success') {
                savedOk = true;
                markSaved();
                alert('Saved successfully! You can now print this sheet, or click "New Sheet" for the next one.');
            } else {
                alert(data.message || 'Failed to save.');
            }
        })
        .catch(() => alert('Error saving data. Check your connection.'))
        .finally(() => { if (!savedOk) btn.disabled = false; });
    }

    function markSaved() {
        const btn = document.getElementById('saveBtn');
        btn.textContent = 'Saved ✓';
        btn.disabled = true;
        document.getElementById('newSheetBtn').style.display = 'inline-block';
    }

    function markEdited() {
        const btn = document.getElementById('saveBtn');
        if (btn.textContent !== 'Save') { btn.textContent = 'Save'; btn.disabled = false; }
    }

    function newSheet() {
        document.getElementById('scoringForm').reset();
        document.getElementById('newSheetBtn').style.display = 'none';
        markEdited();
        window.scrollTo(0, 0);
    }

    applyFormLayout();

</script>

</body>
</html>
