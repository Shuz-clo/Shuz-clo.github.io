<?php
require_once 'db.php';
require_once __DIR__ . '/AuthSchema.php';
require_once 'auth.php';
require_once __DIR__ . '/config.php';

ensure_auth_schema($conn);
purge_expired_tokens($conn);

// Require login and permit both rater and user roles
require_login();
require_role(['rater', 'user']);

// Admins manage results from results_2.php, not this form — if they're
// already logged in, send them straight there. Everyone else (anonymous
// visitors included) still sees the scoring form as normal.
if (current_role() === 'admin') {
    header('Location: results_2.php');
    exit;
}
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

        /* Top Header Rows (Borderless) */
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

        /* Candidate Info Section */
        .info-cell {
            border: none !important;
            padding: 4px 0;
            font-size: 13px;
        }

        /* Table Input Formatting */
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

        /* Section & Header Styles matching Excel sheet */
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

        /* Secret Trigger Style */
        .secret-trigger { 
            user-select: none; 
            cursor: default; 
        }

        /* Print / Save as PDF: hide every button and make the form controls look like plain text */
        @media print {
            .btn-container, #positionBackBtn { display: none !important; }
            #raterDropdown > div { display: none !important; }              /* rater checkbox list, even if left open */
            #raterSummary::-webkit-details-marker { display: none; }
            #raterSummary[data-empty] { color: transparent; }               /* hide "-- Select Raters --" when nothing is ticked */
            select { -webkit-appearance: none; -moz-appearance: none; appearance: none; background: none; }  /* no dropdown arrows */
            input[type="text"]::placeholder { color: transparent; }          /* hide "Rater Name" style hints */
        }

        /* Button Styling */
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
    </style>
</head>
<body>

<?php if (is_logged_in()): ?>
<div class="user-bar">
    <span>Signed in as <strong><?php echo htmlspecialchars($_SESSION['username'] ?? ''); ?></strong> (<?php echo htmlspecialchars((string)current_role()); ?>)</span>
    <a href="logout.php">Log out</a>
</div>
<?php endif; ?>

<form id="scoringForm" action="submit_score.php" method="POST">
<!-- Honeypot (bots fill this, humans never see it) -->
<input type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute; left:-9999px; width:1px; height:1px;">

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
            <select name="position_title" id="position_title" required style="width: 70%; display: inline-block;" onchange="togglePositionOther()">
                <option value="">-- Select Position --</option>
                <option value="Land Management Officer I">Land Management Officer I</option>
                <option value="Land Management Officer II">Land Management Officer II</option>
                <option value="Environmental Management Specialist I">Environmental Management Specialist I</option>
                <option value="Environmental Management Specialist II">Environmental Management Specialist II</option>
                <option value="Administrative Officer I">Administrative Officer I</option>
                <option value="Administrative Assistant I">Administrative Assistant I</option>
                <option value="Forest Technician I">Forest Technician I</option>
                <option value="Community Development Officer I">Community Development Officer I</option>
                <option value="__OTHER__">Others (type position)...</option>
            </select>
            <input type="text" name="position_title" id="position_custom" placeholder="Type position title" maxlength="255" disabled style="display: none; width: 60%; border-bottom: 1px solid #000;">
            <button type="button" id="positionBackBtn" onclick="backToPositionList()" style="display: none; padding: 2px 8px; font-size: 12px;" title="Back to list">&times; list</button>
        </td>
    </tr>
    <tr>
        <td class="info-cell"></td>
        <td colspan="2" class="info-cell">Office: 
            <select name="office_name" id="office_name" required style="width: 70%; display: inline-block;" onchange="updatePositionOptions()">
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
    <tr>
        <th class="sec-header">Core Competencies</th>
        <th class="sec-header">Evidence</th>
        <th class="sec-header">Rating</th>
    </tr>

    <tr>
        <td class="comp-title">CC1 - DISCIPLINE</td>
        <td class="ev-label">Advanced</td>
        <td></td>
    </tr>
    <tr>
        <td>Serves as a good role model on DENR values and ethics to staff/peers</td>
        <td><input type="text" name="evidence_cc1"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="cc1" placeholder="0.00"></td>
    </tr>

    <tr>
        <td class="comp-title">CC2 - EXCELLENCE</td>
        <td></td>
        <td></td>
    </tr>
    <tr>
        <td>Anticipates, identifies and manages stakeholders’ standards and requirements towards excellent customer service through improving sense of responsibility and competence</td>
        <td><input type="text" name="evidence_cc2"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="cc2" placeholder="0.00"></td>
    </tr>

    <tr>
        <td class="comp-title">CC3 - NOBILITY</td>
        <td></td>
        <td></td>
    </tr>
    <tr>
        <td>Influences others to observe virtue, goodness, honor, justness and decency in all situations</td>
        <td><input type="text" name="evidence_cc3"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="cc3" placeholder="0.00"></td>
    </tr>

    <tr>
        <td class="comp-title">CC4 - RESPONSIBILITY</td>
        <td></td>
        <td></td>
    </tr>
    <tr>
        <td>Leads in the observance of the principle of transparency and accountability in the workplace</td>
        <td><input type="text" name="evidence_cc4"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="cc4" placeholder="0.00"></td>
    </tr>

    <tr>
        <td class="comp-title">CC5 - CARING FOR THE ENVIRONMENT AND NATURAL RESOURCES</td>
        <td></td>
        <td></td>
    </tr>
    <tr>
        <td>Serves as a good role model in conserving and preserving the environment to peers and staff</td>
        <td><input type="text" name="evidence_cc5"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="cc5" placeholder="0.00"></td>
    </tr>

    <!-- ===== Organizational Competencies ===== -->
    <tr>
        <th class="sec-header">Organizational Competencies</th>
        <th class="sec-header">Advanced</th>
        <th class="sec-header"></th>
    </tr>

    <tr>
        <td class="comp-title">OC1 - WRITING EFFECTIVELY</td>
        <td></td>
        <td></td>
    </tr>
    <tr>
        <td>Produces written work from scratch with some guidance while complying to agreed or prescribed standards of communicating with the bureaucracy</td>
        <td><input type="text" name="evidence_oc1"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="oc1" placeholder="0.00"></td>
    </tr>

    <tr>
        <td class="comp-title">OC2 - SPEAKING EFFECTIVELY</td>
        <td></td>
        <td></td>
    </tr>
    <tr>
        <td>Effectively delivers messages that require careful planning for the method used and the possible impact of the message (audience may be a large group, i.e., office, organization) Focus of communication is to relay information and to build motivation</td>
        <td><input type="text" name="evidence_oc2"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="oc2" placeholder="0.00"></td>
    </tr>

    <tr>
        <td class="comp-title">OC3 - TECHNOLOGY LITERACY AND MANAGING INFORMATION</td>
        <td></td>
        <td></td>
    </tr>
    <tr>
        <td>Analyzes appropriateness of office software and equipment in the performance of assigned tasks. Develops information assets to achieve organizational goals</td>
        <td><input type="text" name="evidence_oc3"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="oc3" placeholder="0.00"></td>
    </tr>

    <tr>
        <td class="comp-title">OC4 - PROJECT MANAGEMENT</td>
        <td></td>
        <td></td>
    </tr>
    <tr>
        <td>Leads in project management activities</td>
        <td><input type="text" name="evidence_oc4"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="oc4" placeholder="0.00"></td>
    </tr>

    <tr>
        <td class="comp-title">OC5 - COMPLETE STAFF WORK (CSW)</td>
        <td></td>
        <td></td>
    </tr>
    <tr>
        <td>Leads the practice of CSW in his/her office/unit</td>
        <td><input type="text" name="evidence_oc5"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="oc5" placeholder="0.00"></td>
    </tr>

    <!-- ===== Leadership Competencies ===== -->
    <tr>
        <th class="sec-header">Leadership Competencies - BASIC</th>
        <th class="sec-header">Basic</th>
        <th class="sec-header"></th>
    </tr>

    <tr>
        <td class="comp-title">LC1 – STRATEGIC LEADERSHIP (THINKING STRATEGICALLY AND CREATIVELY)</td>
        <td></td>
        <td></td>
    </tr>
    <tr>
        <td>Develops office/ service’s strategies and plans based on the DENR’s mission/vision</td>
        <td><input type="text" name="evidence_lc1"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="lc1" placeholder="0.00"></td>
    </tr>

    <tr>
        <td class="comp-title">LC2 – LEADING CHANGE</td>
        <td></td>
        <td></td>
    </tr>
    <tr>
        <td>Effectively delivers messages that require careful planning for the method used and the possible impact of the message (audience may be a large group, i.e., office, organization) Focus of communication is to relay information and to build motivation</td>
        <td><input type="text" name="evidence_lc2"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="lc2" placeholder="0.00"></td>
    </tr>

    <tr>
        <td class="comp-title">LC3 – PEOPLE DEVELOPMENT (CREATING AND NURTURING A HIGH PERFORMING ORGANIZATION)</td>
        <td></td>
        <td></td>
    </tr>
    <tr>
        <td>Analyzes appropriateness of office software and equipment in the performance of assigned tasks. Develops information assets to achieve organizational goals</td>
        <td><input type="text" name="evidence_lc3"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="lc3" placeholder="0.00"></td>
    </tr>

    <tr>
        <td class="comp-title">LC4 – PEOPLE PERFORMANCE MANAGEMENT (MANAGING PERFORMANCE AND COACHING FOR RESULTS)</td>
        <td></td>
        <td></td>
    </tr>
    <tr>
        <td>Leads in project management activities</td>
        <td><input type="text" name="evidence_lc4"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="lc4" placeholder="0.00"></td>
    </tr>

    <tr>
        <td class="comp-title">LC5 – PARTNERSHIP AND NETWORKING (BUILDING COLLABORATIVE AND INCLUSIVE WORKING RELATIONSHIPS)</td>
        <td></td>
        <td></td>
    </tr>
    <tr>
        <td>Leads the practice of CSW in his/her office/unit</td>
        <td><input type="text" name="evidence_lc5"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="lc5" placeholder="0.00"></td>
    </tr>

    <!-- ===== Functional Competencies ===== -->
    <tr>
        <th class="sec-header">Functional Competencies</th>
        <th class="sec-header">Advanced</th>
        <th class="sec-header"></th>
    </tr>

    <tr>
        <td class="comp-title">PCP1 – PLANNING AND PROGRAMMING</td>
        <td></td>
        <td></td>
    </tr>
    <tr>
        <td>Organizes the preparation of the PENRO operational plans</td>
        <td><input type="text" name="evidence_pcp1"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="pcp1" placeholder="0.00"></td>
    </tr>

    <tr>
        <td class="comp-title">PCP2 – MONITORING AND EVALUATION</td>
        <td></td>
        <td></td>
    </tr>
    <tr>
        <td>Leads the preparation of monitoring and evaluation reports of all DENR-PENRO programs and projects</td>
        <td><input type="text" name="evidence_pcp2"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="pcp2" placeholder="0.00"></td>
    </tr>

    <tr>
        <td class="comp-title">PCP3 – STATISTICAL COORDINATION AND DATA RESEARCH</td>
        <td></td>
        <td></td>
    </tr>
    <tr>
        <td>Leads the characterization of ecosystem and use of planning tools and procedures</td>
        <td><input type="text" name="evidence_pcp3"></td>
        <td><input type="number" step="0.01" min="0" max="10" name="pcp3" placeholder="0.00"></td>
    </tr>

    <tr>
        <td class="comp-title">PCO4 – RESOURCE MANAGEMENT AND RESTORATION/ REHABILITATION OF DEGRADED ECOSYSTEMS</td>
        <td></td>
        <td></td>
    </tr>
    <tr>
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
                <div style="position: absolute; left: 0; top: 100%; z-index: 20; background: #fff; border: 1px solid #999; box-shadow: 0 2px 6px rgba(0,0,0,0.2); padding: 6px 12px; min-width: 200px;">
                    <?php foreach (rater_list() as $label): ?>
                    <label style="display: block; padding: 3px 0; cursor: pointer;"><input type="checkbox" class="rater-check" name="raters[]" value="<?php echo htmlspecialchars($label); ?>" data-label="<?php echo htmlspecialchars($label); ?>"> <?php echo htmlspecialchars($label); ?></label>
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
    // Positions allowed per office. Keys must match the office_name <option> values exactly.
    const POSITIONS_BY_OFFICE = {
        "Regional Office": [
            "ADMINISTRATIVE OFFICER III (CASHIER)", "ADMINISTRATIVE OFFICER V (CASHIER III)", "ACCOUNTANT III",
            "DMO 1 (LPDD)", "DMO 1 (CDD)", "DMO 2 (LPDD)", "Forester II (SMD)", "ADOF2", "ADAS2",
            "AO IV-PMD", "PEO III-PMD", "PLO IV-PMD", "SREMS", "EMS II", "ENG 1", "CARTO IV", "FORESTER III",
            "ADOF 1- SMD", "DMO III-LPDD", "DMO III-CDD", "AO IV-ADMIN", "SAO", "AO V- ADMIN",
            "ADAS II- ORED", "ADAS III- ORED", "ENGA", "CARTO", "ADA6- LEGA", "ZOOT", "MTH", "MTHA 2", "ADA6- SMD"
        ],
        "PENRO Aklan": [
            "PLANNING OFFICER I", "CARTOGRAPHER I", "Forest Technician I", "FOREST TECHNICIAN II",
            "FOREST RANGER", "RECORDS OFFICER I", "SVEMS", "EMS 1", "Forester I"
        ],
        "PENRO Capiz": [
            "Forest Ranger", "FT I", "FT II", "Forester I", "Planning Officer I", "Forester III", "SVEMS"
        ],
        "PENRO Guimaras": [
            "LAND MANAGEMENT OFFICER I", "COMMUNITY DEVELOPMENT OFFICER II", "PLANNING OFFICER II",
            "ADMINISTRATIVE OFFICER I (CASHIER)", "ADMINISTRATIVE AIDE VI", "LAND MANAGEMENT INVESTIGATOR",
            "FOREST TECHNICIAN I/II"
        ],
        "PENRO Antique": [
            "ADOF (Records Officer I)", "FT I", "FT II", "EMS I", "GE", "SVEMS"
        ],
        "PENRO Iloilo": [
            "Forester I (Guimbal)", "Records Officer (PENRO Iloilo)", "PLANNING OFFICER I (PENRO Iloilo)",
            "Forester I (Barotac)", "MLO I (Sara)", "CAO", "LMO II", "GE"
        ]
        // Offices not listed above (Negros Occidental) use DEFAULT_POSITIONS below.
    };

    // Fallback list for offices with no specific list yet (your original 8 options)
    const DEFAULT_POSITIONS = [
        "Land Management Officer I", "Land Management Officer II",
        "Environmental Management Specialist I", "Environmental Management Specialist II",
        "Administrative Officer I", "Administrative Assistant I",
        "Forest Technician I", "Community Development Officer I"
    ];

    function updatePositionOptions() {
        const office = document.getElementById('office_name').value;
        const posSelect = document.getElementById('position_title');
        const positions = POSITIONS_BY_OFFICE[office] || DEFAULT_POSITIONS;

        posSelect.innerHTML = '<option value="">-- Select Position --</option>' +
            positions.map(p => `<option value="${p.replace(/"/g, '&quot;')}">${p}</option>`).join('') +
            '<option value="__OTHER__">Others (type position)...</option>';
        togglePositionOther();
    }

    // "Others": swap the dropdown for a text box. Only one of the two is enabled,
    // so only one "position_title" value is ever submitted.
    function togglePositionOther() {
        const sel = document.getElementById('position_title');
        const custom = document.getElementById('position_custom');
        const backBtn = document.getElementById('positionBackBtn');
        const isOther = sel.value === '__OTHER__';

        sel.disabled = isOther;
        sel.style.display = isOther ? 'none' : 'inline-block';
        custom.disabled = !isOther;
        custom.required = isOther;
        custom.style.display = isOther ? 'inline-block' : 'none';
        backBtn.style.display = isOther ? 'inline-block' : 'none';
        if (isOther) custom.focus();
    }

    function backToPositionList() {
        const sel = document.getElementById('position_title');
        sel.value = '';
        document.getElementById('position_custom').value = '';
        togglePositionOther();
    }

    // After form.reset(), restore the dropdown state and the default position list
    document.getElementById('scoringForm').addEventListener('reset', () => {
        setTimeout(() => { document.getElementById('position_custom').value = ''; updatePositionOptions(); }, 0);
    });

    // ===== Rater checkbox dropdown =====
    function selectedRaters() {
        return Array.from(document.querySelectorAll('input.rater-check:checked'));
    }

    function updateRaterSummary() {
        const labels = selectedRaters().map(c => c.dataset.label);
        const summary = document.getElementById('raterSummary');
        summary.textContent = labels.length ? labels.join(', ') : '-- Select Raters --';
        if (labels.length) summary.removeAttribute('data-empty'); else summary.setAttribute('data-empty', '1');
    }

    function toggleAllRaters() {
        const boxes = Array.from(document.querySelectorAll('input.rater-check'));
        const allOn = boxes.every(b => b.checked);
        boxes.forEach(b => { b.checked = !allOn; });
        updateRaterSummary();
    }

    document.querySelectorAll('input.rater-check').forEach(b => b.addEventListener('change', updateRaterSummary));
    document.getElementById('scoringForm').addEventListener('reset', () => setTimeout(updateRaterSummary, 0));
    updateRaterSummary();
    // close the dropdown when clicking anywhere else
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
                // Keep the filled-in sheet on screen so it can be printed / saved as PDF
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

    // After a successful save the sheet stays visible (so it can be printed); "New Sheet" clears it
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

    // Editing anything after saving turns the Save button back on
    document.getElementById('scoringForm').addEventListener('input', markEdited);
    document.getElementById('scoringForm').addEventListener('change', markEdited);
</script>

</body>
</html>