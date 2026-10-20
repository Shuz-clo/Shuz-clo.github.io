<?php
// ============================================================
// index.php — FINAL (sheet-aware, locked layout)
// ============================================================

require_once 'db.php';
require_once __DIR__ . '/AuthSchema.php';
require_once 'auth.php';
require_once __DIR__ . '/Raters.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/sheets_schema.php';

ensure_auth_schema($conn);
ensure_sheets_schema($conn);
purge_expired_tokens($conn);

require_login();

if (current_role() === 'admin') {
    header('Location: results_2.php');
    exit;
}
require_role(['rater', 'user']);

$canSubmit = in_array(current_role(), ['admin', 'rater'], true);
$sheet_id  = isset($_GET['sheet_id']) ? (int)$_GET['sheet_id'] : 0;

$sheetRow   = null;
$layoutData = null;
$cellsData  = [];
$evidenceOptions = load_evidence_options($conn);

if ($sheet_id > 0) {
    $uid = (int)current_user_id();
    $st = $conn->prepare("SELECT * FROM sheets WHERE id = ? AND user_id = ? LIMIT 1");
    $st->bind_param('ii', $sheet_id, $uid);
    $st->execute();
    $sheetRow = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$sheetRow) {
        $sheet_id = 0;
    } else {
        $layoutData = json_decode($sheetRow['layout_json'] ?? '', true);
        if (!is_array($layoutData) || empty($layoutData['sections'])) {
            $layoutData = default_sheets_layout();
        }
        $st = $conn->prepare("SELECT cell_key, `value` FROM sheet_cells WHERE sheet_id = ?");
        $st->bind_param('i', $sheet_id);
        $st->execute();
        $res = $st->get_result();
        while ($row = $res->fetch_assoc()) $cellsData[$row['cell_key']] = $row['value'];
        $st->close();
    }
}

function val(array $cells, string $key): string {
    return htmlspecialchars($cells[$key] ?? '');
}

$allPositions = array_merge(array_keys(leadership_level_titles()), [
    'Administrative Aide I','Administrative Aide III','Administrative Aide IV','Administrative Aide VI',
    'Administrative Assistant I','Administrative Assistant II','Administrative Assistant III',
    'Administrative Officer I','Administrative Officer II','Administrative Officer III','Administrative Officer IV','Administrative Officer V',
    'Cartographer I','Cartographer II','Cartographer III','Cartographer IV',
    'Chief Administrative Officer','Chief Ecosystems Management Specialist',
    'Communication Development Officer I','Communication Development Officer II',
    'Community Development Assistant II','Community Development Officer II',
    'Community Environment & Natural Resources Officer','Credit Officer I',
    'Development Management Officer I','Development Management Officer II','Development Management Officer III','Development Management Officer IV','Development Management Officer V',
    'Draftsman I','Draftsman II','Ecosystems Management Specialist I','Ecosystems Management Specialist II',
    'Engineer I (Geodetic Engineer)','Engineer II (Geodetic Engineer)','Engineer III (Geodetic Engineer)','Engineer IV (Geodetic Engineer)','Engineer V (Geodetic Engineer)',
    'Engineering Aide','Forest Ranger','Forest Technician I','Forest Technician II','Forester I','Forester II','Forester III',
    'Information Officer I','Information Officer II',
    'Information Systems Analyst I','Information Systems Analyst II','Information Systems Analyst III',
    'Laborer I','Land Management Inspector','Land Management Officer I','Land Management Officer II','Land Management Officer III',
    'Legal Assistant II','Mathematician Aide I','Mathematician Aide II','Mathematician I',
    'Park Attendant II','Park Maintenance Foreman','Park Operation Superintendent IV','Park Operation Superintendent V',
    'Planning Officer I','Planning Officer II','Planning Officer III','Planning Officer IV','Planning Officer V',
    'Project Evaluation Officer I','Project Evaluation Officer II','Project Evaluation Officer III',
    'Provincial Environment & Natural Resources Officer',
    'Scaler','Senior Communications Development Officer','Senior Ecosystems Management Specialist',
    'Special Investigator I','Statistician I','Statistician II',
    'Supervising Administrative Officer','Supervising Ecosystems Management Specialist',
    'Tracer','Tree Marker','Utility Worker I','Zoology Technician',
]);
$allPositionsJson = json_encode($allPositions, JSON_UNESCAPED_UNICODE);

$leadMap = [];
foreach (leadership_level_map() as $compact => $lvl) {
    $leadMap[$compact] = $lvl;
}
$leadMapJson = json_encode($leadMap, JSON_UNESCAPED_UNICODE);

$offices = ['PENRO Iloilo','PENRO Capiz','PENRO Aklan','PENRO Antique','PENRO Guimaras','PENRO Negros Occidental','Regional Office'];

$todayDate = date('F d, Y');
$dateVal = $cellsData['__header.interview_date'] ?? '';
if (trim($dateVal) === '') {
    $dateVal = $todayDate;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="chrome" content="nofill">
    <title>DENR Interview Scoring Sheet</title>
    <style>
        body { font-family: Arial, Helvetica, sans-serif; font-size: 13px; color: #000; background-color: #f8f9fa; margin: 20px; }
        .user-bar { max-width: 960px; margin: 0 auto 10px; display: flex; justify-content: space-between; align-items: center; font-size: 13px; color: #555; }
        .user-bar .nav-links a { color: #107c41; text-decoration: none; font-weight: bold; margin-right: 15px; }
        .user-bar .logout-btn { background: #dc3545; color: #fff; text-decoration: none; padding: 6px 14px; border-radius: 4px; font-weight: bold; }

        form { max-width: 960px; margin: 0 auto; background: #fff; padding: 20px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        col.col-desc { width: 58%; } col.col-ev { width: 27%; } col.col-rate { width: 15%; }
        td, th { border: 1px solid #000; padding: 6px 8px; vertical-align: middle; word-wrap: break-word; }
        .header-cell { border: none !important; text-align: center; font-size: 13px; padding: 2px 0; }
        .header-title { border: none !important; text-align: center; font-weight: bold; font-size: 14px; padding: 10px 0; }
        .info-cell { border: none !important; padding: 4px 0; font-size: 13px; }

        input[type="text"], input[type="number"], select, textarea {
            width: 100%; padding: 4px; box-sizing: border-box; border: none; outline: none;
            background: transparent; font-family: inherit; font-size: inherit; color: #000;
        }
        input[readonly], textarea[readonly] { color: #333; }
        select:disabled { color: #000; opacity: 1; -webkit-text-fill-color: #000; }
        input[type="number"] { text-align: center; font-weight: bold; }

        .sec-header { background-color: #d9d9d9 !important; font-weight: bold !important; text-align: center !important; vertical-align: middle !important; }
        
        .ev-label, .ev-cell { 
            background-color: #d9d9d9 !important; 
            font-weight: bold !important; 
            text-align: center !important; 
            padding: 0 !important; 
        }
        .ev-cell { padding: 6px !important; text-align: left !important; }
        
        .comp-title { font-weight: bold; background-color: #fff; }
        textarea { resize: none; overflow: hidden; }
        .comp-ta { font-weight: bold; }

        .ev-label select { text-align: center; font-weight: bold; cursor: default; padding: 6px; height: 100%; display: block; }

        .position-wrap { position: relative; display: inline-block; width: 70%; vertical-align: middle; }
        .position-wrap input#position_input { width: 100%; border-bottom: 1px solid #000; padding: 4px 2px; }
        #positionDropdown { display: none; position: absolute; top: 100%; left: 0; right: 0; z-index: 30; max-height: 260px; overflow-y: auto; background: #fff; border: 1px solid #999; box-shadow: 0 2px 8px rgba(0,0,0,0.18); }
        #positionDropdown.open { display: block; }
        #positionDropdown .pd-item { padding: 5px 8px; font-size: 12px; cursor: pointer; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        #positionDropdown .pd-item:hover, #positionDropdown .pd-item.active { background: #107c41; color: #fff; }
        #positionDropdown .pd-empty { padding: 6px 8px; font-size: 12px; color: #777; font-style: italic; }

        .btn-container { margin-top: 20px; text-align: center; }
        button { padding: 8px 18px; font-size: 14px; font-weight: bold; cursor: pointer; margin: 0 5px; }
        tr.is-hidden { display: none; }

        #raterDropdown summary { cursor: pointer; border-bottom: 1px solid #000; padding: 4px; list-style: none; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: block; }
        #raterDropdown > div { display: none; position: absolute; left: 0; top: 100%; z-index: 20; background: #fff; border: 1px solid #999; box-shadow: 0 2px 6px rgba(0,0,0,0.2); padding: 6px 12px; min-width: 200px; }
        #raterDropdown[open] > div { display: block; }
        .rater-check { width: auto !important; margin-right: 5px; }

        @media print {
            select, input, textarea {
                appearance: none; -webkit-appearance: none; -moz-appearance: none;
                border: none !important; background: transparent !important; box-shadow: none !important;
                resize: none !important;
            }
            select::-ms-expand { display: none; }
            ::placeholder { color: transparent !important; }
            input[type=number]::-webkit-inner-spin-button,
            input[type=number]::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
            
            .sec-header, .ev-label, .ev-cell {
                background-color: #d9d9d9 !important;
                -webkit-print-color-adjust: exact; print-color-adjust: exact;
            }
            
            .no-print, .rail, button { display: none !important; }
            table { page-break-inside: auto; }
            tr { page-break-inside: avoid; }
        }
    </style>
</head>
<body>

<div class="user-bar no-print">
    <div>
        <span>Signed in as <strong><?php echo htmlspecialchars($_SESSION['username'] ?? ''); ?></strong></span>
        <span class="nav-links" style="margin-left: 15px;">
            <a href="sheets.php">Sheets Dashboard</a>
        </span>
    </div>
    <a href="logout.php" class="logout-btn">Log out</a>
</div>

<?php if ($sheet_id === 0): ?>
    <div style="max-width: 960px; margin: 50px auto; text-align: center; background: #fff; padding: 40px; box-shadow: 0 0 10px rgba(0,0,0,0.1);">
        <h2>No Sheet Selected</h2>
        <p style="color: #666; margin-bottom: 20px;">Please go to the Dashboard, create a new sheet, and click "Open in Main Page".</p>
        <a href="sheets.php" style="background: #107c41; color: #fff; text-decoration: none; padding: 10px 20px; border-radius: 4px; font-weight: bold;">Go to Dashboard</a>
    </div>
<?php else: ?>
    <form id="scoringForm" action="submit_score.php" method="POST" autocomplete="off">
    <input type="text" name="<?php echo HONEYPOT_FIELD; ?>" tabindex="-1" autocomplete="new-password" aria-hidden="true" style="position:absolute; left:-9999px; width:1px; height:1px;">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
    <input type="hidden" name="sheet_id" value="<?php echo (int)$sheet_id; ?>">

    <table>
        <colgroup><col class="col-desc"><col class="col-ev"><col class="col-rate"></colgroup>
        <tr><td colspan="3" class="header-cell">Department of Environment and Natural Resources</td></tr>
        <tr><td colspan="3" class="header-cell">Region VI, Western Visayas</td></tr>
        <tr><td colspan="3" class="header-cell">Regional Human Resource Merit Promotion and Selection Board (RHRMPSB)</td></tr>
        <tr><td colspan="3" class="header-title">INTERVIEW SCORING SHEET</td></tr>

        <tr>
            <td class="info-cell">NAME:
                <input type="text" id="f_candidate_name" name="candidate_name" value="<?php echo val($cellsData, '__header.candidate_name'); ?>" readonly required autocomplete="off" style="width: 75%; border-bottom:1px solid #000;">
            </td>
            <td colspan="2" class="info-cell">Position:
                <span class="position-wrap">
                    <input type="text" id="f_position_title" name="position_title" value="<?php echo val($cellsData, '__header.position_title'); ?>" readonly placeholder="Type or pick a position title" required autocomplete="off" spellcheck="false" maxlength="255" style="border-bottom:1px solid #000;">
                    <div id="positionDropdown" role="listbox"></div>
                </span>
            </td>
        </tr>
        <tr>
            <td class="info-cell"></td>
            <td colspan="2" class="info-cell">Office:
                <select id="f_office_name" name="office_name" disabled required style="width: 70%; border-bottom:1px solid #000;">
                    <option value="">-- Select Office --</option>
                    <?php foreach ($offices as $opt): ?>
                        <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo (($cellsData['__header.office_name'] ?? '') === $opt) ? 'selected' : ''; ?>><?php echo htmlspecialchars($opt); ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="hidden" name="office_name" value="<?php echo val($cellsData, '__header.office_name'); ?>">
            </td>
        </tr>
        <tr>
            <td class="info-cell"></td>
            <td colspan="2" class="info-cell" style="padding-bottom: 15px;">Date:
                <input type="text" id="f_interview_date" name="interview_date" value="<?php echo htmlspecialchars($dateVal); ?>" autocomplete="off" style="width: 70%; border-bottom:1px solid #000;">
            </td>
        </tr>

        <?php
        $order = ['core', 'org', 'lead', 'func'];
        foreach ($order as $sectionKey):
            if (empty($layoutData['sections'][$sectionKey])) continue;
            $sec = $layoutData['sections'][$sectionKey];
            $isLead = ($sectionKey === 'lead');
        ?>
            <tr class="<?php echo $isLead ? 'leadership-row ' : ''; ?>sec-right">
                <th class="sec-header"><?php echo htmlspecialchars($sec['label']); ?></th>
                <th class="sec-header">Evidence</th>
                <th class="sec-header">Rating</th>
            </tr>
            <?php foreach ($sec['rows'] as $row):
                $rowId = $row['id'];
                $rowTitle = (($cellsData[$rowId . '.title'] ?? '') !== '') ? $cellsData[$rowId . '.title'] : ($row['title'] ?? '');
                $rowDesc  = (($cellsData[$rowId . '.desc']  ?? '') !== '') ? $cellsData[$rowId . '.desc']  : ($row['desc']  ?? '');
                $levelVal = (($cellsData[$rowId . '.level'] ?? '') !== '') ? $cellsData[$rowId . '.level'] : ($isLead ? 'Basic' : 'Advanced');
                $evidVal  = $cellsData[$rowId . '.evidence'] ?? '';
                $rateVal  = $cellsData[$rowId . '.rating']   ?? '';
                $hidden   = $isLead ? ' leadership-row' : '';
            ?>
                <tr class="comp-title-row<?php echo $hidden; ?>" data-row-id="<?php echo htmlspecialchars($rowId); ?>">
                    <td class="comp-title">
                        <textarea name="title_<?php echo htmlspecialchars($rowId); ?>" class="comp-ta" readonly rows="1" style="resize:none; overflow:hidden;"><?php echo htmlspecialchars($rowTitle); ?></textarea>
                    </td>
                    <td class="ev-label">
                        <select name="evtype_<?php echo htmlspecialchars($rowId); ?>" disabled>
                            <option value="">--</option>
                            <?php foreach ($evidenceOptions as $eo): ?>
                                <option value="<?php echo htmlspecialchars($eo); ?>" <?php echo $levelVal === $eo ? 'selected' : ''; ?>><?php echo htmlspecialchars($eo); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" name="evtype_<?php echo htmlspecialchars($rowId); ?>" value="<?php echo htmlspecialchars($levelVal); ?>">
                    </td>
                    <td></td>
                </tr>
                <tr class="comp-desc-row<?php echo $hidden; ?>" data-row-id="<?php echo htmlspecialchars($rowId); ?>">
                    <td>
                        <textarea name="desc_<?php echo htmlspecialchars($rowId); ?>" readonly rows="1" style="resize:none; overflow:hidden;"><?php echo htmlspecialchars($rowDesc); ?></textarea>
                    </td>
                    <td class="ev-cell">
                        <textarea name="evidence_<?php echo htmlspecialchars($rowId); ?>" rows="1" placeholder="Evidence" style="background: transparent; font-weight: bold; resize:none; overflow:hidden;"><?php echo htmlspecialchars($evidVal); ?></textarea>
                    </td>
                    <td>
                        <input type="number" step="0.01" min="0" max="10" name="<?php echo htmlspecialchars($rowId); ?>" value="<?php echo htmlspecialchars($rateVal); ?>" placeholder="0.00">
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endforeach; ?>

        <tr>
            <td class="info-cell" style="border-top: 1px solid #000 !important; padding-top: 15px;">Rated by:
                <input type="text" name="rater_name" value="<?php echo val($cellsData, '__header.rater_name'); ?>" placeholder="Rater Name" style="width: 70%; border-bottom:1px solid #000;">
            </td>
            <td class="info-cell" colspan="2" style="border-top: 1px solid #000 !important; padding-top: 15px;">Raters:
                <details id="raterDropdown" style="position: relative; display: inline-block; width: 60%; vertical-align: middle;">
                    <summary id="raterSummary">-- Select Raters --</summary>
                    <input type="hidden" name="raters_order" id="ratersOrder" value="">
                    <div>
                        <?php foreach (rater_list() as $raterLabel): ?>
                            <label style="display: block; margin-bottom: 4px; white-space: nowrap;">
                                <input type="checkbox" class="rater-check" name="raters[]" value="<?php echo htmlspecialchars($raterLabel); ?>" data-label="<?php echo htmlspecialchars($raterLabel); ?>">
                                <?php echo htmlspecialchars($raterLabel); ?>
                            </label>
                        <?php endforeach; ?>
                        <a href="#" id="toggleAllRaters" style="font-size: 12px; display: inline-block; margin-top: 4px;">Select all / none</a>
                    </div>
                </details>
            </td>
        </tr>
    </table>

    <div class="btn-container no-print">
        <button type="button" id="saveBtn">Save</button>
        <button type="button" onclick="window.print()">Print / Save as PDF</button>
        <a href="sheets.php" style="text-decoration:none; padding:8px 18px; background:#eee; color:#333; border-radius:4px; border:1px solid #ccc; font-size:14px; font-weight:bold;">Back to Sheets</a>
    </div>
    </form>
<?php endif; ?>

<script>
(function () {
  'use strict';

  const CAN_SUBMIT   = <?php echo $canSubmit ? 'true' : 'false'; ?>;
  const ALL_POSITIONS= <?php echo $allPositionsJson; ?>;
  const LEAD_MAP     = <?php echo $leadMapJson; ?>;
  const LEADERSHIP_HIDDEN_POSITIONS = ['SVEMS', 'SUPERVISING ECOSYSTEMS MANAGEMENT SPECIALIST'];

  let positionInput    = null;
  let positionDropdown = null;
  let activeIndex      = -1;
  let raterClickOrder  = [];

  function $(id) { return document.getElementById(id); }
  function on(id, evt, fn) { const el = $(id); if (el) el.addEventListener(evt, fn); }

  function posCompact(s) { return String(s || '').toUpperCase().replace(/[^A-Z0-9]/g, ''); }

  const POSITION_ALIASES = {
    'ATY5':'Attorney V','ATTY5':'Attorney V','ATTY4':'Attorney IV','ATY4':'Attorney IV',
    'ATTY3':'Attorney III','ATY3':'Attorney III','ATTY2':'Attorney II','ATY2':'Attorney II',
    'PLO1':'Planning Officer I','PLO2':'Planning Officer II','PLO3':'Planning Officer III','PLO4':'Planning Officer IV','PLO5':'Planning Officer V',
    'PEO1':'Project Evaluation Officer I','PEO2':'Project Evaluation Officer II','PEO3':'Project Evaluation Officer III',
    'DMO1':'Development Management Officer I','DMO2':'Development Management Officer II','DMO3':'Development Management Officer III','DMO4':'Development Management Officer IV','DMO5':'Development Management Officer V',
    'ENG1':'Engineer I (Geodetic Engineer)','ENG2':'Engineer II (Geodetic Engineer)','ENG3':'Engineer III (Geodetic Engineer)','ENG4':'Engineer IV (Geodetic Engineer)','ENG5':'Engineer V (Geodetic Engineer)',
    'FOR1':'Forester I','FOR2':'Forester II','FOR3':'Forester III','FORST1':'Forester I','FORST2':'Forester II','FORST3':'Forester III',
    'FT1':'Forest Technician I','FT2':'Forest Technician II',
    'LMO1':'Land Management Officer I','LMO2':'Land Management Officer II','LMO3':'Land Management Officer III',
    'LAMI':'Land Management Inspector',
    'ADOF1':'Administrative Officer I','ADOF2':'Administrative Officer II','ADOF3':'Administrative Officer III','ADOF4':'Administrative Officer IV','ADOF5':'Administrative Officer V',
    'AO1':'Administrative Officer I','AO2':'Administrative Officer II','AO3':'Administrative Officer III','AO4':'Administrative Officer IV','AO5':'Administrative Officer V',
    'ADAS1':'Administrative Assistant I','ADAS2':'Administrative Assistant II','ADAS3':'Administrative Assistant III',
    'ADA1':'Administrative Aide I','ADA3':'Administrative Aide III','ADA4':'Administrative Aide IV','ADA6':'Administrative Aide VI',
    'PENRO':'Provincial Environment & Natural Resources Officer',
    'CENRO':'Community Environment & Natural Resources Officer',
    'SVEMS':'Supervising Ecosystems Management Specialist',
    'SREMS':'Senior Ecosystems Management Specialist',
    'EMS1':'Ecosystems Management Specialist I','EMS2':'Ecosystems Management Specialist II',
    'CECMS':'Chief Ecosystems Management Specialist',
    'CADOF':'Chief Administrative Officer','SAO':'Supervising Administrative Officer','SADOF':'Supervising Administrative Officer',
    'SRCDO':'Senior Communications Development Officer',
    'LAMO1':'Land Management Officer I','LAMO2':'Land Management Officer II','LAMO3':'Land Management Officer III',
    'INFOSA1':'Information Systems Analyst I','INFOSA2':'Information Systems Analyst II','INFOSA3':'Information Systems Analyst III',
    'CGR1':'Cartographer I','CGR2':'Cartographer II','CGR3':'Cartographer III','CGR4':'Cartographer IV'
  };

  const POSITION_LOOKUP = (function () {
    const map = {};
    ALL_POSITIONS.forEach(t => { map[posCompact(t)] = t; });
    Object.keys(POSITION_ALIASES).forEach(k => {
      const c = POSITION_ALIASES[k];
      if (ALL_POSITIONS.indexOf(c) !== -1 && !(k in map)) map[k] = c;
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

  function compactPosition(s) {
    return String(s || '')
      .replace(/&/g, ' AND ')
      .replace(/\s+/g, ' ')
      .trim()
      .toUpperCase()
      .replace(/[^\p{L}\p{N}]/gu, '');
  }
  function compactNoParens(s) {
    return compactPosition(String(s || '').replace(/\([^)]*\)/g, ' '));
  }
  function leadershipLevelFor(pos) {
    const full = compactPosition(pos);
    if (full === '') return null;
    if (full in LEAD_MAP) return LEAD_MAP[full];
    const base = compactNoParens(pos);
    return (base !== '' && base in LEAD_MAP) ? LEAD_MAP[base] : null;
  }

  function applyLeadership() {
    if (!positionInput) return;
    const pos = positionInput.value.trim();
    const level = leadershipLevelFor(pos);
    const noLead = (pos === '') || LEADERSHIP_HIDDEN_POSITIONS.indexOf(pos.toUpperCase()) !== -1 || (level === null);
    document.querySelectorAll('.leadership-row').forEach(function (row) {
      row.style.display = noLead ? 'none' : '';
      row.classList.toggle('is-hidden', noLead);
    });
  }

  function renderPositionDropdown(filter) {
    if (!positionDropdown) return;
    const q = posCompact(filter);
    const matches = ALL_POSITIONS.filter(t => posCompact(t).indexOf(q) !== -1);
    positionDropdown.innerHTML = '';
    if (matches.length === 0) {
      const d = document.createElement('div');
      d.className = 'pd-item';
      d.style.cursor = 'default';
      d.style.color = '#777';
      d.style.fontStyle = 'italic';
      d.textContent = 'No matching position';
      positionDropdown.appendChild(d);
      positionDropdown.classList.add('open');
      activeIndex = -1;
      return;
    }
    matches.slice(0, 80).forEach(function (title, i) {
      const item = document.createElement('div');
      item.className = 'pd-item' + (i === activeIndex ? ' active' : '');
      item.textContent = title;
      item.addEventListener('mousedown', function (e) {
        e.preventDefault();
        positionInput.value = title;
        positionDropdown.classList.remove('open');
        activeIndex = -1;
        applyLeadership();
      });
      positionDropdown.appendChild(item);
    });
    positionDropdown.classList.add('open');
  }
  function closePositionDropdown() {
    if (!positionDropdown) return;
    positionDropdown.classList.remove('open');
    activeIndex = -1;
  }

  function attachPositionEvents() {
    if (!positionInput || !positionDropdown) return;
    if (positionInput.readOnly) return;

    positionInput.addEventListener('focus', function () { renderPositionDropdown(positionInput.value); });
    positionInput.addEventListener('input', function () { activeIndex = -1; renderPositionDropdown(positionInput.value); applyLeadership(); });
    positionInput.addEventListener('keydown', function (e) {
      if (!positionDropdown.classList.contains('open')) {
        if (e.key === 'ArrowDown') { renderPositionDropdown(positionInput.value); e.preventDefault(); }
        return;
      }
      const items = positionDropdown.querySelectorAll('.pd-item:not([style*="cursor:default"])');
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        activeIndex = Math.min(activeIndex + 1, items.length - 1);
        items.forEach((el, i) => el.classList.toggle('active', i === activeIndex));
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        activeIndex = Math.max(activeIndex - 1, 0);
        items.forEach((el, i) => el.classList.toggle('active', i === activeIndex));
      } else if (e.key === 'Enter') {
        if (activeIndex >= 0 && items[activeIndex]) {
          e.preventDefault();
          positionInput.value = items[activeIndex].textContent;
          closePositionDropdown();
          applyLeadership();
        } else {
          positionInput.value = canonicalPositionTitle(positionInput.value);
          closePositionDropdown();
          applyLeadership();
        }
      } else if (e.key === 'Escape') {
        closePositionDropdown();
      }
    });
    positionInput.addEventListener('blur', function () {
      setTimeout(function () {
        if (!positionDropdown.contains(document.activeElement)) {
          positionInput.value = canonicalPositionTitle(positionInput.value);
          closePositionDropdown();
          applyLeadership();
        }
      }, 120);
    });
    document.addEventListener('click', function (e) {
      if (!positionDropdown.contains(e.target) && e.target !== positionInput) closePositionDropdown();
    });
  }

  function selectedRaters() { return Array.from(document.querySelectorAll('input.rater-check:checked')); }
  function syncRaterOrder() {
    const checked = selectedRaters().map(c => c.dataset.label);
    raterClickOrder = raterClickOrder.filter(l => checked.indexOf(l) !== -1);
    checked.forEach(l => { if (raterClickOrder.indexOf(l) === -1) raterClickOrder.push(l); });
  }
  function updateRaterSummary() {
    syncRaterOrder();
    const sum = $('raterSummary');
    const order = $('ratersOrder');
    if (sum) sum.textContent = raterClickOrder.length ? raterClickOrder.join(', ') : '-- Select Raters --';
    if (order) order.value = raterClickOrder.join(',');
  }
  function toggleAllRaters() {
    const boxes = Array.from(document.querySelectorAll('input.rater-check'));
    const allOn = boxes.every(b => b.checked);
    boxes.forEach(b => { b.checked = !allOn; });
    if (allOn) raterClickOrder = [];
    updateRaterSummary();
  }

  function saveOnly() {
    if (!CAN_SUBMIT) {
      alert('Your account is view-only. Ask an administrator for a Rater account.');
      return;
    }
    const form = $('scoringForm');
    const btn  = $('saveBtn');
    if (!form || !btn) return;

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
    let ok = false;

    fetch('submit_score.php', {
      method: 'POST',
      body: new FormData(form),
      headers: { 'Accept': 'application/json' }
    })
    .then(res => {
      const ct = res.headers.get('content-type') || '';
      if (!ct.includes('application/json')) {
        return res.text().then(t => { throw new Error('Server returned HTML: ' + t.replace(/<[^>]*>/g, ' ').slice(0, 300)); });
      }
      return res.json();
    })
    .then(data => {
      if (data.status === 'success') {
        ok = true;
        alert('Saved successfully to the main database!');
        window.location.href = 'results_2.php';
      } else {
        alert(data.message || 'Save failed.');
      }
    })
    .catch(err => alert('Save error: ' + err.message))
    .finally(() => { if (!ok) btn.disabled = false; });
  }

  // Autosize so long titles/descriptions wrap like the Excel sheet.
  function autosize(el) {
    if (el && el.tagName === 'TEXTAREA') {
      el.style.height = 'auto';
      el.style.height = (el.scrollHeight || 0) + 'px';
    }
  }
  function autosizeAll() {
    document.querySelectorAll('textarea').forEach(autosize);
  }

  document.addEventListener('DOMContentLoaded', function () {
    positionInput    = $('f_position_title');
    positionDropdown = $('positionDropdown');

    attachPositionEvents();
    applyLeadership();

    on('saveBtn', 'click', saveOnly);
    on('toggleAllRaters', 'click', function (e) { e.preventDefault(); toggleAllRaters(); });

    document.querySelectorAll('input.rater-check').forEach(function (b) {
      b.addEventListener('change', updateRaterSummary);
    });
    updateRaterSummary();

    document.addEventListener('click', function (e) {
      const dd = $('raterDropdown');
      if (dd && !dd.contains(e.target)) dd.open = false;
    });

    const form = $('scoringForm');
    if (form) {
      form.addEventListener('input', function (e) {
        const el = e.target;
        if (el && el.type === 'number') {
          if (el.value !== '' && parseFloat(el.value) > 10) el.value = '10';
          if (el.value !== '' && parseFloat(el.value) < 0) el.value = '0';
        }
        if (el && el.tagName === 'TEXTAREA') autosize(el);
      });
      form.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && e.target.tagName !== 'TEXTAREA' && e.target.tagName !== 'BUTTON') e.preventDefault();
      });
    }

    autosizeAll();
    window.addEventListener('beforeprint', autosizeAll);
    window.addEventListener('resize', autosizeAll);
    document.addEventListener('input', function (e) { if (e.target.tagName === 'TEXTAREA') autosize(e.target); });
  });
})();
</script>

</body>
</html>