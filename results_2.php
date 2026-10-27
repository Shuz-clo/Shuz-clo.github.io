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

$templateCustomCompetencies = [];
$templateResult = $conn->query('SELECT layout_json FROM competency_templates');
while ($templateRow = $templateResult->fetch_assoc()) {
    $templateLayout = json_decode((string)$templateRow['layout_json'], true);
    if (!is_array($templateLayout['sections'] ?? null)) continue;
    foreach ($templateLayout['sections'] as $sectionId => $section) {
        if (empty($section['custom']) || !is_array($section['rows'] ?? null)) continue;
        foreach ($section['rows'] as $row) {
            if (!is_array($row) || empty($row['id'])) continue;
            $key = $sectionId . '|' . $row['id'];
            $templateCustomCompetencies[$key] = [
                'sectionId' => (string)$sectionId,
                'competencyId' => (string)$row['id'],
                'label' => (string)($section['label'] ?? 'Untitled section') . ' — ' . (string)($row['title'] ?? 'Untitled competency')
            ];
        }
    }
}

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
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', Calibri, Arial, sans-serif; margin: 0; padding: 15px; background-color: #f3f3f3; color: #000; font-size: 12px; }
        .excel-tabs-container { display: flex; align-items: flex-end; gap: 5px; background-color: #e1e1e1; border: 1px solid #b0b0b0; border-bottom: none; padding: 6px 8px 0 8px; border-radius: 4px 4px 0 0; }
        #tabs-bar { display: flex; align-items: flex-end; gap: 3px; flex: 1; overflow-x: auto; }
        .excel-tab { display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; background-color: #d6d6d6; border: 1px solid #a6a6a6; border-bottom: none; border-radius: 4px 4px 0 0; cursor: pointer; font-size: 11px; font-weight: 600; color: #444; user-select: none; white-space: nowrap; }
        .excel-tab.active { background-color: #ffffff; color: #107c41; border-top: 3px solid #107c41; border-bottom: 1px solid #ffffff; font-weight: bold; }
        .tab-close-btn { display: inline-flex; align-items: center; justify-content: center; width: 18px; height: 18px; border-radius: 50%; background: rgba(220, 53, 69, 0.15); color: #c0392b; font-weight: bold; font-size: 13px; margin-left: 6px; cursor: pointer; flex-shrink: 0; }
        .tab-close-btn:hover { background: #c0392b; color: #fff; }
        .excel-tab-add { padding: 4px 10px; background-color: #ffffff; border: 1px solid #a6a6a6; border-radius: 3px; cursor: pointer; font-weight: bold; color: #107c41; margin-bottom: 2px; }
        .tab-search { display: flex; align-items: center; gap: 4px; padding-bottom: 2px; }
        .tab-search input { padding: 4px 8px; border: 1px solid #8a8a8a; border-radius: 2px; font-size: 11px; width: 180px; }
        .table-container { display: none; background: #ffffff; padding: 25px 30px; border: 1px solid #b0b0b0; box-shadow: 0 4px 10px rgba(0,0,0,0.08); margin-bottom: 20px; }
        .table-container.active { display: block; }
        .table-scroll { overflow-x: auto; }
        .doc-header { text-align: center; margin-bottom: 15px; text-transform: uppercase; line-height: 1.3; }
        .doc-header .gov-title { font-size: 11px; font-weight: 600; color: #333; }
        .doc-header .agency-title { font-size: 13px; font-weight: bold; color: #000; }
        .doc-header .office-sub { font-size: 11px; font-weight: bold; color: #222; }
        .doc-header .doc-name { font-size: 14px; font-weight: 800; color: #107c41; margin-top: 5px; text-decoration: underline; }
        .table-block { margin-bottom: 30px; }
        
        .pos-delete-btn { background: rgba(255, 255, 255, 0.4); color: #000; border: 1px solid rgba(0, 0, 0, 0.3); border-radius: 50%; width: 20px; height: 20px; font-size: 13px; font-weight: bold; line-height: 1; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; padding: 0; float: right;}
        .pos-delete-btn:hover { background: #c0392b; color: #fff; border-color: #c0392b; }

        .excel-table { border-collapse: collapse; width: 100%; min-width: 900px; font-size: 11px; border: 2px solid #000000; }
        .excel-table th, .excel-table td { border: 1px solid #000000; padding: 5px 4px; text-align: center; vertical-align: middle; }
        .meta-row { background-color: #f2f2f2; font-weight: bold; }
        
        .position-cell {
            background-color: #FF99CC !important;
            color: #000000;
            font-weight: bold;
            text-align: left;
            padding: 6px 12px !important;
        }

        .col-green-ave { background-color: #c6e0b4; font-weight: bold; }
        .col-yellow-ave { background-color: #ffe699; font-weight: bold; }
        .rater-remove { cursor: pointer; color: #c0392b; font-weight: bold; margin-left: 3px; opacity: .55; }
        .rater-remove:hover { opacity: 1; }
        .rater-add { display: none; }
        .text-left { text-align: left !important; padding-left: 8px !important; }
        th[contenteditable="true"], td[contenteditable="true"] { outline: none; cursor: cell; }
        th[contenteditable="true"]:focus, td[contenteditable="true"]:focus { outline: 2px solid #107c41 !important; background-color: #e8f5e9 !important; }
        .row-num-cell { background-color: #f2f2f2; font-weight: bold; width: 30px; cursor: pointer; }
        .row-num-cell:hover .row-number { display: none; }
        .delete-icon { display: none; color: #d9534f; font-weight: bold; font-size: 14px; }
        .row-num-cell:hover .delete-icon { display: inline; }
        .signatory-section { margin-top: 35px; display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; page-break-inside: avoid; }
        .sig-box { text-align: center; }
        .sig-line { margin-top: 40px; border-bottom: 1px solid #000000; width: 80%; margin-left: auto; margin-right: auto; }
        .sig-title { font-weight: bold; margin-top: 4px; font-size: 11px; }
        .sig-sub { font-size: 10px; color: #555; }
        .action-bar { margin-top: 15px; display: flex; align-items: center; flex-wrap: wrap; gap: 10px 16px; padding: 12px; background: #f3f5f6; border: 1px solid #d7dde1; border-radius: 8px; }
        .action-group { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
        .action-group + .action-group { padding-left: 16px; border-left: 1px solid #cbd2d7; }
        .action-group-admin { margin-left: auto; }
        .action-group-logout { margin-left: 0; }
        .btn { padding: 6px 14px; font-size: 12px; font-weight: 600; cursor: pointer; background-color: #ffffff; border: 1px solid #7f7f7f; border-radius: 3px; text-decoration: none; color: #000; }
        .btn:hover { background-color: #e6e6e6; }
        .btn-primary { background-color: #107c41; color: #fff; border-color: #107c41; }
        .btn-primary:hover { background-color: #0b5c30; }
        @media (max-width: 768px) {
            body { padding: 6px; font-size: 11px; }
            .excel-tabs-container { flex-wrap: wrap; padding: 6px 6px 0 6px; }
            #tabs-bar { width: 100%; overflow-x: auto; }
            .excel-tab { padding: 5px 10px; font-size: 10px; }
            .tab-search { width: 100%; padding-bottom: 6px; }
            .tab-search input { flex: 1; width: auto; font-size: 11px; }
            .table-container { padding: 10px 8px; }
            .excel-table { font-size: 10px; }
            .excel-table th, .excel-table td { padding: 4px 3px; }
            .row-num-cell { width: 26px; }
            .action-bar { align-items: stretch; gap: 8px; padding: 10px; }
            .action-group { width: 100%; gap: 6px; }
            .action-group + .action-group { padding: 8px 0 0; border-left: 0; border-top: 1px solid #cbd2d7; }
            .action-group-admin { margin-left: 0; }
            .action-group .btn { flex: 1 1 auto; text-align: center; }
            .signatory-section { grid-template-columns: 1fr; gap: 30px; }
            .tab-close-btn { width: 22px; height: 22px; font-size: 15px; margin-left: 8px; }
        }
        @media print {
            @page { size: landscape; margin: 0.4in; }
            body { background: #fff; padding: 0; }
            .no-print, .excel-tabs-container, .action-bar, .rater-add, .pos-delete-btn, .tab-close-btn, .rater-remove { display: none !important; }
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
        <div class="action-group">
            <button type="button" class="btn btn-primary" onclick="saveWholeTable(event)">💾 Save All Changes</button>
            <button type="button" class="btn" id="importExcelButton">Import Excel</button>
            <input type="file" id="excelImportFile" accept=".xlsx,.xls,.csv" hidden>
            <button type="button" class="btn" id="exportExcelButton">Export Excel</button>
            <button type="button" class="btn" onclick="deleteCompetency()">Delete Competency</button>
            <button type="button" class="btn" onclick="window.print()">🖨️ Print / Save as PDF</button>
        </div>
        <div class="action-group">
            <a href="template_manager.php" class="btn">Competency Templates</a>
            <a href="template_seed.php" class="btn">Generate Position Sheets</a>
            <a href="positions_import.php" class="btn">Import Positions</a>
        </div>
        <div class="action-group action-group-admin">
            <a href="manage_users.php" class="btn">👥 Manage Users</a>
            <a href="audit_viewer.php" class="btn">Score Audit</a>
            <a href="bulk_users.php" class="btn">Bulk Users</a>
        </div>
        <div class="action-group action-group-logout">
            <a href="logout.php" class="btn" style="background-color: #dc3545; color: white; border-color: #dc3545;">🚪 Logout</a>
        </div>
    </div>
</form>

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
    const RATERS = <?php echo json_encode(rater_list(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const MIN_SLOTS = 7;
    const CSRF_TOKEN = <?php echo json_encode(csrf_token()); ?>;
    const TEMPLATE_CUSTOM_COMPETENCIES = <?php echo json_encode(array_values($templateCustomCompetencies), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
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

    async function deleteCompetency() {
        const competencies = new Map();
        TEMPLATE_CUSTOM_COMPETENCIES.forEach(item => competencies.set(item.sectionId + '|' + item.competencyId, item));
        Object.values(groupedData).flat().forEach(candidate => {
            (candidate.customCompetencies || []).forEach(section => (section.rows || []).forEach(row => {
                if (!section.id || !row.id) return;
                const key = section.id + '|' + row.id;
                if (!competencies.has(key)) competencies.set(key, { sectionId: section.id, competencyId: row.id, label: (section.label || 'Untitled section') + ' — ' + (row.title || 'Untitled competency') });
            }));
        });
        const options = Array.from(competencies.values());
        if (options.length === 0) return alert('There are no custom competencies in the saved candidate records.');
        const menu = options.map((item, index) => `${index + 1}. ${item.label}`).join('\\n');
        const answer = prompt('Enter the number of the competency to permanently delete from all candidate records and matching templates:\\n\\n' + menu);
        if (answer === null) return;
        const selectedIndex = Number(answer.trim()) - 1;
        if (!Number.isInteger(selectedIndex) || selectedIndex < 0 || selectedIndex >= options.length) return alert('Choose a valid competency number.');
        const selected = options[selectedIndex];
        if (!confirm(`Permanently delete "${selected.label}" from all candidate records and matching templates?`)) return;
        try {
            const response = await fetch('delete_competency.php', { method: 'POST', credentials: 'same-origin', body: new URLSearchParams({ section_id: selected.sectionId, competency_id: selected.competencyId, csrf_token: CSRF_TOKEN }) });
            const data = await response.json();
            if (!response.ok || data.status !== 'success') throw new Error(data.message || 'Unknown error');
            Object.values(groupedData).flat().forEach(candidate => {
                candidate.customCompetencies = (candidate.customCompetencies || []).map(section => section.id === selected.sectionId ? { ...section, rows: (section.rows || []).filter(row => row.id !== selected.competencyId) } : section).filter(section => (section.rows || []).length > 0);
            });
            skipNextSync = true;
            renderAll();
            alert(`Competency deleted from ${data.candidateRecordsChanged} candidate record(s) and ${data.templatesChanged} template(s).`);
        } catch (error) { alert('Delete failed - the competency was NOT deleted.\\n' + error.message); }
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
            <tr data-id="${id}">
                <td class="row-num-cell" onclick="handleRowDelete(this)"><span class="row-number">${rowNumber}</span><span class="delete-icon" title="Delete Row">×</span></td>
                <td class="text-left" contenteditable="true" data-field="name">${name}</td>
                ${psychoCells}
                <td class="col-green-ave psycho-ave">${psychoAverage}</td>
                ${potentialCells}
                <td class="col-yellow-ave potential-ave">${potentialAverage}</td>
            </tr>
        `;
    }

    function buildTableHeaderHtml(currentDate, branch, position, cols, slots) {
        const raterHeaders = () => {
            let html = '';
            for (let index = 0; index < slots; index++) {
                if (cols[index]) html += `<th style="width: 62px;">${escapeHtml(cols[index])}<span class="rater-remove" data-rater="${escapeHtml(cols[index])}" title="Remove this column" onclick="removeRater(this)">×</span></th>`;
                else html += '<th style="width: 62px;"></th>';
            }
            return html;
        };
        const totalCols = 2 + (slots + 1) * 2;
        const escapedOffice = escapeHtml(branch).replace(/'/g, "\\'").replace(/"/g, '&quot;');
        const escapedPosition = escapeHtml(position).replace(/'/g, "\\'").replace(/"/g, '&quot;');
        return `
            <tr>
                <td colspan="3" class="text-left meta-row" contenteditable="true" data-field="interview_date" style="border-right: none;"><b>DATE OF INTERVIEW:</b> ${escapeHtml(currentDate)}</td>
                <td colspan="${totalCols - 3}" class="position-cell" style="border-left: none;">POSITION TITLE: ${escapeHtml(position)}<button type="button" class="pos-delete-btn no-print" title="Delete this entire position table" onclick="deletePositionGroup(this, &quot;${escapedOffice}&quot;, &quot;${escapedPosition}&quot;)">×</button></td>
            </tr>
            <tr>
                <th style="width: 30px;">${escapeHtml(headerLabels.hash)}</th>
                <th style="width: 230px;" contenteditable="true" onblur="updateHeaderLabel(this,'name')">${escapeHtml(headerLabels.name)}</th>
                ${raterHeaders()}
                <th class="col-green-ave" style="width: 55px; border-left: 2px solid #000 !important;" contenteditable="true" onblur="updateHeaderLabel(this,'psychoAve')">${escapeHtml(headerLabels.psychoAve)}</th>
                ${raterHeaders()}
                <th class="col-yellow-ave" style="width: 55px; border-left: 2px solid #000 !important;" contenteditable="true" onblur="updateHeaderLabel(this,'potentialAve')">${escapeHtml(headerLabels.potentialAve)}</th>
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
            <div class="table-block"${positionAttr}>
                <div class="table-scroll">
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
            <div class="signatory-section">
                <div class="sig-box"><div class="sig-line"></div><div class="sig-title">HRMPSB SECRETARIAT</div><div class="sig-sub">Prepared By</div></div>
                <div class="sig-box"><div class="sig-line"></div><div class="sig-title">HRMPSB COMMITTEE MEMBER</div><div class="sig-sub">Attested By</div></div>
                <div class="sig-box"><div class="sig-line"></div><div class="sig-title">CHAIRPERSON, HRMPSB</div><div class="sig-sub">Approved By</div></div>
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
        if (!skipNextSync) syncFromDom();
        skipNextSync = false;
        const tabsBar = document.getElementById('tabs-bar');
        const wrapper = document.getElementById('tables-wrapper');
        tabsBar.innerHTML = '';
        wrapper.innerHTML = '';
        const branchKeys = Object.keys(groupedData);
        if (branchKeys.length > 0 && !branchKeys.includes(activeTabOffice)) activeTabOffice = branchKeys[0];

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
            closeBtn.onclick = (e) => { e.stopPropagation(); e.preventDefault(); deleteTab(branch); };
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
        addBtn.className = 'excel-tab-add';
        addBtn.innerText = '+ Add Tab';
        addBtn.onclick = promptNewTab;
        tabsBar.appendChild(addBtn);
    }

    function switchTab(branch) { activeTabOffice = branch; renderAll(); }
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