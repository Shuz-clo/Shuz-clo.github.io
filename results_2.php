<?php
require_once __DIR__ . '/bootstrap.php';
require_once 'db.php';
require_once __DIR__ . '/AuthSchema.php';
require_once 'auth.php';

require_admin();
require_once __DIR__ . '/Raters.php';
require_once __DIR__ . '/competency_template_schema.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

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
        $customCompetencies = json_decode((string)($row['custom_competencies_json'] ?? '[]'), true);
        if (!is_array($customCompetencies)) $customCompetencies = [];
        $groupedCandidates[$office][] = [
            'id' => $id,
            'name' => $row['candidate_name'],
            'position' => $position,
            'office' => $office,
            'date' => !empty($row['interview_date']) ? $row['interview_date'] : date('F d, Y'),
            'scores' => isset($scoreMap[$id]) ? $scoreMap[$id] : [],
            'customCompetencies' => $customCompetencies,
            '_legacy' => ['p' => $legacyP, 't' => $legacyT],
        ];
    }
}

$defaultOffices = ['REGIONAL OFFICE', 'PENRO ILOILO', 'PENRO CAPIZ', 'PENRO AKLAN', 'PENRO ANTIQUE', 'PENRO GUIMARAS', 'PENRO NEGROS OCCIDENTAL'];
$orderedOffices = [];
foreach ($defaultOffices as $defOffice) $orderedOffices[$defOffice] = $groupedCandidates[$defOffice] ?? [];
foreach ($groupedCandidates as $office => $list) {
    if (!isset($orderedOffices[$office])) $orderedOffices[$office] = $list;
}
$groupedCandidates = $orderedOffices;
foreach (array_keys($storedOrders) as $storedOffice) {
    if (!isset($groupedCandidates[$storedOffice])) $groupedCandidates[$storedOffice] = [];
}
try {
    foreach (removed_tab_list($conn) as $removedOffice) {
        if (isset($groupedCandidates[$removedOffice]) && count($groupedCandidates[$removedOffice]) === 0) unset($groupedCandidates[$removedOffice]);
    }
} catch (Exception $e) {
    error_log('results_2 removed tabs load error: ' . $e->getMessage());
}

$headerLabels = [
    'hash' => 'NO.', 'name' => 'NAME OF APPLICANT / CANDIDATE', 'psychoGroup' => 'PSYCHO-SOCIAL ATTRIBUTES', 'potentialGroup' => 'POTENTIAL ATTRIBUTES',
    'core' => 'CORE', 'org' => 'ORG', 'psychoExtra1' => 'R1', 'psychoExtra2' => 'R2', 'psychoExtra3' => 'R3', 'psychoExtra4' => 'R4',
    'psychoAve' => 'AVE.', 'func' => 'FUNC', 'lead' => 'LEAD',
    'potentialExtra1' => 'R1', 'potentialExtra2' => 'R2', 'potentialExtra3' => 'R3', 'potentialExtra4' => 'R4', 'potentialAve' => 'AVE.'
];
try {
    $settingsResult = $conn->query("SELECT * FROM sheet_settings WHERE id = 1 LIMIT 1");
    $settingsRow = $settingsResult ? $settingsResult->fetch_assoc() : null;
    if ($settingsRow) {
        $headerLabels = [
            'hash' => $settingsRow['hdr_hash'] ?? 'NO.',
            'name' => $settingsRow['hdr_name'] ?? 'NAME OF APPLICANT / CANDIDATE',
            'psychoGroup' => $settingsRow['hdr_psycho_group'] ?? 'PSYCHO-SOCIAL ATTRIBUTES',
            'potentialGroup' => $settingsRow['hdr_potential_group'] ?? 'POTENTIAL ATTRIBUTES',
            'core' => $settingsRow['hdr_core'] ?? 'CORE',
            'org' => $settingsRow['hdr_org'] ?? 'ORG',
            'psychoExtra1' => $settingsRow['hdr_psycho_extra1'] ?? 'R1',
            'psychoExtra2' => $settingsRow['hdr_psycho_extra2'] ?? 'R2',
            'psychoExtra3' => $settingsRow['hdr_psycho_extra3'] ?? 'R3',
            'psychoExtra4' => $settingsRow['hdr_psycho_extra4'] ?? 'R4',
            'psychoAve' => $settingsRow['hdr_psycho_ave'] ?? 'AVE.',
            'func' => $settingsRow['hdr_func'] ?? 'FUNC',
            'lead' => $settingsRow['hdr_lead'] ?? 'LEAD',
            'potentialExtra1' => $settingsRow['hdr_potential_extra1'] ?? 'R1',
            'potentialExtra2' => $settingsRow['hdr_potential_extra2'] ?? 'R2',
            'potentialExtra3' => $settingsRow['hdr_potential_extra3'] ?? 'R3',
            'potentialExtra4' => $settingsRow['hdr_potential_extra4'] ?? 'R4',
            'potentialAve' => $settingsRow['hdr_potential_ave'] ?? 'AVE.',
        ];
    }
} catch (Exception $e) {}

function normalize_rater(string $label): ?string {
    $trimmed = trim($label);
    if ($trimmed === '') return null;
    foreach (rater_list() as $rater) {
        if (preg_replace('/[\s\-_]+/', '', strtoupper($rater)) === preg_replace('/[\s\-_]+/', '', strtoupper($trimmed))) return $rater;
    }
    return preg_replace('/\s+/', ' ', strtoupper($trimmed));
}

$legacyPLabels = [$headerLabels['core'], $headerLabels['org'], $headerLabels['psychoExtra1'], $headerLabels['psychoExtra2'], $headerLabels['psychoExtra3'], $headerLabels['psychoExtra4']];
$legacyTLabels = [$headerLabels['func'], $headerLabels['lead'], $headerLabels['potentialExtra1'], $headerLabels['potentialExtra2'], $headerLabels['potentialExtra3'], $headerLabels['potentialExtra4']];
foreach ($groupedCandidates as $officeKey => $list) {
    foreach ($list as $i => $candidate) {
        if (empty($candidate['scores'])) {
            $scores = [];
            for ($index = 0; $index < 6; $index++) {
                $psychoRater = normalize_rater((string)$legacyPLabels[$index]);
                $potentialRater = normalize_rater((string)$legacyTLabels[$index]);
                if ($psychoRater !== null && $candidate['_legacy']['p'][$index] !== null) $scores[$psychoRater]['p'] = $candidate['_legacy']['p'][$index];
                if ($potentialRater !== null && $candidate['_legacy']['t'][$index] !== null) $scores[$potentialRater]['t'] = $candidate['_legacy']['t'][$index];
            }
            foreach ($scores as $rater => $score) {
                $scores[$rater] = ['p' => $score['p'] ?? null, 't' => $score['t'] ?? null];
            }
            $groupedCandidates[$officeKey][$i]['scores'] = $scores;
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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="mockup.css?v=<?php echo filemtime(__DIR__ . '/mockup.css'); ?>">
    <link rel="stylesheet" href="ui-enhancements.css?v=<?php echo filemtime(__DIR__ . '/ui-enhancements.css'); ?>">
    <script src="ui-enhancements.js?v=<?php echo filemtime(__DIR__ . '/ui-enhancements.js'); ?>" defer></script>
    <style>
        #tables-wrapper > .table-container { display: none; }
        #tables-wrapper > .table-container.active { display: block; }
        #tabs .ind { z-index: 5; transition: left .42s cubic-bezier(.22, .61, .36, 1), width .42s cubic-bezier(.22, .61, .36, 1); }
        ::view-transition-old(active-sheet) { animation: ui-sheet-out-left .42s cubic-bezier(.22, .61, .36, 1) both; }
        ::view-transition-new(active-sheet) { animation: ui-sheet-in-right .42s cubic-bezier(.22, .61, .36, 1) both; }
        html[data-ui-transition="sheet-reverse"]::view-transition-old(active-sheet) { animation: ui-sheet-out-right .42s cubic-bezier(.22, .61, .36, 1) both; }
        html[data-ui-transition="sheet-reverse"]::view-transition-new(active-sheet) { animation: ui-sheet-in-left .42s cubic-bezier(.22, .61, .36, 1) both; }
        .table-container.sheet.sl { animation: ui-sheet-in-right .42s cubic-bezier(.22, .61, .36, 1) both; }
        .table-container.sheet.sr { animation: ui-sheet-in-left .42s cubic-bezier(.22, .61, .36, 1) both; }
        .table-container.sheet.sl .blk, .table-container.sheet.sr .blk { animation: none; }
        .tab-close-btn { display: inline-flex; align-items: center; justify-content: center; width: 18px; height: 18px; margin-left: 6px; border-radius: 50%; background: rgba(179, 38, 30, .12); color: #b3261e; font-size: 13px; cursor: pointer; }
        .tab-close-btn:hover { background: #b3261e; color: #fff; }
        .pos-delete-btn { float: right; width: 20px; height: 20px; padding: 0; border: 1px solid var(--ln); border-radius: 50%; background: #fff; cursor: pointer; }
        .row-number { display: inline; }
        .delete-icon { display: none; color: #b3261e; font-weight: 700; }
        .row-num-cell:hover .row-number { display: none; }
        .row-num-cell:hover .delete-icon { display: inline; }
        @media (max-width: 600px) {
            #results { width: 100%; min-width: 0; padding: 8px 6px; }
            .tabsbar { align-items: center; gap: 4px; padding: 6px 6px 0; border-radius: 8px 8px 0 0; }
            #tabs { min-width: 0; }
            .tab { padding: 9px 10px; font-size: 12px; white-space: nowrap; }
            .tab-close-btn { width: 16px; height: 16px; margin-left: 4px; }
            .tabsbar .add { margin: 0 0 4px; }
            .srch { min-width: 0; padding: 6px 0; }
            .srch input { min-width: 0; }
            #tables-wrapper, .table-container.sheet { width: 100%; min-width: 0; max-width: 100%; }
            .table-container.sheet { overflow: hidden; padding: 14px 8px; border-radius: 0 0 8px 8px; }
            .dh { margin: 0 2px 14px; overflow-wrap: anywhere; }
            .dh .a, .dh .c { font-size: 10px; }
            .dh .b { font-size: 12px; }
            .dh .d { font-size: 13px; }
            .blk, .table-scroll.sc { width: 100%; min-width: 0; max-width: 100%; }
            .table-scroll.sc { overflow-x: auto; overflow-y: hidden; -webkit-overflow-scrolling: touch; scrollbar-width: thin; touch-action: pan-x pan-y; }
            .excel-table th, .excel-table td { padding: 6px 4px; }
            .excel-table .nm { min-width: 170px; }
            .sg { gap: 22px; }
            .ab { gap: 8px; padding: 9px; }
            .ag { gap: 6px; }
            .ag .btn { min-width: 0; padding: 8px 9px; font-size: 12px; }
        }
        @media print {
            @page { size: landscape; margin: 0.4in; }
            body { background: #fff; padding: 0; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
            #results, #results * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
            .no-print, .excel-tabs-container, .action-bar, .rater-add, .pos-delete-btn, .tab-close-btn, .rater-remove { display: none !important; }
            .table-container { display: block !important; border: none; box-shadow: none; padding: 0; page-break-after: always; }
            .table-block { page-break-inside: avoid; }
            .table-scroll { overflow: visible !important; }
            .excel-table { min-width: 0 !important; }
            .excel-table th, .excel-table td { background-color: #fff !important; }
            .excel-table thead th:not(.g):not(.y) { background-color: #f4f7f5 !important; }
            .excel-table .dt { background-color: #f4f7f5 !important; }
            .excel-table .pc { background-color: #fbd3e5 !important; }
            .excel-table .g { background-color: #e3f1d9 !important; }
            .excel-table thead th.g { background-color: #cfe7bf !important; }
            .excel-table .y { background-color: #fff2c9 !important; }
            .excel-table thead th.y { background-color: #ffe79a !important; }
            .row-num-cell:hover .row-number { display: inline !important; }
            .row-num-cell:hover .delete-icon { display: none !important; }
        }
    </style>
</head>
<body>

<section id="results">
<form id="resultsForm" autocomplete="off" onsubmit="return false;">
    <div class="excel-tabs-container tabsbar">
        <div id="tabs"></div>
        <div class="tab-search srch">
            <input type="text" id="candidateSearch" placeholder="Search applicant name..." autocomplete="off">
            <button type="button" class="btn" onclick="searchCandidate()">🔍 Find</button>
        </div>
    </div>

    <div id="tables-wrapper"></div>

    <div class="action-bar ab no-print">
        <div class="action-group ag">
            <button type="button" class="btn p btn-primary" onclick="saveWholeTable(event)">💾 Save All Changes</button>
            <button type="button" class="btn" id="importExcelButton">Import Excel</button>
            <input type="file" id="excelImportFile" accept=".xlsx,.xls,.csv" hidden>
            <button type="button" class="btn" id="exportExcelButton">Export Excel</button>
            <button type="button" class="btn" onclick="window.print()">🖨️ Print / Save as PDF</button>
        </div>
        <div class="action-group ag">
            <a href="template_manager.php" class="btn">Competency Templates</a>
        </div>
        <div class="action-group ag r action-group-admin">
            <a href="manage_users.php" class="btn">👥 Manage Users</a>
        </div>
        <div class="action-group ag action-group-logout">
            <a href="logout.php" class="btn d">🚪 Logout</a>
        </div>
    </div>
</form>
</section>

<script src="https://cdn.jsdelivr.net/npm/xlsx-js-style@1.2.0/dist/xlsx.bundle.js"></script>
<script src="results_excel_export.js?v=<?php echo (int)filemtime(__DIR__ . '/results_excel_export.js'); ?>"></script>
<script>
    <?php
    $excelImportModule = __DIR__ . '/results_excel_import.js';
    if (is_readable($excelImportModule)) {
        readfile($excelImportModule);
    } else {
        echo 'window.ResultsExcelImportLoadError = "The results_excel_import.js file is missing or unreadable on the server.";';
    }
    ?>
</script>
<script>
    const groupedData = <?php echo json_encode($groupedCandidates, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    let headerLabels = <?php echo json_encode($headerLabels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    let activeTabOffice = 'REGIONAL OFFICE';
    let sheetTransition = '';
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
        return String(str ?? '').replace(/[&<>"']/g, ch => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));
    }

    function calculateAverage(...scores) {
        const validScores = scores.filter(score => score !== null && !isNaN(score) && score !== '' && score !== undefined);
        if (!validScores.length) return '#DIV/0!';
        return (validScores.reduce((total, score) => total + parseFloat(score), 0) / validScores.length).toFixed(2);
    }

    function formatVal(value) {
        if (value === null || value === undefined || isNaN(value) || value === '') return '';
        return Number(value).toFixed(2);
    }

    function updateHeaderLabel(element, key) { headerLabels[key] = element.innerText.replace(/[\r\n\t]/g, '').trim(); renderAll(); }
    function blockKey(office, position, date) { return office + '|' + position + '|' + date; }

    function raterColumns(candidates, key, office, position) {
        const stored = ((storedOrders[office] || {})[position]) || [];
        const ordered = [];
        stored.concat(addedRaters[key] || []).forEach(rater => { if (!ordered.includes(rater)) ordered.push(rater); });
        const scored = new Set();
        candidates.forEach(candidate => Object.keys(candidate.scores || {}).forEach(rater => scored.add(rater)));
        const rest = Array.from(scored).filter(rater => !ordered.includes(rater));
        return ordered.concat(RATERS.filter(rater => rest.includes(rater)), rest.filter(rater => !RATERS.includes(rater)));
    }

    function buildRowHtml(candidate = null, rowNumber = 1, cols = [], slots = MIN_SLOTS) {
        const id = candidate ? candidate.id : '';
        const name = candidate ? escapeHtml(candidate.name) : '';
        let psychoCells = '';
        let potentialCells = '';
        const psychoValues = [];
        const potentialValues = [];
        for (let index = 0; index < slots; index++) {
            const rater = cols[index];
            if (rater) {
                const score = (candidate && candidate.scores && candidate.scores[rater]) || {};
                psychoValues.push(score.p);
                potentialValues.push(score.t);
                psychoCells += `<td contenteditable="true" data-kind="p" data-rater="${escapeHtml(rater)}">${formatVal(score.p)}</td>`;
                potentialCells += `<td contenteditable="true" data-kind="t" data-rater="${escapeHtml(rater)}">${formatVal(score.t)}</td>`;
            } else {
                psychoCells += '<td></td>';
                potentialCells += '<td></td>';
            }
        }
        const psychoAverage = candidate ? calculateAverage(...psychoValues) : '#DIV/0!';
        const potentialAverage = candidate ? calculateAverage(...potentialValues) : '#DIV/0!';
        return `
            <tr class="in" style="--i:${rowNumber - 1}" data-id="${id}">
                <td class="row-num-cell n" onclick="handleRowDelete(this)"><span class="row-number">${rowNumber}</span><span class="delete-icon" title="Delete Row">×</span></td>
                <td class="text-left nm" contenteditable="true" data-field="name">${name}</td>
                ${psychoCells}
                <td class="col-green-ave g psycho-ave">${psychoAverage}</td>
                ${potentialCells}
                <td class="col-yellow-ave y potential-ave">${potentialAverage}</td>
            </tr>
        `;
    }

    function buildTableHeaderHtml(currentDate, branch, position, cols, slots) {
        const raterHeaders = () => {
            let html = '';
            for (let index = 0; index < slots; index++) {
                if (cols[index]) html += `<th style="width: 62px;">${escapeHtml(cols[index])}<span class="rater-remove rx" data-rater="${escapeHtml(cols[index])}" title="Remove this column" onclick="removeRater(this)">×</span></th>`;
                else html += '<th style="width: 62px;"></th>';
            }
            return html;
        };
        const totalCols = 2 + (slots + 1) * 2;
        const escapedOffice = escapeHtml(branch).replace(/'/g, "\\'").replace(/"/g, '&quot;');
        const escapedPosition = escapeHtml(position).replace(/'/g, "\\'").replace(/"/g, '&quot;');
        return `
            <tr>
                <td colspan="3" class="text-left meta-row dt" contenteditable="true" data-field="interview_date" style="border-right: none;"><b>DATE OF INTERVIEW:</b> ${escapeHtml(currentDate)}</td>
                <td colspan="${totalCols - 3}" class="position-cell pc" style="border-left: none;">POSITION TITLE: ${escapeHtml(position)}<button type="button" class="pos-delete-btn no-print" title="Delete this entire position table" onclick="deletePositionGroup(this, &quot;${escapedOffice}&quot;, &quot;${escapedPosition}&quot;)">×</button></td>
            </tr>
            <tr>
                <th class="n" style="width: 30px;">${escapeHtml(headerLabels.hash)}</th>
                <th class="nm" style="width: 230px;" contenteditable="true" onblur="updateHeaderLabel(this,'name')">${escapeHtml(headerLabels.name)}</th>
                ${raterHeaders()}
                <th class="col-green-ave g" style="width: 55px; border-left: 2px solid #000 !important;" contenteditable="true" onblur="updateHeaderLabel(this,'psychoAve')">${escapeHtml(headerLabels.psychoAve)}</th>
                ${raterHeaders()}
                <th class="col-yellow-ave y" style="width: 55px; border-left: 2px solid #000 !important;" contenteditable="true" onblur="updateHeaderLabel(this,'potentialAve')">${escapeHtml(headerLabels.potentialAve)}</th>
            </tr>
        `;
    }
    function buildTableBlockHtml(candidates, currentDate, branch, posKey) {
        const cols = raterColumns(candidates, blockKey(branch, posKey, currentDate), branch, posKey);
        const slots = Math.max(MIN_SLOTS, cols.length + 1);
        const free = RATERS.filter(r => !cols.includes(r));
        let rowsHtml = '';
        const minRows = Math.max(5, candidates.length);
        for (let i = 0; i < minRows; i++) rowsHtml += buildRowHtml(candidates[i] || null, i + 1, cols, slots);
        const positionAttr = posKey ? ` data-position="${escapeHtml(posKey)}"` : '';

        return `
            <div class="table-block blk"${positionAttr}>
                <div class="table-scroll sc">
                    <table class="excel-table">
                        <thead>
                            ${buildTableHeaderHtml(currentDate, branch, posKey, cols, slots, free)}
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
            <div class="signatory-section sg">
            <div class="sig-box"><i class="sig-line"></i><b class="sig-title">HRMPSB SECRETARIAT</b><small class="sig-sub">Prepared By</small></div>
            <div class="sig-box"><i class="sig-line"></i><b class="sig-title">HRMPSB COMMITTEE MEMBER</b><small class="sig-sub">Attested By</small></div>
            <div class="sig-box"><i class="sig-line"></i><b class="sig-title">CHAIRPERSON, HRMPSB</b><small class="sig-sub">Approved By</small></div>
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
                    out.push({ row, office, position, date, id, name, scores });
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
            (fresh[r.office] = fresh[r.office] || []).push({ id: r.id, name: r.name, position: r.position, office: r.office, date: r.date, scores: r.scores });
        });
        containers.forEach(c => {
            const o = c.getAttribute('data-office');
            if (o in groupedData) groupedData[o] = fresh[o] || [];
        });
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
        const matching = list.filter(c => (c.position && c.position.trim() !== '') ? c.position.trim() === position : position === 'UNASSIGNED');
        const savedCount = matching.filter(c => c.id > 0).length;
        const msg = savedCount > 0 ? `Delete the entire "${position}" table for ${office}?\n\nThis will remove ${savedCount} saved applicant(s) and all of their scores, plus every rater column for this position.` : `Delete the entire "${position}" table for ${office}?`;
        if (!confirm(msg)) return;
        groupedData[office] = list.filter(c => (c.position && c.position.trim() !== '') ? c.position.trim() !== position : position !== 'UNASSIGNED');
        if (storedOrders[office] && storedOrders[office][position]) delete storedOrders[office][position];
        Object.keys(addedRaters).forEach(k => { if (k.indexOf(office + '|' + position + '|') === 0) delete addedRaters[k]; });
        window.__positionsToDelete.push({ office: office, position: position });
        skipNextSync = true;
        renderAll();
        if (savedCount > 0) alert('Removed from view.\n\nClick "Save All Changes" to permanently delete from the database.');
    }

    function renderAll() {
        const transitionClass = sheetTransition;
        const update = () => renderAllNow(
            typeof document.startViewTransition === 'function' ? '' : transitionClass
        );
        if (typeof window.runUITransition === 'function') {
            const transition = transitionClass === 'sr'
                ? 'sheet-reverse'
                : transitionClass === 'sl' ? 'sheet-forward' : 'content';
            return window.runUITransition(update, transition);
        }
        return update();
    }

    function renderAllNow(transitionClass = '') {
        if (!skipNextSync) syncFromDom();
        skipNextSync = false;
        const tabsBar = document.getElementById('tabs');
        const wrapper = document.getElementById('tables-wrapper');
        const previousIndicator = tabsBar.querySelector('.ind');
        const indicatorLeft = previousIndicator ? previousIndicator.style.left : '0px';
        const indicatorWidth = previousIndicator ? previousIndicator.style.width : '0px';
        tabsBar.innerHTML = '';
        const indicator = document.createElement('span');
        indicator.className = 'ind';
        indicator.setAttribute('aria-hidden', 'true');
        indicator.style.left = indicatorLeft;
        indicator.style.width = indicatorWidth;
        tabsBar.appendChild(indicator);
        wrapper.innerHTML = '';
        const branchKeys = Object.keys(groupedData);
        if (branchKeys.length > 0 && !branchKeys.includes(activeTabOffice)) activeTabOffice = branchKeys[0];

        branchKeys.forEach(branch => {
            const tab = document.createElement('div');
            tab.className = `tab ${branch === activeTabOffice ? 'active' : ''}`;
            tab.setAttribute('aria-selected', branch === activeTabOffice ? 'true' : 'false');
            tab.onclick = () => switchTab(branch);
            const tabLabel = document.createElement('span');
            tabLabel.innerText = branch;
            tab.appendChild(tabLabel);
            const closeBtn = document.createElement('span');
            closeBtn.className = 'tab-close-btn';
            closeBtn.innerText = '×';
            closeBtn.title = 'Delete this tab';
            closeBtn.onclick = (e) => { e.stopPropagation(); e.preventDefault(); deleteTab(branch); };
            tab.appendChild(closeBtn);
            tabsBar.appendChild(tab);

            const candidates = groupedData[branch] || [];
            const container = document.createElement('div');
            container.className = `table-container sheet ${branch === activeTabOffice ? `active ${transitionClass}` : ''}`;
            container.setAttribute('data-office', branch);

            let docHeaderHtml = `
                <div class="doc-header dh">
                    <div class="gov-title a">Republic of the Philippines</div>
                    <div class="agency-title b">DEPARTMENT OF ENVIRONMENT AND NATURAL RESOURCES</div>
                    <div class="office-sub c">${escapeHtml(branch)}</div>
                    <div class="doc-name d">MASTER INTERVIEW SUMMARY RATING SHEET</div>
                </div>
            `;

            const positionGroups = {};
            candidates.forEach(cand => {
                const posKey = (cand.position && cand.position.trim() !== '') ? cand.position.trim() : 'UNASSIGNED';
                if (!positionGroups[posKey]) positionGroups[posKey] = [];
                positionGroups[posKey].push(cand);
            });
            if (Object.keys(positionGroups).length === 0) positionGroups['UNASSIGNED'] = [];

            let stackHtml = docHeaderHtml;
            Object.keys(positionGroups).forEach(posKey => {
                const candsForPos = positionGroups[posKey];
                const dateGroups = {};
                candsForPos.forEach(cand => {
                    const dateKey = (cand.date && cand.date.trim() !== '') ? cand.date.trim() : "<?php echo date('F d, Y'); ?>";
                    if (!dateGroups[dateKey]) dateGroups[dateKey] = [];
                    dateGroups[dateKey].push(cand);
                });
                if (Object.keys(dateGroups).length === 0) dateGroups["<?php echo date('F d, Y'); ?>"] = [];
                Object.keys(dateGroups).forEach(dateKey => {
                    stackHtml += buildTableBlockHtml(dateGroups[dateKey], dateKey, branch, posKey);
                });
            });

            stackHtml += buildSignatoryBlock();
            container.innerHTML = stackHtml;
            wrapper.appendChild(container);
            container.querySelectorAll('.scores-body').forEach(tbody => checkAndAutoAddRow(tbody));
        });

        const addBtn = document.createElement('button');
        addBtn.className = 'add';
        addBtn.innerText = '+ Add Tab';
        addBtn.onclick = promptNewTab;
        tabsBar.appendChild(addBtn);
        const selectedTab = tabsBar.querySelector('.tab[aria-selected="true"]');
        if (selectedTab) {
            indicator.style.left = `${selectedTab.offsetLeft}px`;
            indicator.style.width = `${selectedTab.offsetWidth}px`;
        }
        sheetTransition = '';
    }

    function switchTab(branch) {
        const branches = Object.keys(groupedData);
        const currentIndex = branches.indexOf(activeTabOffice);
        const nextIndex = branches.indexOf(branch);
        sheetTransition = nextIndex > currentIndex ? 'sl' : nextIndex < currentIndex ? 'sr' : '';
        activeTabOffice = branch;
        renderAll();
    }
    function searchCandidate() {
        const query = document.getElementById('candidateSearch').value.trim().toLowerCase();
        if (query === '') return;
        for (const branch of Object.keys(groupedData)) {
            const match = (groupedData[branch] || []).find(c => c.name && c.name.toLowerCase().includes(query));
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
        const msg = saved > 0 ? `Delete the "${branch}" tab?\n\nThis permanently deletes ${saved} saved applicant(s) and all of their scores from the database.` : `Delete the "${branch}" tab?`;
        if (!confirm(msg)) return;
        fetch('delete_tab.php', { method: 'POST', credentials: 'same-origin', body: new URLSearchParams({ office: branch, csrf_token: CSRF_TOKEN }) })
        .then(res => res.text().then(text => { try { return JSON.parse(text); } catch (e) { throw new Error('Unexpected server reply: ' + text.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 300)); } }))
        .then(data => {
            if (data.status !== 'success') return alert('Delete failed - the tab was NOT deleted.\n' + (data.message || 'Unknown error'));
            delete groupedData[branch];
            delete storedOrders[branch];
            Object.keys(addedRaters).forEach(k => { if (k.indexOf(branch + '|') === 0) delete addedRaters[k]; });
            const remaining = Object.keys(groupedData);
            activeTabOffice = remaining.length > 0 ? remaining[0] : '';
            renderAll();
        }).catch(err => alert('Delete failed - the tab was NOT deleted.\n' + err.message));
    }

    function promptNewTab() {
        const name = prompt("Enter new PENRO or Branch Name:");
        if (name && name.trim() !== "") {
            const cleanName = name.trim().toUpperCase();
            if (!groupedData[cleanName]) { groupedData[cleanName] = []; activeTabOffice = cleanName; renderAll(); }
            else switchTab(cleanName);
        }
    }

    function renameBranchTab(el) {
        const container = el.closest('.table-container');
        const oldBranch = container.getAttribute('data-office');
        const newBranch = el.innerText.replace('OFFICE/BRANCH:', '').replace(/[\r\n\t]/g, '').trim().toUpperCase();
        if (!newBranch || newBranch === oldBranch) { el.innerText = 'OFFICE/BRANCH: ' + oldBranch; return; }
        if (groupedData[newBranch]) { alert('A tab named "' + newBranch + '" already exists.'); el.innerText = 'OFFICE/BRANCH: ' + oldBranch; return; }
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
                .then(res => res.text().then(text => { try { return JSON.parse(text); } catch (e) { throw new Error('Unexpected server reply: ' + text.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 300)); } }))
                .then(data => {
                    if (data.status === 'success') { row.remove(); reindexTableRows(tbody); }
                    else alert('Delete failed - the row was NOT deleted.\n' + (data.message || 'Unknown error'));
                }).catch(err => alert('Delete failed - the row was NOT deleted.\n' + err.message));
            } else { row.remove(); reindexTableRows(tbody); }
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
            return { tempIndex: i, id: r.id, name: r.name, office: r.office, position: r.position, interviewDate: r.date, scores: r.scores };
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

        const tabsToSave = Array.from(document.querySelectorAll('.table-container')).map(c => c.getAttribute('data-office')).filter(Boolean);

        fetch('save_score.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: CSRF_TOKEN, candidates: candidatesToSave, columns: columnsToSave, tabs: tabsToSave, hiddenTabs: Array.from(hiddenTabs), renames: pendingRenames, headers: headerLabels, positionsToDelete: window.__positionsToDelete })
        })
        .then(res => res.text().then(text => { try { return JSON.parse(text); } catch (e) { throw new Error('Server returned non-JSON. First 300 chars: ' + text.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 300)); } }))
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
                if (typeof data.savedColumns === 'number') msg += '\n' + data.savedColumns + ' rater column(s) stored.';
                if (typeof data.deletedPositions === 'number' && data.deletedPositions > 0) msg += '\n' + data.deletedPositions + ' position table(s) permanently deleted.';
                if (typeof data.renamedOffices === 'number' && data.renamedOffices > 0) msg += '\n' + data.renamedOffices + ' office(s) renamed.';
                alert(msg);
            } else { alert('Error saving: ' + (data.message || 'Unknown error')); }
        }).catch(err => alert('Save request failed: ' + err.message));
    }

    function exportExcel() {
        window.ResultsExcelExport.exportExcel({
            XLSX: window.XLSX,
            groupedData,
            headerLabels,
            raterColumns,
            blockKey,
            minSlots: MIN_SLOTS,
            todayDate: "<?php echo date('F d, Y'); ?>",
            syncFromDom
        });
    }

    async function importExcel(file) {
        syncFromDom();
        if (window.ResultsExcelImportLoadError) throw new Error(window.ResultsExcelImportLoadError);
        if (!window.ResultsExcelImport || typeof window.ResultsExcelImport.readWorkbook !== 'function') {
            throw new Error('The import module did not initialize. Refresh the page and check the results_excel_import.js file.');
        }
        const { candidates, invalidScores } = await window.ResultsExcelImport.readWorkbook(
            file,
            groupedData,
            "<?php echo date('F d, Y'); ?>",
            activeTabOffice
        );
        candidates.forEach(candidate => {
            if (candidate.id > 0) {
                Object.keys(groupedData).forEach(branch => {
                    groupedData[branch] = groupedData[branch].filter(existing => Number(existing.id) !== candidate.id);
                });
            }
            if (!groupedData[candidate.office]) groupedData[candidate.office] = [];
            groupedData[candidate.office].push(candidate);
        });

        skipNextSync = true;
        activeTabOffice = Object.keys(groupedData).includes(activeTabOffice) ? activeTabOffice : Object.keys(groupedData)[0];
        renderAll();
        const scoreNotice = invalidScores.length ? ' Invalid score values were skipped on row(s): ' + [...new Set(invalidScores)].join(', ') + '.' : '';
        alert(candidates.length + ' candidate row(s) imported. Review the results, then select Save All Changes to store them.' + scoreNotice);
    }

    document.addEventListener('DOMContentLoaded', function() {
        renderAll();
        document.getElementById('exportExcelButton').addEventListener('click', exportExcel);
        const importFile = document.getElementById('excelImportFile');
        document.getElementById('importExcelButton').addEventListener('click', () => importFile.click());
        importFile.addEventListener('change', async () => {
            const file = importFile.files[0];
            if (!file) return;
            try {
                await importExcel(file);
            } catch (error) {
                alert('Import failed: ' + error.message);
            } finally {
                importFile.value = '';
            }
        });
        document.getElementById('candidateSearch')?.addEventListener('keydown', function(e) { if (e.key === 'Enter') { e.preventDefault(); searchCandidate(); } });
        document.addEventListener('input', function(e) {
            if (e.target.isContentEditable) {
                const row = e.target.closest('tr');
                if (row && !(e.target.classList.contains('psycho-ave') || e.target.classList.contains('potential-ave'))) recalculateRow(row);
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
                    if (nextRow && nextRow.children[cellIndex]) nextRow.children[cellIndex].focus();
                }
            }
        });
    });
</script>
</body>
</html>