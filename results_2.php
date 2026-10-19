<?php
require_once 'db.php';
require_once 'AuthSchema.php';
require_once 'auth.php';

require_admin();
require_once __DIR__ . '/Raters.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

ensure_schema($conn);

// ---------- Load stored rater orders ----------
$storedOrders = [];
try {
    $storedOrders = all_position_rater_orders($conn);
} catch (Exception $e) {
    error_log('results_2 rater order load error: ' . $e->getMessage());
}

$canonicalStoredOrders = [];
foreach ($storedOrders as $off => $positions) {
    foreach ($positions as $pos => $raters) {
        $canonicalPos = canonical_position_title($pos);
        if (!isset($canonicalStoredOrders[$off][$canonicalPos])) {
            $canonicalStoredOrders[$off][$canonicalPos] = [];
        }
        foreach ($raters as $r) {
            if (!in_array($r, $canonicalStoredOrders[$off][$canonicalPos], true)) {
                $canonicalStoredOrders[$off][$canonicalPos][] = $r;
            }
        }
    }
}
$storedOrders = $canonicalStoredOrders;

// ---------- Load all candidate_scores rows ----------
$scoreMap = [];
$sr = $conn->query("SELECT candidate_id, rater, psycho, potential FROM candidate_scores");
if ($sr) {
    while ($sc = $sr->fetch_assoc()) {
        $scoreMap[(int)$sc['candidate_id']][$sc['rater']] = [
            'p' => $sc['psycho'] !== null ? (float)$sc['psycho'] : null,
            't' => $sc['potential'] !== null ? (float)$sc['potential'] : null,
        ];
    }
}

// ---------- Load candidates and group by office ----------
$result = $conn->query("SELECT * FROM candidates ORDER BY office_name ASC, interview_date ASC, id ASC");

$groupedCandidates = [];
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $office = !empty($row['office_name']) ? strtoupper(trim($row['office_name'])) : 'REGIONAL OFFICE';
        $position = canonical_position_title((string)($row['position_title'] ?? 'LMO I'));

        if (!isset($groupedCandidates[$office])) $groupedCandidates[$office] = [];

        $id = (int)$row['id'];

        $legacyP = []; $legacyT = [];
        foreach (['core_avg', 'org_avg', 'psycho_extra1', 'psycho_extra2', 'psycho_extra3', 'psycho_extra4'] as $col) {
            $legacyP[] = isset($row[$col]) && $row[$col] !== null ? (float)$row[$col] : null;
        }
        foreach (['lead_avg', 'func_avg', 'potential_extra1', 'potential_extra2', 'potential_extra3', 'potential_extra4'] as $col) {
            $legacyT[] = isset($row[$col]) && $row[$col] !== null ? (float)$row[$col] : null;
        }

        $groupedCandidates[$office][] = [
            'id'       => $id,
            'name'     => $row['candidate_name'],
            'position' => $position,
            'office'   => $office,
            'date'     => !empty($row['interview_date']) ? $row['interview_date'] : date('F d, Y'),
            'remarks'  => $row['remarks'] ?? '',
            'scores'   => isset($scoreMap[$id]) ? $scoreMap[$id] : [],
            '_legacy'  => ['p' => $legacyP, 't' => $legacyT],
        ];
    }
}

// ---------- Ensure the standard offices exist as tabs ----------
$defaultOffices = ['REGIONAL OFFICE', 'PENRO ILOILO', 'PENRO CAPIZ', 'PENRO AKLAN', 'PENRO ANTIQUE', 'PENRO GUIMARAS', 'PENRO NEGROS OCCIDENTAL'];

$orderedOffices = [];
foreach ($defaultOffices as $defOffice) {
    $orderedOffices[$defOffice] = $groupedCandidates[$defOffice] ?? [];
}
foreach ($groupedCandidates as $office => $list) {
    if (!isset($orderedOffices[$office])) $orderedOffices[$office] = $list;
}
$groupedCandidates = $orderedOffices;

foreach (array_keys($storedOrders) as $storedOffice) {
    if (!isset($groupedCandidates[$storedOffice])) $groupedCandidates[$storedOffice] = [];
}

try {
    foreach (removed_tab_list($conn) as $removedOffice) {
        if (isset($groupedCandidates[$removedOffice]) && count($groupedCandidates[$removedOffice]) === 0) {
            unset($groupedCandidates[$removedOffice]);
        }
    }
} catch (Exception $e) {
    error_log('results_2 removed tabs load error: ' . $e->getMessage());
}

// ---------- Load header labels ----------
$headerLabels = [
    'hash' => 'NO.', 'name' => 'NAME OF APPLICANT / CANDIDATE', 'psychoGroup' => 'PSYCHO-SOCIAL ATTRIBUTES', 'potentialGroup' => 'POTENTIAL ATTRIBUTES',
    'core' => 'CORE', 'org' => 'ORG', 'psychoExtra1' => 'R1', 'psychoExtra2' => 'R2', 'psychoExtra3' => 'R3', 'psychoExtra4' => 'R4',
    'psychoAve' => 'AVE.', 'func' => 'FUNC', 'lead' => 'LEAD',
    'potentialExtra1' => 'R1', 'potentialExtra2' => 'R2', 'potentialExtra3' => 'R3', 'potentialExtra4' => 'R4', 'potentialAve' => 'AVE.', 'remarks' => 'REMARKS'
];

try {
    $settingsResult = $conn->query("SELECT * FROM sheet_settings WHERE id = 1 LIMIT 1");
    $settingsRow = $settingsResult ? $settingsResult->fetch_assoc() : null;
    if ($settingsRow) {
        $headerLabels = [
            'hash'            => $settingsRow['hdr_hash'] ?? 'NO.',
            'name'            => $settingsRow['hdr_name'] ?? 'NAME OF APPLICANT / CANDIDATE',
            'psychoGroup'     => $settingsRow['hdr_psycho_group'] ?? 'PSYCHO-SOCIAL ATTRIBUTES',
            'potentialGroup'  => $settingsRow['hdr_potential_group'] ?? 'POTENTIAL ATTRIBUTES',
            'core'            => $settingsRow['hdr_core'] ?? 'CORE',
            'org'             => $settingsRow['hdr_org'] ?? 'ORG',
            'psychoExtra1'    => $settingsRow['hdr_psycho_extra1'] ?? 'R1',
            'psychoExtra2'    => $settingsRow['hdr_psycho_extra2'] ?? 'R2',
            'psychoExtra3'    => $settingsRow['hdr_psycho_extra3'] ?? 'R3',
            'psychoExtra4'    => $settingsRow['hdr_psycho_extra4'] ?? 'R4',
            'psychoAve'       => $settingsRow['hdr_psycho_ave'] ?? 'AVE.',
            'func'            => $settingsRow['hdr_func'] ?? 'FUNC',
            'lead'            => $settingsRow['hdr_lead'] ?? 'LEAD',
            'potentialExtra1' => $settingsRow['hdr_potential_extra1'] ?? 'R1',
            'potentialExtra2' => $settingsRow['hdr_potential_extra2'] ?? 'R2',
            'potentialExtra3' => $settingsRow['hdr_potential_extra3'] ?? 'R3',
            'potentialExtra4' => $settingsRow['hdr_potential_extra4'] ?? 'R4',
            'potentialAve'    => $settingsRow['hdr_potential_ave'] ?? 'AVE.',
            'remarks'         => $settingsRow['hdr_remarks'] ?? 'REMARKS',
        ];
    }
} catch (Exception $e) {
    // Non-fatal
}

// ============================================================
// FIX (Bug D + Bug 21): canonicalise known labels regardless
// of space/hyphen/underscore variants. Unknown labels are kept
// (no data loss) with whitespace collapsed to a single space.
// ============================================================
function normalize_rater(string $label): ?string {
    $trimmed = trim($label);
    if ($trimmed === '') return null;

    $known = rater_list();
    $upperCompact = preg_replace('/[\s\-_]+/', '', strtoupper($trimmed));

    foreach ($known as $r) {
        if (preg_replace('/[\s\-_]+/', '', strtoupper($r)) === $upperCompact) return $r;
    }

    return preg_replace('/\s+/', ' ', strtoupper($trimmed));
}

// ---------- Migrate legacy scores ----------
$legacyPLabels = [$headerLabels['core'], $headerLabels['org'], $headerLabels['psychoExtra1'], $headerLabels['psychoExtra2'], $headerLabels['psychoExtra3'], $headerLabels['psychoExtra4']];
$legacyTLabels = [$headerLabels['func'], $headerLabels['lead'], $headerLabels['potentialExtra1'], $headerLabels['potentialExtra2'], $headerLabels['potentialExtra3'], $headerLabels['potentialExtra4']];

foreach ($groupedCandidates as $officeKey => $list) {
    foreach ($list as $i => $cand) {
        if (empty($cand['scores'])) {
            $sc = [];
            for ($k = 0; $k < 6; $k++) {
                $rp = normalize_rater((string)$legacyPLabels[$k]);
                $rt = normalize_rater((string)$legacyTLabels[$k]);
                if ($rp !== null && $cand['_legacy']['p'][$k] !== null) $sc[$rp]['p'] = $cand['_legacy']['p'][$k];
                if ($rt !== null && $cand['_legacy']['t'][$k] !== null) $sc[$rt]['t'] = $cand['_legacy']['t'][$k];
            }
            foreach ($sc as $r => $v) {
                $sc[$r] = ['p' => $v['p'] ?? null, 't' => $v['t'] ?? null];
            }
            $groupedCandidates[$officeKey][$i]['scores'] = $sc;
        }
        unset($groupedCandidates[$officeKey][$i]['_legacy']);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="chrome" content="nofill">
    <title>Master Interview Summary Rating Sheet</title>
    <style>
        * { box-sizing: border-box; }
        body { 
            font-family: 'Segoe UI', Calibri, Arial, sans-serif; 
            margin: 0; 
            padding: 15px; 
            background-color: #f3f3f3; 
            color: #000; 
            font-size: 12px;
        }

        .excel-tabs-container {
            display: flex;
            align-items: flex-end;
            gap: 5px;
            background-color: #e1e1e1;
            border: 1px solid #b0b0b0;
            border-bottom: none;
            padding: 6px 8px 0 8px;
            border-radius: 4px 4px 0 0;
        }

        #tabs-bar {
            display: flex;
            align-items: flex-end;
            gap: 3px;
            flex: 1;
            overflow-x: auto;
        }

        .excel-tab {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            background-color: #d6d6d6;
            border: 1px solid #a6a6a6;
            border-bottom: none;
            border-radius: 4px 4px 0 0;
            cursor: pointer;
            font-size: 11px;
            font-weight: 600;
            color: #444;
            user-select: none;
            white-space: nowrap;
        }

        .excel-tab.active {
            background-color: #ffffff;
            color: #107c41;
            border-top: 3px solid #107c41;
            border-bottom: 1px solid #ffffff;
            font-weight: bold;
        }

        .tab-close-btn {
            visibility: visible;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 18px;
            height: 18px;
            line-height: 1;
            border-radius: 50%;
            background: rgba(220, 53, 69, 0.15);
            color: #c0392b;
            font-weight: bold;
            font-size: 13px;
            margin-left: 6px;
            cursor: pointer;
            user-select: none;
            flex-shrink: 0;
            transition: background 0.15s, color 0.15s;
        }
        .tab-close-btn:hover {
            background: #c0392b;
            color: #fff;
        }

        .excel-tab-add {
            padding: 4px 10px;
            background-color: #ffffff;
            border: 1px solid #a6a6a6;
            border-radius: 3px;
            cursor: pointer;
            font-weight: bold;
            color: #107c41;
            margin-bottom: 2px;
        }

        .tab-search { display: flex; align-items: center; gap: 4px; padding-bottom: 2px; }
        .tab-search input {
            padding: 4px 8px;
            border: 1px solid #8a8a8a;
            border-radius: 2px;
            font-size: 11px;
            width: 180px;
        }

        .table-container { 
            display: none; 
            background: #ffffff;
            padding: 25px 30px;
            border: 1px solid #b0b0b0;
            box-shadow: 0 4px 10px rgba(0,0,0,0.08);
            margin-bottom: 20px;
        }
        .table-container.active { display: block; }

        .table-scroll {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .doc-header {
            text-align: center;
            margin-bottom: 15px;
            text-transform: uppercase;
            line-height: 1.3;
        }
        .doc-header .gov-title { font-size: 11px; font-weight: 600; color: #333; }
        .doc-header .agency-title { font-size: 13px; font-weight: bold; color: #000; }
        .doc-header .office-sub { font-size: 11px; font-weight: bold; color: #222; }
        .doc-header .doc-name { font-size: 14px; font-weight: 800; color: #107c41; margin-top: 5px; text-decoration: underline; }

        .table-block { margin-bottom: 30px; }
        
        .position-banner {
            background-color: #107c41;
            color: #ffffff;
            font-weight: bold;
            font-size: 12px;
            padding: 6px 12px;
            border: 1px solid #000000;
            border-bottom: none;
            letter-spacing: 0.5px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
        }
        .position-banner .banner-left { flex: 1; min-width: 0; }
        .position-banner .banner-right {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-shrink: 0;
        }

        .pos-delete-btn {
            background: rgba(255, 255, 255, 0.2);
            color: #fff;
            border: 1px solid rgba(255, 255, 255, 0.5);
            border-radius: 50%;
            width: 26px;
            height: 26px;
            font-size: 16px;
            font-weight: bold;
            line-height: 1;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0;
            transition: background 0.15s, transform 0.15s;
        }
        .pos-delete-btn:hover {
            background: #c0392b;
            border-color: #c0392b;
            transform: scale(1.05);
        }

        .excel-table { 
            border-collapse: collapse; 
            width: 100%; 
            min-width: 900px;
            font-size: 11px;
            border: 2px solid #000000;
        }
        
        .excel-table th, .excel-table td { 
            border: 1px solid #000000; 
            padding: 5px 4px; 
            text-align: center; 
            vertical-align: middle;
        }

        .meta-row { background-color: #f2f2f2; font-weight: bold; }
        .header-psycho { background-color: #e2efda; font-weight: bold; color: #276a3c; }
        .header-potential { background-color: #fff2cc; font-weight: bold; color: #7f6000; }
        .sub-header th { background-color: #ffffff; font-weight: bold; font-size: 10px; }
        
        .col-green-ave { background-color: #c6e0b4; font-weight: bold; }
        .col-yellow-ave { background-color: #ffe699; font-weight: bold; }
        .sub-header th.col-green-ave  { background-color: #c6e0b4; }
        .sub-header th.col-yellow-ave { background-color: #ffe699; }
        .rater-remove { cursor: pointer; color: #c0392b; font-weight: bold; margin-left: 3px; opacity: .55; }
        .rater-remove:hover { opacity: 1; }
        .rater-add { border: 1px dashed #888; background: #fff; font-size: 10px; width: 100%; cursor: pointer; }

        .text-left { text-align: left !important; padding-left: 8px !important; }
        .remarks-cell { min-width: 140px; text-align: left; }

        th[contenteditable="true"], td[contenteditable="true"] { outline: none; cursor: cell; }
        th[contenteditable="true"]:focus, td[contenteditable="true"]:focus {
            outline: 2px solid #107c41 !important;
            background-color: #e8f5e9 !important;
        }

        .row-num-cell {
            background-color: #f2f2f2;
            font-weight: bold;
            width: 30px;
            cursor: pointer;
        }
        .row-num-cell:hover .row-number { display: none; }
        .delete-icon { display: none; color: #d9534f; font-weight: bold; font-size: 14px; }
        .row-num-cell:hover .delete-icon { display: inline; }

        .signatory-section {
            margin-top: 35px;
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            page-break-inside: avoid;
        }
        .sig-box { text-align: center; }
        .sig-line { margin-top: 40px; border-bottom: 1px solid #000000; width: 80%; margin-left: auto; margin-right: auto; }
        .sig-title { font-weight: bold; margin-top: 4px; font-size: 11px; }
        .sig-sub { font-size: 10px; color: #555; }

        .action-bar {
            margin-top: 15px;
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .btn {
            padding: 6px 14px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            background-color: #ffffff;
            border: 1px solid #7f7f7f;
            border-radius: 3px;
            text-decoration: none;
            color: #000;
        }
        .btn:hover { background-color: #e6e6e6; }
        .btn-primary { background-color: #107c41; color: #fff; border-color: #107c41; }
        .btn-primary:hover { background-color: #0b5c30; }

        @media (max-width: 768px) {
            body { padding: 6px; font-size: 11px; }
            .excel-tabs-container { flex-wrap: wrap; padding: 6px 6px 0 6px; }
            #tabs-bar { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; }
            .excel-tab { padding: 5px 10px; font-size: 10px; }
            .tab-search { width: 100%; padding-bottom: 6px; }
            .tab-search input { flex: 1; width: auto; font-size: 11px; }
            .table-container { padding: 10px 8px; }
            .doc-header .gov-title, .doc-header .office-sub { font-size: 9px; }
            .doc-header .agency-title { font-size: 11px; }
            .doc-header .doc-name { font-size: 12px; }
            .position-banner { flex-direction: column; gap: 6px; text-align: left; font-size: 10px; padding: 6px 8px; }
            .position-banner .banner-right { width: 100%; justify-content: space-between; }
            .excel-table { font-size: 10px; }
            .excel-table th, .excel-table td { padding: 4px 3px; }
            .row-num-cell { width: 26px; }
            .action-bar { flex-wrap: wrap; gap: 6px; }
            .btn { padding: 8px 12px; font-size: 11px; }
            .signatory-section { grid-template-columns: 1fr; gap: 30px; }
            .tab-close-btn { width: 22px; height: 22px; font-size: 15px; margin-left: 8px; }
        }

        @media print {
            @page { size: landscape; margin: 0.4in; }
            body { background: #fff; padding: 0; }
            .no-print, .excel-tabs-container, .action-bar, .rater-add,
            .pos-delete-btn, .tab-close-btn, .rater-remove { display: none !important; }
            .table-container { display: block !important; border: none; box-shadow: none; padding: 0; page-break-after: always; }
            .table-block { page-break-inside: avoid; }
            .table-scroll { overflow: visible !important; }
            .excel-table { min-width: 0 !important; }
            .row-num-cell:hover .row-number { display: inline !important; }
            .row-num-cell:hover .delete-icon { display: none !important; }
        }
    </style>
</head>
<body>

<form id="resultsForm" autocomplete="off" onsubmit="return false;">

    <div class="excel-tabs-container">
        <div id="tabs-bar"></div>
        <div class="tab-search">
            <input type="text" id="candidateSearch" placeholder="Search applicant name..." autocomplete="off">
            <button type="button" class="btn" onclick="searchCandidate()">🔍 Find</button>
        </div>
    </div>

    <div id="tables-wrapper"></div>

    <div class="action-bar no-print">
        <button type="button" class="btn btn-primary" onclick="saveWholeTable(event)">💾 Save All Changes</button>
        <button type="button" class="btn" onclick="window.print()">🖨️ Print / Save as PDF</button>
        <a href="manage_users.php" class="btn">👥 Manage Users</a>
        <a href="logout.php" class="btn" style="background-color: #dc3545; color: white; border-color: #dc3545;">🚪 Logout</a>
    </div>

</form>

    <script>
        const groupedData = <?php echo json_encode($groupedCandidates, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        let headerLabels = <?php echo json_encode($headerLabels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        let activeTabOffice = 'REGIONAL OFFICE';
        const RATERS = <?php echo json_encode(rater_list(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const MIN_SLOTS = 7;
        const CSRF_TOKEN = <?php echo json_encode(csrf_token()); ?>;
        const hiddenTabs = new Set();
        const pendingRenames = [];
        let skipNextSync = false;
        const addedRaters = {};
        const storedOrders = <?php echo json_encode($storedOrders, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

        window.__positionsToDelete = window.__positionsToDelete || [];

        function escapeHtml(str) {
            return String(str ?? '').replace(/[&<>"']/g, ch => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
            }[ch]));
        }

        function calculateAverage(...scores) {
            const validScores = scores.filter(s => s !== null && !isNaN(s) && s !== '' && s !== undefined);
            if (validScores.length === 0) return "#DIV/0!";
            const sum = validScores.reduce((acc, val) => acc + parseFloat(val), 0);
            return (sum / validScores.length).toFixed(2);
        }

        function formatVal(val) {
            if (val === null || val === undefined || isNaN(val) || val === '') return '';
            return Number(val).toFixed(2);
        }

        function updateHeaderLabel(el, key) {
            headerLabels[key] = el.innerText.replace(/[\r\n\t]/g, '').trim();
            renderAll();
        }

        function blockKey(office, position, date) { return office + '|' + position + '|' + date; }

        function raterColumns(cands, key, office, position) {
            const stored = ((storedOrders[office] || {})[position]) || [];
            const added = addedRaters[key] || [];
            const ordered = [];
            stored.concat(added).forEach(r => { if (!ordered.includes(r)) ordered.push(r); });

            const scored = new Set();
            cands.forEach(c => Object.keys(c.scores || {}).forEach(r => scored.add(r)));
            const rest = Array.from(scored).filter(r => !ordered.includes(r));
            const known = RATERS.filter(r => rest.includes(r));
            const other = rest.filter(r => !RATERS.includes(r));
            return ordered.concat(known, other);
        }

        function buildRowHtml(cand = null, rowNumber = 1, cols = [], slots = MIN_SLOTS) {
            const id = cand ? cand.id : '';
            const name = cand ? escapeHtml(cand.name) : '';
            const remarks = cand ? escapeHtml(cand.remarks) : '';

            let pCells = '', tCells = '';
            const pVals = [], tVals = [];
            for (let i = 0; i < slots; i++) {
                const r = cols[i];
                if (r) {
                    const sc = (cand && cand.scores && cand.scores[r]) || {};
                    pVals.push(sc.p); tVals.push(sc.t);
                    pCells += `<td contenteditable="true" data-kind="p" data-rater="${escapeHtml(r)}">${formatVal(sc.p)}</td>`;
                    tCells += `<td contenteditable="true" data-kind="t" data-rater="${escapeHtml(r)}">${formatVal(sc.t)}</td>`;
                } else {
                    pCells += '<td></td>';
                    tCells += '<td></td>';
                }
            }

            const psychoAve = cand ? calculateAverage(...pVals) : '#DIV/0!';
            const potentialAve = cand ? calculateAverage(...tVals) : '#DIV/0!';

            return `
                <tr data-id="${id}">
                    <td class="row-num-cell" onclick="handleRowDelete(this)">
                        <span class="row-number">${rowNumber}</span>
                        <span class="delete-icon" title="Delete Row">×</span>
                    </td>
                    <td class="text-left" contenteditable="true" data-field="name">${name}</td>
                    ${pCells}
                    <td class="col-green-ave psycho-ave">${psychoAve}</td>
                    ${tCells}
                    <td class="col-yellow-ave potential-ave">${potentialAve}</td>
                    <td class="text-left remarks-cell" contenteditable="true" data-field="remarks">${remarks}</td>
                </tr>
            `;
        }

        function buildTableHeaderHtml(currentDate, branch, cols, slots, free) {
            const addSelect = `<select class="rater-add" onchange="addRater(this)" title="Add a rater column"><option value="">+</option>${free.map(r => `<option value="${escapeHtml(r)}">${escapeHtml(r)}</option>`).join('')}</select>`;
            const raterHdr = () => {
                let h = '';
                for (let i = 0; i < slots; i++) {
                    if (cols[i]) h += `<th style="width: 62px;">${escapeHtml(cols[i])}<span class="rater-remove" data-rater="${escapeHtml(cols[i])}" title="Remove this column" onclick="removeRater(this)">×</span></th>`;
                    else if (i === cols.length && free.length) h += `<th style="width: 62px;">${addSelect}</th>`;
                    else h += '<th style="width: 62px;"></th>';
                }
                return h;
            };
            const totalCols = 2 + (slots + 1) * 2 + 1;

            return `
                <tr class="meta-row">
                    <td colspan="3" class="text-left" contenteditable="true" data-field="interview_date">
                        <b>DATE OF INTERVIEW:</b> ${escapeHtml(currentDate)}
                    </td>
                    <td colspan="${totalCols - 3}" style="text-align: center; font-weight: bold;" contenteditable="true" data-branch-title="true" onblur="renameBranchTab(this)">
                        OFFICE/BRANCH: ${escapeHtml(branch)}
                    </td>
                </tr>
                <tr>
                    <th style="width: 30px;">${escapeHtml(headerLabels.hash)}</th>
                    <th style="width: 230px;" contenteditable="true" onblur="updateHeaderLabel(this,'name')">${escapeHtml(headerLabels.name)}</th>
                    <th colspan="${slots + 1}" class="header-psycho" contenteditable="true" onblur="updateHeaderLabel(this,'psychoGroup')">${escapeHtml(headerLabels.psychoGroup)}</th>
                    <th colspan="${slots + 1}" class="header-potential" contenteditable="true" onblur="updateHeaderLabel(this,'potentialGroup')">${escapeHtml(headerLabels.potentialGroup)}</th>
                    <th rowspan="2" style="width: 160px;" contenteditable="true" onblur="updateHeaderLabel(this,'remarks')">${escapeHtml(headerLabels.remarks)}</th>
                </tr>
                <tr class="sub-header">
                    <th></th>
                    <th></th>
                    ${raterHdr()}
                    <th class="col-green-ave" style="width: 55px;" contenteditable="true" onblur="updateHeaderLabel(this,'psychoAve')">${escapeHtml(headerLabels.psychoAve)}</th>
                    ${raterHdr()}
                    <th class="col-yellow-ave" style="width: 55px;" contenteditable="true" onblur="updateHeaderLabel(this,'potentialAve')">${escapeHtml(headerLabels.potentialAve)}</th>
                </tr>
            `;
        }

        function buildTableBlockHtml(candidates, currentDate, branch, posKey) {
            const cols = raterColumns(candidates, blockKey(branch, posKey, currentDate), branch, posKey);
            const slots = Math.max(MIN_SLOTS, cols.length + 1);
            const free = RATERS.filter(r => !cols.includes(r));

            let rowsHtml = '';
            const minRows = Math.max(5, candidates.length);
            for (let i = 0; i < minRows; i++) {
                rowsHtml += buildRowHtml(candidates[i] || null, i + 1, cols, slots);
            }

            const positionAttr = posKey ? ` data-position="${escapeHtml(posKey)}"` : '';
            const escOffice = escapeHtml(branch).replace(/'/g, "\\'").replace(/"/g, '&quot;');
            const escPos = escapeHtml(posKey).replace(/'/g, "\\'").replace(/"/g, '&quot;');

            return `
                <div class="table-block"${positionAttr}>
                    <div class="position-banner">
                        <span class="banner-left">POSITION TITLE: ${escapeHtml(posKey)}</span>
                        <span class="banner-right">
                            <span>OFFICE: ${escapeHtml(branch)}</span>
                            <button type="button"
                                    class="pos-delete-btn no-print"
                                    title="Delete this entire position table"
                                    onclick="deletePositionGroup(this, &quot;${escOffice}&quot;, &quot;${escPos}&quot;)">×</button>
                        </span>
                    </div>
                    <div class="table-scroll">
                        <table class="excel-table">
                            <thead>
                                ${buildTableHeaderHtml(currentDate, branch, cols, slots, free)}
                            </thead>
                            <tbody class="scores-body" data-cols="${escapeHtml(JSON.stringify(cols))}" data-slots="${slots}">
                                ${rowsHtml}
                            </tbody>
                        </table>
                    </div>
                </div>
            `;
        }

        function buildSignatoryBlock() {
            return `
                <div class="signatory-section">
                    <div class="sig-box">
                        <div class="sig-line"></div>
                        <div class="sig-title">HRMPSB SECRETARIAT</div>
                        <div class="sig-sub">Prepared By</div>
                    </div>
                    <div class="sig-box">
                        <div class="sig-line"></div>
                        <div class="sig-title">HRMPSB COMMITTEE MEMBER</div>
                        <div class="sig-sub">Attested By</div>
                    </div>
                    <div class="sig-box">
                        <div class="sig-line"></div>
                        <div class="sig-title">CHAIRPERSON, HRMPSB</div>
                        <div class="sig-sub">Approved By</div>
                    </div>
                </div>
            `;
        }

        function checkAndAutoAddRow(tbody) {
            const rows = tbody.querySelectorAll('tr');
            if (rows.length === 0) return;
            const lastRow = rows[rows.length - 1];
            const nameCell = lastRow.querySelector('[data-field="name"]');

            if (nameCell && nameCell.innerText.trim() !== "") {
                const cols = JSON.parse(tbody.dataset.cols || '[]');
                const slots = parseInt(tbody.dataset.slots, 10) || MIN_SLOTS;
                tbody.insertAdjacentHTML('beforeend', buildRowHtml(null, rows.length + 1, cols, slots));
            }
        }

        function readDate(block) {
            const el = block.querySelector('[data-field="interview_date"]');
            const t = el ? el.innerText.replace('DATE OF INTERVIEW:', '').replace(/[\r\n\t]/g, ' ').trim() : '';
            return t || "<?php echo date('F d, Y'); ?>";
        }

        function collectRows() {
            const out = [];
            document.querySelectorAll('.table-container').forEach(container => {
                const office = container.getAttribute('data-office') || 'REGIONAL OFFICE';
                container.querySelectorAll('.table-block').forEach(block => {
                    const position = block.getAttribute('data-position') || 'UNASSIGNED';
                    const date = readDate(block);
                    block.querySelectorAll('.scores-body tr').forEach(row => {
                        const id = parseInt(row.getAttribute('data-id') || '0', 10) || 0;
                        const name = (row.querySelector('[data-field="name"]')?.innerText || '').replace(/[\r\n\t]/g, '').trim();
                        if (!(id > 0 || name !== '')) return;
                        const scores = {};
                        row.querySelectorAll('td[data-rater]').forEach(td => {
                            const r = td.getAttribute('data-rater');
                            const v = td.innerText.trim();
                            if (!scores[r]) scores[r] = { p: null, t: null };
                            if (v !== '' && !isNaN(parseFloat(v))) scores[r][td.getAttribute('data-kind')] = parseFloat(v);
                        });
                        out.push({
                            row, office, position, date, id, name, scores,
                            remarks: (row.querySelector('[data-field="remarks"]')?.innerText || '').replace(/[\r\n\t]/g, ' ').trim()
                        });
                    });
                });
            });
            return out;
        }

        function syncFromDom() {
            const containers = document.querySelectorAll('.table-container');
            if (!containers.length) return;
            const fresh = {};
            collectRows().forEach(r => {
                if (!(r.office in groupedData)) return;
                (fresh[r.office] = fresh[r.office] || []).push({
                    id: r.id, name: r.name, position: r.position, office: r.office,
                    date: r.date, remarks: r.remarks, scores: r.scores
                });
            });
            containers.forEach(c => {
                const o = c.getAttribute('data-office');
                if (o in groupedData) groupedData[o] = fresh[o] || [];
            });
        }

        function addRater(sel) {
            const r = sel.value;
            if (!r) return;
            const block = sel.closest('.table-block');
            const office = block.closest('.table-container').getAttribute('data-office');
            const key = blockKey(office, block.getAttribute('data-position') || 'UNASSIGNED', readDate(block));
            if (!addedRaters[key]) addedRaters[key] = [];
            if (!addedRaters[key].includes(r)) addedRaters[key].push(r);
            renderAll();
        }

        function removeRater(el) {
            const r = el.getAttribute('data-rater');
            const block = el.closest('.table-block');
            const office = block.closest('.table-container').getAttribute('data-office');
            const position = block.getAttribute('data-position') || 'UNASSIGNED';
            if (!confirm('Remove the "' + r + '" column for position "' + position + '"?\n\nIts scores will be deleted when you click Save All Changes.')) return;

            syncFromDom();
            (groupedData[office] || []).forEach(c => {
                const pos = (c.position && c.position.trim() !== '') ? c.position.trim() : 'UNASSIGNED';
                if (pos === position && c.scores) delete c.scores[r];
            });
            if (storedOrders[office] && storedOrders[office][position]) {
                storedOrders[office][position] = storedOrders[office][position].filter(x => x !== r);
            }
            Object.keys(addedRaters).forEach(k => {
                if (k.indexOf(office + '|' + position + '|') === 0) addedRaters[k] = addedRaters[k].filter(x => x !== r);
            });
            skipNextSync = true;
            renderAll();
        }

        function deletePositionGroup(btnEl, office, position) {
            syncFromDom();

            const list = groupedData[office] || [];
            const matching = list.filter(c => {
                const pos = (c.position && c.position.trim() !== '') ? c.position.trim() : 'UNASSIGNED';
                return pos === position;
            });

            const savedCount = matching.filter(c => c.id > 0).length;
            const msg = savedCount > 0
                ? `Delete the entire "${position}" table for ${office}?\n\nThis will remove ${savedCount} saved applicant(s) and all of their scores, plus every rater column for this position.`
                : `Delete the entire "${position}" table for ${office}?`;
            if (!confirm(msg)) return;

            groupedData[office] = list.filter(c => {
                const pos = (c.position && c.position.trim() !== '') ? c.position.trim() : 'UNASSIGNED';
                return pos !== position;
            });

            if (storedOrders[office] && storedOrders[office][position]) {
                delete storedOrders[office][position];
            }
            Object.keys(addedRaters).forEach(k => {
                if (k.indexOf(office + '|' + position + '|') === 0) delete addedRaters[k];
            });

            window.__positionsToDelete.push({ office: office, position: position });

            skipNextSync = true;
            renderAll();

            if (savedCount > 0) {
                alert('Removed from view.\n\nClick "Save All Changes" to permanently delete from the database.');
            }
        }

        function renderAll() {
            if (!skipNextSync) syncFromDom();
            skipNextSync = false;

            const tabsBar = document.getElementById('tabs-bar');
            const wrapper = document.getElementById('tables-wrapper');

            tabsBar.innerHTML = '';
            wrapper.innerHTML = '';

            const branchKeys = Object.keys(groupedData);
            if (branchKeys.length > 0 && !branchKeys.includes(activeTabOffice)) {
                activeTabOffice = branchKeys[0];
            }

            branchKeys.forEach(branch => {
                const tab = document.createElement('div');
                tab.className = `excel-tab ${branch === activeTabOffice ? 'active' : ''}`;
                tab.onclick = () => switchTab(branch);

                const tabLabel = document.createElement('span');
                tabLabel.innerText = branch;
                tab.appendChild(tabLabel);

                const closeBtn = document.createElement('span');
                closeBtn.className = 'tab-close-btn';
                closeBtn.innerText = '×';
                closeBtn.title = 'Delete this tab';
                closeBtn.onclick = (e) => {
                    e.stopPropagation();
                    e.preventDefault();
                    deleteTab(branch);
                };
                tab.appendChild(closeBtn);
                tabsBar.appendChild(tab);

                const candidates = groupedData[branch] || [];

                const container = document.createElement('div');
                container.className = `table-container ${branch === activeTabOffice ? 'active' : ''}`;
                container.setAttribute('data-office', branch);

                let docHeaderHtml = `
                    <div class="doc-header">
                        <div class="gov-title">Republic of the Philippines</div>
                        <div class="agency-title">DEPARTMENT OF ENVIRONMENT AND NATURAL RESOURCES</div>
                        <div class="office-sub">${escapeHtml(branch)}</div>
                        <div class="doc-name">MASTER INTERVIEW SUMMARY RATING SHEET</div>
                    </div>
                `;

                const positionGroups = {};
                candidates.forEach(cand => {
                    const posKey = (cand.position && cand.position.trim() !== '') ? cand.position.trim() : 'UNASSIGNED';
                    if (!positionGroups[posKey]) positionGroups[posKey] = [];
                    positionGroups[posKey].push(cand);
                });

                if (Object.keys(positionGroups).length === 0) {
                    positionGroups['UNASSIGNED'] = [];
                }

                let stackHtml = docHeaderHtml;
                Object.keys(positionGroups).forEach(posKey => {
                    const candsForPos = positionGroups[posKey];

                    const dateGroups = {};
                    candsForPos.forEach(cand => {
                        const dateKey = (cand.date && cand.date.trim() !== '') ? cand.date.trim() : "<?php echo date('F d, Y'); ?>";
                        if (!dateGroups[dateKey]) dateGroups[dateKey] = [];
                        dateGroups[dateKey].push(cand);
                    });
                    if (Object.keys(dateGroups).length === 0) {
                        dateGroups["<?php echo date('F d, Y'); ?>"] = [];
                    }

                    Object.keys(dateGroups).forEach(dateKey => {
                        const candsForDate = dateGroups[dateKey];
                        stackHtml += buildTableBlockHtml(candsForDate, dateKey, branch, posKey);
                    });
                });

                stackHtml += buildSignatoryBlock();
                container.innerHTML = stackHtml;
                wrapper.appendChild(container);

                const tbodies = container.querySelectorAll('.scores-body');
                tbodies.forEach(tbody => checkAndAutoAddRow(tbody));
            });

            const addBtn = document.createElement('button');
            addBtn.className = 'excel-tab-add';
            addBtn.innerText = '+ Add Tab';
            addBtn.onclick = promptNewTab;
            tabsBar.appendChild(addBtn);
        }

        function switchTab(branch) {
            activeTabOffice = branch;
            renderAll();
        }

        function searchCandidate() {
            const query = document.getElementById('candidateSearch').value.trim().toLowerCase();
            if (query === '') return;

            for (const branch of Object.keys(groupedData)) {
                const match = (groupedData[branch] || []).find(
                    c => c.name && c.name.toLowerCase().includes(query)
                );
                if (match) {
                    activeTabOffice = branch;
                    renderAll();

                    setTimeout(() => {
                        const rows = document.querySelectorAll('.scores-body tr');
                        for (const row of rows) {
                            const nameCell = row.querySelector('[data-field="name"]');
                            if (nameCell && nameCell.innerText.trim().toLowerCase().includes(query)) {
                                row.scrollIntoView({ behavior: 'smooth', block: 'center' });
                                row.style.backgroundColor = '#fff3b0';
                                setTimeout(() => { row.style.backgroundColor = ''; }, 2500);
                                break;
                            }
                        }
                    }, 50);
                    return;
                }
            }
            alert('Candidate not found.');
        }

        function deleteTab(branch) {
            syncFromDom();
            const saved = (groupedData[branch] || []).filter(c => c.id > 0).length;
            const msg = saved > 0
                ? `Delete the "${branch}" tab?\n\nThis permanently deletes ${saved} saved applicant(s) and all of their scores from the database.`
                : `Delete the "${branch}" tab?`;
            if (!confirm(msg)) return;

            fetch('delete_tab.php', {
                method: 'POST',
                credentials: 'same-origin',
                body: new URLSearchParams({ office: branch, csrf_token: CSRF_TOKEN })
            })
            .then(res => res.text().then(text => {
                let data;
                try { data = JSON.parse(text); }
                catch (e) { throw new Error('Unexpected server reply: ' + text.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 300)); }
                return data;
            }))
            .then(data => {
                if (data.status !== 'success') {
                    alert('Delete failed - the tab was NOT deleted.\n' + (data.message || 'Unknown error'));
                    return;
                }
                delete groupedData[branch];
                delete storedOrders[branch];
                Object.keys(addedRaters).forEach(k => { if (k.indexOf(branch + '|') === 0) delete addedRaters[k]; });
                const remaining = Object.keys(groupedData);
                activeTabOffice = remaining.length > 0 ? remaining[0] : '';
                renderAll();
            })
            .catch(err => alert('Delete failed - the tab was NOT deleted.\n' + err.message));
        }

        function promptNewTab() {
            const name = prompt("Enter new PENRO or Branch Name:");
            if (name && name.trim() !== "") {
                const cleanName = name.trim().toUpperCase();
                if (!groupedData[cleanName]) {
                    groupedData[cleanName] = [];
                    activeTabOffice = cleanName;
                    renderAll();
                } else {
                    switchTab(cleanName);
                }
            }
        }

        // ============================================================
        // FIX (Bug 50): sync DOM into groupedData BEFORE moving the
        // branch key, then skip the redundant re-sync in renderAll().
        // FIX (Bug 5):  record the rename in pendingRenames so
        //               save_score.php can UPDATE candidates rows.
        // ============================================================
        function renameBranchTab(el) {
            const container = el.closest('.table-container');
            const oldBranch = container.getAttribute('data-office');
            const newBranch = el.innerText.replace('OFFICE/BRANCH:', '').replace(/[\r\n\t]/g, '').trim().toUpperCase();

            if (!newBranch || newBranch === oldBranch) {
                el.innerText = 'OFFICE/BRANCH: ' + oldBranch;
                return;
            }
            if (groupedData[newBranch]) {
                alert('A tab named "' + newBranch + '" already exists.');
                el.innerText = 'OFFICE/BRANCH: ' + oldBranch;
                return;
            }

            syncFromDom();
            pendingRenames.push({ from: oldBranch, to: newBranch });

            groupedData[newBranch] = groupedData[oldBranch] || [];
            hiddenTabs.add(oldBranch);
            delete groupedData[oldBranch];
            if (activeTabOffice === oldBranch) activeTabOffice = newBranch;

            skipNextSync = true;
            renderAll();
        }

        function handleRowDelete(cellEl) {
            const row = cellEl.closest('tr');
            const tbody = row.closest('tbody');
            const id = row.getAttribute('data-id');

            if (confirm('Delete this applicant row?')) {
                if (id && parseInt(id) > 0) {
                    fetch('delete_candidate.php', { method: 'POST', credentials: 'same-origin', body: new URLSearchParams({ id: id, csrf_token: CSRF_TOKEN }) })
                    .then(res => res.text().then(text => {
                        let data;
                        try { data = JSON.parse(text); }
                        catch (e) { throw new Error('Unexpected server reply: ' + text.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 300)); }
                        return data;
                    }))
                    .then(data => {
                        if (data.status === 'success') {
                            row.remove();
                            reindexTableRows(tbody);
                        } else {
                            alert('Delete failed - the row was NOT deleted.\n' + (data.message || 'Unknown error'));
                        }
                    })
                    .catch(err => alert('Delete failed - the row was NOT deleted.\n' + err.message));
                } else {
                    row.remove();
                    reindexTableRows(tbody);
                }
            }
        }

        function reindexTableRows(tbody) {
            Array.from(tbody.children).forEach((tr, index) => {
                const numSpan = tr.querySelector('.row-number');
                if (numSpan) numSpan.innerText = index + 1;
            });
            checkAndAutoAddRow(tbody);
        }

        function recalculateRow(row) {
            const pv = Array.from(row.querySelectorAll('td[data-kind="p"]')).map(td => td.innerText.trim());
            const tv = Array.from(row.querySelectorAll('td[data-kind="t"]')).map(td => td.innerText.trim());
            const psychoAveEl = row.querySelector('.psycho-ave');
            const potentialAveEl = row.querySelector('.potential-ave');
            if (psychoAveEl) psychoAveEl.innerText = calculateAverage(...pv);
            if (potentialAveEl) potentialAveEl.innerText = calculateAverage(...tv);
        }

        function saveWholeTable(e) {
            if (e) e.preventDefault();

            const candidatesToSave = collectRows().map((r, i) => {
                r.row.setAttribute('data-temp-index', i);
                return {
                    tempIndex: i,
                    id: r.id,
                    name: r.name,
                    office: r.office,
                    position: r.position,
                    interviewDate: r.date,
                    remarks: r.remarks,
                    scores: r.scores
                };
            });

            const columnsToSave = [];
            document.querySelectorAll('.table-container').forEach(container => {
                const office = container.getAttribute('data-office') || 'REGIONAL OFFICE';
                container.querySelectorAll('.table-block').forEach(block => {
                    const position = block.getAttribute('data-position') || 'UNASSIGNED';
                    const tbody = block.querySelector('.scores-body');
                    let raters = null;
                    try { raters = JSON.parse((tbody && tbody.dataset.cols) || '[]'); } catch (err) { raters = null; }
                    if (Array.isArray(raters)) columnsToSave.push({ office: office, position: position, raters: raters });
                });
            });

            const tabsToSave = Array.from(document.querySelectorAll('.table-container'))
                .map(c => c.getAttribute('data-office')).filter(Boolean);

            fetch('save_score.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    csrf_token: CSRF_TOKEN,
                    candidates: candidatesToSave,
                    columns: columnsToSave,
                    tabs: tabsToSave,
                    hiddenTabs: Array.from(hiddenTabs),
                    renames: pendingRenames,
                    headers: headerLabels,
                    positionsToDelete: window.__positionsToDelete
                })
            })
            .then(res => res.text().then(text => {
                let data;
                try { data = JSON.parse(text); }
                catch (e) { throw new Error('Server returned non-JSON. First 300 chars: ' + text.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 300)); }
                return data;
            }))
            .then(data => {
                if (data.status === 'success') {
                    if (data.savedIds) {
                        data.savedIds.forEach(item => {
                            const targetRow = document.querySelector(`tr[data-temp-index="${item.tempIndex}"]`);
                            if (targetRow) targetRow.setAttribute('data-id', item.id);
                        });
                    }
                    window.__positionsToDelete = [];
                    pendingRenames.length = 0;

                    let msg = 'All interview rating sheets saved successfully!';
                    if (typeof data.savedColumns === 'number') {
                        msg += '\n' + data.savedColumns + ' rater column(s) stored.';
                    }
                    if (typeof data.deletedPositions === 'number' && data.deletedPositions > 0) {
                        msg += '\n' + data.deletedPositions + ' position table(s) permanently deleted.';
                    }
                    if (typeof data.renamedOffices === 'number' && data.renamedOffices > 0) {
                        msg += '\n' + data.renamedOffices + ' office(s) renamed.';
                    }
                    alert(msg);
                } else {
                    alert('Error saving: ' + (data.message || 'Unknown error'));
                }
            })
            .catch(err => alert('Save request failed: ' + err.message));
        }

        document.addEventListener('DOMContentLoaded', function() {
            renderAll();

            document.getElementById('candidateSearch')?.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') { e.preventDefault(); searchCandidate(); }
            });

            document.addEventListener('input', function(e) {
                if (e.target.isContentEditable) {
                    const row = e.target.closest('tr');
                    if (row) {
                        const isAveCell = e.target.classList.contains('psycho-ave') || e.target.classList.contains('potential-ave');
                        if (!isAveCell) recalculateRow(row);
                    }
                    const tbody = e.target.closest('.scores-body');
                    if (tbody) checkAndAutoAddRow(tbody);
                }
            });

            document.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' && e.target.isContentEditable) {
                    e.preventDefault();
                    e.target.blur();
                    const row = e.target.closest('tr');
                    if (row) {
                        const cellIndex = Array.from(row.children).indexOf(e.target);
                        const nextRow = row.nextElementSibling;
                        if (nextRow && nextRow.children[cellIndex]) {
                            nextRow.children[cellIndex].focus();
                        }
                    }
                }
            });
        });
    </script>
</body>
</html>
