<?php
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

$evidenceOptions = load_evidence_options($conn);

$leadMap = [];
foreach (leadership_level_map() as $compact => $lvl) {
    $leadMap[$compact] = $lvl;
}
$leadMapJson = json_encode($leadMap, JSON_UNESCAPED_UNICODE);

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

$offices = ['PENRO Iloilo','PENRO Capiz','PENRO Aklan','PENRO Antique','PENRO Guimaras','PENRO Negros Occidental','Regional Office'];
$officesJson = json_encode($offices, JSON_UNESCAPED_UNICODE);
$evidenceOptionsJson = json_encode($evidenceOptions, JSON_UNESCAPED_UNICODE);

$todayDate = date('F j, Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="chrome" content="nofill">
<title>Sheets Dashboard</title>
<style>
  :root { --bg:#f8f9fa; --rail:#0d0d0d; --accent:#6ea8fe; --blue:#2b579a; }
  * { box-sizing: border-box; }
  body { margin:0; height:100vh; display:flex; background:var(--bg); color:#000; font-family:"Calibri","Segoe UI",Arial,sans-serif; }

  .rail { width:175px; background:var(--rail); display:flex; flex-direction:column; padding-top:10px; flex-shrink:0; }
  .rail button { background:none; border:0; border-left:3px solid transparent; color:#fff; padding:18px 0; font-size:15px; cursor:pointer; display:flex; flex-direction:column; align-items:center; gap:8px; }
  .rail button.active { border-left-color:var(--accent); }
  .rail button:hover { background:#1c1c1c; }
  .rail svg { width:26px; height:26px; stroke:#fff; fill:none; stroke-width:1.4; }
  .rail .spacer { flex:1; }
  .rail .small { font-size:13px; padding:12px 0; flex-direction:row; justify-content:center; }

  #homeView { flex:1; padding:24px 40px; overflow:auto; background:#fff; }
  .templates { display:flex; gap:22px; padding-bottom:22px; border-bottom:1px solid #ccc; margin-bottom:20px; }
  .tpl { width:225px; padding:18px 0 12px; text-align:center; border:2px solid transparent; border-radius:4px; cursor:pointer; background:none; color:#000; font-size:14px; }
  .tpl:hover { background:#eef2f5; border-color:#107c41; }
  .tpl .page { width:90px; height:115px; background:#fff; margin:0 auto 14px; border:1px solid #999; }
  #search { padding:10px 14px; border:1px solid #ccc; width:300px; margin-bottom:20px; }
  .list-head { display:flex; padding:0 12px 10px; color:#555; font-size:14px; border-bottom:1px solid #ccc; }
  .list-head > div:first-child { flex:1; }
  .list-head .col-date { width:200px; }
  .list-head .col-status { width:100px; }
  .list-head .col-actions { width:32px; }
  .sheet-row { display:flex; align-items:center; height:60px; border-bottom:1px solid #eee; cursor:pointer; padding:0 12px; background:#fff; }
  .sheet-row:hover { background:#f4f8f4; }
  .sheet-row .name { flex:1; font-size:16px; }
  .sheet-row .name small { color:#777; font-size:12px; display:block; }
  .sheet-row .col-date { width:200px; color:#555; font-size:13px; }
  .sheet-row .col-status { width:100px; font-size:12px; }
  .sheet-row .col-actions { width:32px; text-align:right; }
  .badge { display:inline-block; padding:2px 8px; border-radius:20px; font-size:11px; font-weight:700; }
  .badge.draft { background:#fff3cd; color:#7a5a00; }
  .badge.submitted { background:#d4edda; color:#155724; }
  .delete-sheet { background:none; border:0; color:#c0392b; font-size:16px; cursor:pointer; }

  .excel-tabs-container {
    display: flex;
    align-items: flex-end;
    gap: 5px;
    background-color: #e1e1e1;
    border: 1px solid #b0b0b0;
    border-bottom: none;
    padding: 6px 8px 0 8px;
    border-radius: 4px 4px 0 0;
    width: 100%;
    max-width: 960px;
    margin-bottom: 0;
  }
  #tabs-bar { display: flex; align-items: flex-end; gap: 3px; flex: 1; overflow-x: auto; }
  .excel-tab {
    display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px;
    background-color: #d6d6d6; border: 1px solid #a6a6a6; border-bottom: none;
    border-radius: 4px 4px 0 0; cursor: pointer; font-size: 11px; font-weight: 600; color: #444; user-select: none; white-space: nowrap;
  }
  .excel-tab.active { background-color: #ffffff; color: #107c41; border-top: 3px solid #107c41; border-bottom: 1px solid #ffffff; font-weight: bold; }
  .tab-close-btn { display: inline-flex; align-items: center; justify-content: center; width: 18px; height: 18px; border-radius: 50%; background: rgba(220, 53, 69, 0.15); color: #c0392b; font-weight: bold; font-size: 13px; margin-left: 6px; cursor: pointer; }
  .tab-close-btn:hover { background: #c0392b; color: #fff; }
  .excel-tab-add { padding: 4px 10px; background-color: #ffffff; border: 1px solid #a6a6a6; border-radius: 3px; cursor: pointer; font-weight: bold; color: #107c41; margin-bottom: 2px; }

  #editorView { flex:1; display:none; flex-direction:column; min-width:0; background:#fff; }
  .bar { background:var(--blue); color:#fff; display:flex; align-items:center; gap:14px; padding:8px 14px; }
  .bar button { background:rgba(255,255,255,.15); border:0; color:#fff; padding:6px 12px; border-radius:4px; cursor:pointer; font-size:13px; }
  .bar button:hover { background:rgba(255,255,255,.28); }
  .bar button.primary { background:#107c41; }
  .bar button.primary:hover { background:#0b5c30; }
  #status { margin-left:auto; font-size:13px; opacity:.9; }

  .gridwrap { flex:1; overflow:auto; padding:20px; background:#f8f9fa; display:flex; flex-direction:column; align-items:center; }
  
  .editor-toolbar { width:100%; max-width:960px; margin-bottom:10px; display:flex; gap:10px; }
  .editor-toolbar button { background:#e8f5e9; border:1px solid #107c41; color:#107c41; font-weight:bold; padding:8px 14px; border-radius:4px; cursor:pointer; font-size:13px; }
  .editor-toolbar button:hover { background:#107c41; color:#fff; }

  .denr-form { width:100%; max-width:960px; background:#fff; padding:20px; box-shadow:0 0 10px rgba(0,0,0,0.1); margin-bottom: 20px; }
  .denr-form table { width:100%; border-collapse:collapse; table-layout:fixed; font-family:Calibri,Arial,sans-serif; font-size:13px; color:#000; }
  
  .denr-form td, .denr-form th { border:1px solid #000; padding:6px 8px; vertical-align:top; word-wrap:break-word; }
  
  .header-cell { border:none !important; text-align:center; padding:2px 0; vertical-align:middle; font-weight:normal; font-size:13px; }
  .header-title { border:none !important; text-align:center; font-weight:bold; font-size:15px; padding:8px 0; vertical-align:middle; }
  .info-cell { border:none !important; padding:4px 0; vertical-align:middle; font-weight:normal; font-size:13px; }
  .sec-header { background:#d9d9d9; font-weight:bold; text-align:center; vertical-align:middle; }
  
  .comp-title { font-weight:bold; background:#fff; }
  .ev-label, .ev-cell { background:#d9d9d9; font-weight:bold; text-align:center; padding:0 !important; }
  .ev-label { vertical-align:middle; background:#d9d9d9 !important; }
  .ev-cell { padding:6px !important; text-align:left !important; background:#fff !important; }
  
  .denr-form input[type="text"], .denr-form input[type="number"], .denr-form select, .denr-form textarea {
    width:100%; padding:4px; border:none; outline:none; background:transparent; font-family:inherit; font-size:inherit;
  }
  .denr-form input[type="text"]:focus, .denr-form input[type="number"]:focus, .denr-form select:focus, .denr-form textarea:focus {
    background:#f6fff9; outline:1px solid #107c41;
  }
  .denr-form input[type="number"] { text-align:center; color:#555; padding-top:6px; }
  .denr-form textarea { resize:vertical; min-height:22px; }

  .position-wrap { position:relative; display:inline-block; width:70%; vertical-align:middle; }
  #positionDropdown { display:none; position:absolute; top:100%; left:0; right:0; z-index:30; max-height:260px; overflow-y:auto; background:#fff; border:1px solid #999; box-shadow:0 2px 8px rgba(0,0,0,0.18); }
  #positionDropdown.open { display:block; }
  #positionDropdown .pd-item { padding:5px 8px; font-size:12px; cursor:pointer; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  #positionDropdown .pd-item:hover, #positionDropdown .pd-item.active { background:#107c41; color:#fff; }

  .del-row-btn { background:transparent; border:none; color:#c0392b; font-size:16px; cursor:pointer; float:right; padding:0 4px; transition:transform 0.1s; }
  .del-row-btn:hover { transform:scale(1.2); }

  .add-table-btn { width:100%; max-width:960px; background:#e8f5e9; border:2px dashed #107c41; color:#107c41; font-weight:bold; padding:12px; text-align:center; cursor:pointer; border-radius:4px; margin-bottom:20px; font-size:14px; }
  .add-table-btn:hover { background:#107c41; color:#fff; }

  tr.is-hidden { display:none; }
</style>
</head>
<body>

<div class="rail">
  <button id="navHome" class="active" type="button"><svg viewBox="0 0 24 24"><path d="M3 11l9-8 9 8M5 10v10h5v-6h4v6h5V10"/></svg>Home</button>
  <button type="button" id="navNew"><svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>New</button>
  <button type="button" id="navOpen"><svg viewBox="0 0 24 24"><path d="M3 6h6l2 3h10v11H3z"/></svg>Open</button>
  <div class="spacer"></div>
  <button type="button" class="small" onclick="location.href='index.php'">Main Sheet</button>
  <button type="button" class="small" onclick="location.href='logout.php'">Log out</button>
</div>

<div id="homeView">
  <h2>Interview Scoring Sheets</h2>
  <div class="templates">
    <button class="tpl" type="button" id="tplNew">
      <div class="page"></div>
      New DENR Interview Sheet
    </button>
  </div>

  <input id="search" placeholder="Search for a sheet" autocomplete="off">

  <div class="list-head">
    <div>Name</div>
    <div class="col-date">Date modified</div>
    <div class="col-status">Status</div>
    <div class="col-actions"></div>
  </div>
  <div id="list"></div>
</div>

<div id="editorView">
  <div class="bar">
    <button type="button" id="btnBack">&larr; Home</button>
    <span id="titleBar" style="font-size:16px; font-weight:bold;">Interview Sheet</span>
    <button type="button" id="saveBtn" class="primary">Save &amp; Open Main Sheet</button>
    <span id="status">Ready</span>
  </div>

  <div class="gridwrap">
    <div class="editor-toolbar no-print">
        <button type="button" id="btnAddHeader">+ Add Empty Row (Header)</button>
        <button type="button" id="btnAddComp">+ Add Competency</button>
    </div>

    <!-- Excel Office Tabs Container -->
    <div class="excel-tabs-container">
        <div id="tabs-bar"></div>
    </div>

    <div id="tablesContainerWrapper"></div>
  </div>
</div>

<script>
(function () {
  'use strict';

  const CSRF          = <?php echo json_encode(csrf_token()); ?>;
  const ALL_POSITIONS = <?php echo $allPositionsJson; ?>;
  const OFFICES       = <?php echo $officesJson; ?>;
  const LEAD_MAP      = <?php echo $leadMapJson; ?>;
  const INIT_EVIDENCE = <?php echo $evidenceOptionsJson; ?>;
  const TODAY_DATE    = <?php echo json_encode($todayDate); ?>;

  const POSITION_ALIASES = {
    'ATY5':'Attorney V','ATTY5':'Attorney V','ATTY4':'Attorney IV','ATY4':'Attorney IV',
    'ATTY3':'Attorney III','ATTY3':'Attorney III','ATTY2':'Attorney II','ATY2':'Attorney II',
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

  const LEADERSHIP_HIDDEN_POSITIONS = ['SVEMS', 'SUPERVISING ECOSYSTEMS MANAGEMENT SPECIALIST'];

  let currentSheetId    = null;
  let layoutData        = null;
  let cellsData         = {};
  let officesData       = { 'REGIONAL OFFICE': [] };
  let activeTabOffice   = 'REGIONAL OFFICE';
  let autoSaveTimer     = null;
  let hasUnsavedChanges = false;
  let savingInFlight    = false;
  let activeIndex       = -1;
  let lastAddedRowId    = null;

  function $(id) { return document.getElementById(id); }
  function on(elOrId, evt, fn) {
    const el = (typeof elOrId === 'string') ? $(elOrId) : elOrId;
    if (el) el.addEventListener(evt, fn);
  }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
  }
  function setStatus(text) { const el = $('status'); if (el) el.textContent = text; }
  function posCompact(s) { return String(s || '').toUpperCase().replace(/[^A-Z0-9]/g, ''); }

  const POSITION_LOOKUP = (function () {
    const map = {};
    ALL_POSITIONS.forEach(function (t) { map[posCompact(t)] = t; });
    Object.keys(POSITION_ALIASES).forEach(function (k) {
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
    return String(s || '').replace(/&/g, ' AND ').replace(/\s+/g, ' ').trim().toUpperCase().replace(/[^\p{L}\p{N}]/gu, '');
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

  async function api(action, data, post) {
    let url = 'sheets_api.php';
    let opts;
    if (post) {
      const f = new FormData();
      f.append('action', action);
      f.append('csrf_token', CSRF);
      Object.keys(data || {}).forEach(function (k) { f.append(k, data[k]); });
      opts = { method:'POST', body: f, headers:{ 'Accept':'application/json' }, credentials:'same-origin' };
    } else {
      url += '?' + new URLSearchParams(Object.assign({ action: action }, data || {}));
      opts = { headers:{ 'Accept':'application/json' }, credentials:'same-origin' };
    }
    const res  = await fetch(url, opts);
    const ct   = res.headers.get('content-type') || '';
    const text = await res.text();
    if (!ct.includes('application/json')) {
      throw new Error('Server returned non-JSON (HTTP ' + res.status + '). Preview: ' + text.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').slice(0, 200));
    }
    let j;
    try { j = JSON.parse(text); } catch (err) { throw new Error('Bad JSON: ' + text.slice(0, 200)); }
    if (j.status !== 'success') throw new Error(j.message || 'Request failed');
    return j;
  }

  function applyLeadership() {
    document.querySelectorAll('.leadership-row').forEach(function (row) {
      const posInput = row.closest('.denr-form').querySelector('.f-position-title');
      const pos = posInput ? posInput.value.trim() : '';
      const level = leadershipLevelFor(pos);
      const noLead = (pos === '') || LEADERSHIP_HIDDEN_POSITIONS.indexOf(pos.toUpperCase()) !== -1 || (level === null);
      row.style.display = noLead ? 'none' : '';
    });
  }

  function renderPositionDropdown(inputEl, dropdownEl, filter) {
    if (!dropdownEl) return;
    const q = posCompact(filter);
    const matches = ALL_POSITIONS.filter(function (t) { return posCompact(t).indexOf(q) !== -1; });
    dropdownEl.innerHTML = '';
    if (matches.length === 0) {
      const d = document.createElement('div');
      d.className = 'pd-item';
      d.style.cursor = 'default'; d.style.color = '#777'; d.style.fontStyle = 'italic';
      d.textContent = 'No matching position';
      dropdownEl.appendChild(d);
      dropdownEl.classList.add('open');
      activeIndex = -1;
      return;
    }
    matches.slice(0, 80).forEach(function (title, i) {
      const item = document.createElement('div');
      item.className = 'pd-item' + (i === activeIndex ? ' active' : '');
      item.textContent = title;
      item.addEventListener('mousedown', function (e) {
        e.preventDefault();
        inputEl.value = title;
        dropdownEl.classList.remove('open');
        activeIndex = -1;
        applyLeadership();
        markDirty();
      });
      dropdownEl.appendChild(item);
    });
    dropdownEl.classList.add('open');
  }

  function attachPositionEvents(inputEl, dropdownEl) {
    if (!inputEl || !dropdownEl) return;
    inputEl.addEventListener('focus', function () { renderPositionDropdown(inputEl, dropdownEl, inputEl.value); });
    inputEl.addEventListener('input', function () {
      activeIndex = -1;
      renderPositionDropdown(inputEl, dropdownEl, inputEl.value);
      applyLeadership();
      markDirty();
    });
    inputEl.addEventListener('blur', function () {
      setTimeout(function () {
        if (!dropdownEl.contains(document.activeElement)) {
          inputEl.value = canonicalPositionTitle(inputEl.value);
          dropdownEl.classList.remove('open');
          applyLeadership();
        }
      }, 120);
    });
  }

  function headerRowHtml(candName='', posTitle='', officeName='', interviewDate='') {
    return ''
      + '<tr><td colspan="3" class="header-cell">Department of Environment and Natural Resources</td></tr>'
      + '<tr><td colspan="3" class="header-cell">Region VI, Western Visayas</td></tr>'
      + '<tr><td colspan="3" class="header-cell">Regional Human Resource Merit Promotion and Selection Board (RHRMPSB)</td></tr>'
      + '<tr><td colspan="3" class="header-title">INTERVIEW SCORING SHEET</td></tr>'
      + '<tr>'
      +   '<td class="info-cell">NAME: <input type="text" class="f-candidate-name" value="' + esc(candName) + '" autocomplete="off" style="width:75%; border-bottom:1px solid #000;"></td>'
      +   '<td colspan="2" class="info-cell">Position: '
      +     '<span class="position-wrap">'
      +       '<input type="text" class="f-position-title" value="' + esc(posTitle) + '" placeholder="Type or pick a position title" autocomplete="off" spellcheck="false" maxlength="255" style="border-bottom:1px solid #000;">'
      +       '<div class="positionDropdown" role="listbox" style="display:none; position:absolute; top:100%; left:0; right:0; z-index:30; max-height:260px; overflow-y:auto; background:#fff; border:1px solid #999; box-shadow:0 2px 8px rgba(0,0,0,0.18);"></div>'
      +     '</span>'
      +   '</td>'
      + '</tr>'
      + '<tr>'
      +   '<td class="info-cell"></td>'
      +   '<td colspan="2" class="info-cell">Office: '
      +     '<select class="f-office-name" style="width:70%; border-bottom:1px solid #000;">'
      +       '<option value="">-- Select Office --</option>'
      +       OFFICES.map(function (o) { return '<option value="' + esc(o) + '" ' + (officeName === o ? 'selected' : '') + '>' + esc(o) + '</option>'; }).join('')
      +     '</select>'
      +   '</td>'
      + '</tr>'
      + '<tr>'
      +   '<td class="info-cell"></td>'
      +   '<td colspan="2" class="info-cell" style="padding-bottom:15px;">Date: '
      +     '<input type="text" class="f-interview-date" value="' + esc(interviewDate || TODAY_DATE) + '" style="width:70%; border-bottom:1px solid #000;">'
      +   '</td>'
      + '</tr>';
  }

  function appendRowPair(tb, sectionKey, row) {
    const isLead = (sectionKey === 'lead');
    const leadClass = isLead ? ' leadership-row' : '';
    const rowId = row.id || ('custom_' + Math.random().toString(36).slice(2, 6));

    const title  = row.title || '';
    const desc   = row.desc  || '';
    const level  = cellsData[rowId + '.level']    || '';
    const evid   = cellsData[rowId + '.evidence'] || '';
    const rating = cellsData[rowId + '.rating']   || '';

    const deleteBtn = '<button type="button" class="del-row-btn" data-action="delete-row" data-section="'+esc(sectionKey)+'" data-row-id="'+esc(rowId)+'" title="Delete this row">&#128465;</button>';

    if (row.is_header) {
      const tr = document.createElement('tr');
      tr.className = 'comp-title-row' + leadClass + (rowId === lastAddedRowId ? ' highlighted-new-row' : '');
      tr.setAttribute('data-row-id', rowId);
      tr.setAttribute('data-section', sectionKey);
      tr.innerHTML = ''
        + '<th colspan="3" class="sec-header" style="text-align:left; padding-left:8px;">'
        +   '<input type="text" class="row-title" value="' + esc(title) + '" placeholder="Sub-Section Title" data-row-id="' + esc(rowId) + '" data-section="' + esc(sectionKey) + '" style="font-weight:bold; background:transparent; border:none; width:90%; outline:none;">'
        +   deleteBtn
        + '</th>';
      tb.appendChild(tr);
      return;
    }

    const tr1 = document.createElement('tr');
    tr1.className = 'comp-title-row' + leadClass + (rowId === lastAddedRowId ? ' highlighted-new-row' : '');
    tr1.setAttribute('data-row-id', rowId);
    tr1.setAttribute('data-section', sectionKey);
    tr1.innerHTML = ''
      + '<td class="comp-title" style="position:relative;">'
      +   '<input type="text" class="row-title" value="' + esc(title) + '" placeholder="Competency title" data-row-id="' + esc(rowId) + '" data-section="' + esc(sectionKey) + '" style="width:90%; font-weight:bold;">'
      +   deleteBtn
      + '</td>'
      + '<td class="ev-label" style="background:#fff !important;">'
      +   '<input type="text" class="row-level" value="' + esc(level) + '" placeholder="" data-row-id="' + esc(rowId) + '" style="font-weight:bold; text-align:center;">'
      + '</td>'
      + '<td></td>';
    tb.appendChild(tr1);

    const tr2 = document.createElement('tr');
    tr2.className = 'comp-desc-row' + leadClass;
    tr2.setAttribute('data-row-id', rowId);
    tr2.setAttribute('data-section', sectionKey);
    tr2.innerHTML = ''
      + '<td><textarea class="row-desc" placeholder="Description" data-row-id="' + esc(rowId) + '" rows="1">' + esc(desc) + '</textarea></td>'
      + '<td class="ev-cell" style="background:#fff !important;"><textarea class="row-evidence" placeholder="" data-row-id="' + esc(rowId) + '" rows="1" style="background:transparent;"></textarea></td>'
      + '<td><input type="number" step="0.01" min="0" max="10" class="row-rating" placeholder="0.00" value="' + esc(rating) + '" data-row-id="' + esc(rowId) + '"></td>';
    tb.appendChild(tr2);
  }

  function buildSection(tb, key, label, rows, defaultLevel) {
    const isLead = (key === 'lead');
    const secClass = isLead ? ' leadership-row' : '';
    const el = document.createElement('tr');
    el.className = secClass;
    el.innerHTML = ''
      + '<th class="sec-header" style="text-align:center; vertical-align:middle;">' + esc(label) + '</th>'
      + '<th class="sec-header" style="text-align:center; vertical-align:middle;">' + esc(defaultLevel) + '</th>'
      + '<th class="sec-header" style="text-align:center; vertical-align:middle;">Rating</th>';
    tb.appendChild(el);

    (rows || []).forEach(function (row) {
      appendRowPair(tb, key, row);
    });
  }

  function buildFooter(tb) {
    const tr = document.createElement('tr');
    tr.innerHTML = '<td colspan="3" class="info-cell" style="border-top:1px solid #000 !important; padding-top:15px;">'
      + 'Rated by: <input type="text" class="f-rater-name" placeholder="Rater Name" autocomplete="off" style="width:60%; border-bottom:1px solid #000;">'
      + '</td>';
    tb.appendChild(tr);
  }

  function buildTableForm(officeName, blockData) {
    const div = document.createElement('div');
    div.className = 'denr-form';
    const tableId = 'table_' + Math.random().toString(36).slice(2, 6);
    div.setAttribute('data-table-id', tableId);

    const table = document.createElement('table');
    table.innerHTML = '<colgroup><col style="width:58%"><col style="width:27%"><col style="width:15%"></colgroup><tbody class="tableBody"></tbody>';
    div.appendChild(table);

    const tb = table.querySelector('.tableBody');
    tb.insertAdjacentHTML('beforeend', headerRowHtml(blockData.candidate_name, blockData.position_title, officeName, blockData.interview_date));

    const order    = ['core', 'org', 'lead', 'func'];
    const defaults = { core:'Evidence', org:'Advanced', lead:'Basic', func:'Advanced' };

    order.forEach(function (key) {
      if (!layoutData.sections[key]) return;
      const sec = layoutData.sections[key];
      buildSection(tb, key, sec.label || key, sec.rows || [], defaults[key]);
    });

    buildFooter(tb);

    const posInput = div.querySelector('.f-position-title');
    const dropEl = div.querySelector('.positionDropdown');
    attachPositionEvents(posInput, dropEl);

    return div;
  }

  function renderTabsAndTables() {
    const tabsBar = $('tabs-bar');
    const containerWrapper = $('tablesContainerWrapper');
    if (!tabsBar || !containerWrapper) return;

    tabsBar.innerHTML = '';
    containerWrapper.innerHTML = '';

    const officesList = Object.keys(officesData);
    if (officesList.length > 0 && !officesList.includes(activeTabOffice)) {
      activeTabOffice = officesList[0];
    }

    officesList.forEach(office => {
      const tab = document.createElement('div');
      tab.className = 'excel-tab ' + (office === activeTabOffice ? 'active' : '');
      tab.innerHTML = '<span>' + esc(office) + '</span><span class="tab-close-btn" title="Delete office tab">×</span>';
      
      tab.addEventListener('click', function() {
        activeTabOffice = office;
        renderTabsAndTables();
      });

      tab.querySelector('.tab-close-btn').addEventListener('click', function(e) {
        e.stopPropagation();
        if (Object.keys(officesData).length <= 1) {
          alert('Cannot delete the last remaining office tab.');
          return;
        }
        if (confirm('Delete office tab "' + office + '"?')) {
          delete officesData[office];
          const remaining = Object.keys(officesData);
          activeTabOffice = remaining[0];
          renderTabsAndTables();
          markDirty();
        }
      });

      tabsBar.appendChild(tab);
    });

    const addTabBtn = document.createElement('button');
    addTabBtn.className = 'excel-tab-add';
    addTabBtn.innerText = '+ Add Tab';
    addTabBtn.addEventListener('click', function() {
      const name = prompt('Enter new Office / Branch name:');
      if (name && name.trim() !== '') {
        const clean = name.trim().toUpperCase();
        if (!officesData[clean]) {
          officesData[clean] = [{ position_title: '', candidate_name: '', interview_date: TODAY_DATE }];
          activeTabOffice = clean;
          renderTabsAndTables();
          markDirty();
        } else {
          activeTabOffice = clean;
          renderTabsAndTables();
        }
      }
    });
    tabsBar.appendChild(addTabBtn);

    const blocks = officesData[activeTabOffice] || [];
    blocks.forEach((blockData, idx) => {
      const formEl = buildTableForm(activeTabOffice, blockData);
      containerWrapper.appendChild(formEl);
    });

    const addTableBtn = document.createElement('button');
    addTableBtn.className = 'add-table-btn';
    addTableBtn.innerText = '+ Add Table for ' + activeTabOffice;
    addTableBtn.addEventListener('click', function() {
      if (!officesData[activeTabOffice]) officesData[activeTabOffice] = [];
      officesData[activeTabOffice].push({ position_title: '', candidate_name: '', interview_date: TODAY_DATE });
      renderTabsAndTables();
      markDirty();
    });
    containerWrapper.appendChild(addTableBtn);

    applyLeadership();
    setTimeout(autosizeAll, 50);
  }

  function defaultLayout() {
    return {
      sections: {
        core: { label: 'Core Competencies', rows: [
          { id:'cc1', title:'CC1 - DISCIPLINE', desc: 'Serves as a good role model on DENR values and ethics to staff/peers' },
          { id:'cc2', title:'CC2 - EXCELLENCE', desc: 'Anticipates, identifies and manages stakeholders’ standards and requirements towards excellent customer service through improving sense of responsibility and competence' },
          { id:'cc3', title:'CC3 - NOBILITY', desc: 'Influences others to observe virtue, goodness, honor, justness and decency in all situations' },
          { id:'cc4', title:'CC4 - RESPONSIBILITY', desc: 'Leads in the observance of the principle of transparency and accountability in the workplace' },
          { id:'cc5', title:'CC5 - CARING FOR THE ENVIRONMENT AND NATURAL RESOURCES', desc: 'Serves as a good role model in conserving and preserving the environment to peers and staff' }
        ]},
        org: { label: 'Organizational Competencies', rows: [
          { id:'oc1', title:'OC1 - WRITING EFFECTIVELY', desc: 'Produces written work from scratch with some guidance while complying to agreed or prescribed standards of communicating with the bureaucracy' },
          { id:'oc2', title:'OC2 - SPEAKING EFFECTIVELY', desc: 'Effectively delivers messages that require careful planning for the method used and the possible impact of the message (audience may be a large group, i.e., office, organization) Focus of communication is to relay information and to build motivation' },
          { id:'oc3', title:'OC3 - TECHNOLOGY LITERACY AND MANAGING INFORMATION', desc: 'Analyzes appropriateness of office software and equipment in the performance of assigned tasks. Develops information assets to achieve organizational goals' },
          { id:'oc4', title:'OC4 - PROJECT MANAGEMENT', desc: 'Leads in project management activities' },
          { id:'oc5', title:'OC5 - COMPLETE STAFF WORK (CSW)', desc: 'Leads the practice of CSW in his/her office/unit' }
        ]},
        lead: { label: 'Leadership Competencies', rows: [
          { id:'lc1', title:'LC1 – STRATEGIC LEADERSHIP (THINKING STRATEGICALLY AND CREATIVELY)', desc: 'Develops office/ service’s strategies and plans based on the DENR’s mission/vision' },
          { id:'lc2', title:'LC2 – LEADING CHANGE', desc: 'Effectively delivers messages that require careful planning for the method used and the possible impact of the message (audience may be a large group, i.e., office, organization) Focus of communication is to relay information and to build motivation' },
          { id:'lc3', title:'LC3 – PEOPLE DEVELOPMENT (CREATING AND NURTURING A HIGH PERFORMING ORGANIZATION)', desc: 'Analyzes appropriateness of office software and equipment in the performance of assigned tasks. Develops information assets to achieve organizational goals' },
          { id:'lc4', title:'LC4 – PEOPLE PERFORMANCE MANAGEMENT (MANAGING PERFORMANCE AND COACHING FOR RESULTS)', desc: 'Leads in project management activities' },
          { id:'lc5', title:'LC5 – PARTNERSHSIP AND NETWORKING (BUILDING COLLABORATIVE AND INCLUSIVE WORKING RELATIONSHIPS)', desc: 'Leads the practice of CSW in his/her office/unit' }
        ]},
        func: { label: 'Functional Competencies', rows: [
          { id:'pco1', title:'PCO1 – CONCEPT AND APPLICATION OF INTEGRATED ECOSYSTEMS MANAGEMENT (IEM)', desc: 'Applies IEM and other related approaches to NRM' },
          { id:'pco2', title:'PCO2 – IDENTIFICATION OF INTERVENTIONS AND INTEGRATING STRATEGIES ACROSS (FORESTRY, COASTAL, AGRICULTURE, URBAN, AIR PACE) AND ZONING FOR STRATEGIC MANAGEMENT', desc: 'Identifies interventions and integrating strategies across sectors' },
          { id:'pco3', title:'PCO3 – CHARACTERIZATION OF ECOSYSTEMS AND USE OF PLANNING TOOLS AND PROCEDURES', desc: 'Undertakes characterization of ecosystems and use of planning tools and procedures' },
          { id:'pco4', title:'PCO4 – RESOURCE MANAGEMENT AND RESTORATION/ REHABILITATION OF DEGRADED ECOSYSTEMS', desc: 'Identifies and implements appropriate resource management and restoration strategies of various ecosystems' }
        ]}
      }
    };
  }

  function bindGlobalEvents() {
    document.addEventListener('input', function (e) {
      const t = e.target;
      if (!t) return;
      if (t.classList.contains('f-candidate_name') || t.classList.contains('f-position_title') || t.classList.contains('f-interview_date') || t.classList.contains('f-rater_name') || t.classList.contains('f-office_name')) {
        markDirty(); return;
      }
      if (t.classList.contains('row-title'))    { cellsData[t.dataset.rowId + '.title']    = t.value; markDirty(); autosize(t); return; }
      if (t.classList.contains('row-desc'))     { cellsData[t.dataset.rowId + '.desc']     = t.value; markDirty(); autosize(t); return; }
      if (t.classList.contains('row-level'))    { cellsData[t.dataset.rowId + '.level']    = t.value; markDirty(); return; }
      if (t.classList.contains('row-evidence')) { cellsData[t.dataset.rowId + '.evidence'] = t.value; markDirty(); autosize(t); return; }
      if (t.classList.contains('row-rating')) {
        let v = t.value;
        if (v !== '' && parseFloat(v) > 10) v = '10';
        if (v !== '' && parseFloat(v) < 0)  v = '0';
        t.value = v;
        cellsData[t.dataset.rowId + '.rating'] = v;
        markDirty(); return;
      }
    });

    document.addEventListener('click', function (e) {
      const btn = e.target.closest('button[data-action="delete-row"]');
      if (!btn) return;
      e.preventDefault();
      if (confirm('Delete this row?')) {
        const rowId = btn.getAttribute('data-row-id');
        const section = btn.getAttribute('data-section');
        deleteRow(section, rowId);
      }
    });
  }

  function promptAddRow(isHeaderRow) {
    if (!currentSheetId) return;
    const label = isHeaderRow ? 'Header Row' : 'Competency';
    const title = prompt('Enter the title for the new ' + label + ':');
    if (!title || title.trim() === '') return;
    
    const secStr = prompt('Which section? Type:\n1 for Core\n2 for Organizational\n3 for Leadership\n4 for Functional', '1');
    if (!secStr) return;
    
    let section = 'core';
    if (secStr === '2') section = 'org';
    else if (secStr === '3') section = 'lead';
    else if (secStr === '4') section = 'func';
    
    if (!layoutData.sections[section]) return;
    
    const id = 'custom_' + Math.random().toString(36).slice(2, 6);
    lastAddedRowId = id;
    layoutData.sections[section].rows.push({ id: id, title: title.trim(), desc: '', is_header: isHeaderRow });
    renderTabsAndTables();
    markDirty();
  }
  
  function deleteRow(section, rowId) {
    if (!layoutData.sections[section]) return;
    layoutData.sections[section].rows = layoutData.sections[section].rows.filter(function(r) {
      return r.id !== rowId;
    });
    Object.keys(cellsData).forEach(function(k) {
      if (k.indexOf(rowId + '.') === 0) delete cellsData[k];
    });
    renderTabsAndTables();
    markDirty();
  }

  function markDirty() {
    hasUnsavedChanges = true;
    setStatus('Not saved');
    clearTimeout(autoSaveTimer);
    autoSaveTimer = setTimeout(triggerAutoSave, 1500);
  }

  function collectData() {
    const officesObj = {};
    document.querySelectorAll('.denr-form').forEach(form => {
      const officeSelect = form.querySelector('.f-office_name');
      const officeName = officeSelect && officeSelect.value ? officeSelect.value : activeTabOffice;
      if (!officesObj[officeName]) officesObj[officeName] = [];

      officesObj[officeName].push({
        candidate_name: form.querySelector('.f-candidate_name')?.value.trim() || '',
        position_title: canonicalPositionTitle(form.querySelector('.f-position_title')?.value.trim() || ''),
        office_name: officeName,
        interview_date: form.querySelector('.f-interview_date')?.value.trim() || ''
      });
    });
    return officesObj;
  }

  function collectCells() {
    const out = {};
    Object.keys(cellsData).forEach(function (k) {
      const v = cellsData[k];
      if (v === undefined || v === null) return;
      if (String(v).length === 0) return;
      out[k] = String(v).substring(0, 5000);
    });
    return out;
  }

  async function triggerAutoSave() {
    if (!currentSheetId) return;
    if (!hasUnsavedChanges) return;
    if (savingInFlight) { markDirty(); return; }

    savingInFlight = true;
    setStatus('Saving...');
    try {
      const firstForm = document.querySelector('.denr-form');
      const mainHeader = firstForm ? {
        candidate_name: firstForm.querySelector('.f-candidate_name')?.value.trim() || '',
        position_title: canonicalPositionTitle(firstForm.querySelector('.f-position_title')?.value.trim() || ''),
        office_name: activeTabOffice,
        interview_date: firstForm.querySelector('.f-interview_date')?.value.trim() || ''
      } : {};

      await api('save', {
        id: currentSheetId,
        header: JSON.stringify(mainHeader),
        layout_json: JSON.stringify(layoutData),
        cells: JSON.stringify(collectCells()),
        offices_json: JSON.stringify(collectData())
      }, true);
      hasUnsavedChanges = false;
      setStatus('Saved ' + new Date().toLocaleTimeString());
    } catch (e) {
      setStatus('Not saved');
      console.error('save error', e);
    } finally {
      savingInFlight = false;
    }
  }

  async function saveNowThenGo() {
    if (!currentSheetId) return;
    clearTimeout(autoSaveTimer);
    hasUnsavedChanges = true;
    savingInFlight = false;
    await triggerAutoSave();
    if (hasUnsavedChanges) {
      alert('Save failed — please check your connection and try again.');
      return;
    }
    window.location.href = 'index.php?sheet_id=' + encodeURIComponent(currentSheetId);
  }

  function showHome() {
    const ed = $('editorView'); const hm = $('homeView');
    if (ed) ed.style.display = 'none';
    if (hm) hm.style.display = 'block';
    currentSheetId = null;
    loadList();
  }
  function showEditor() {
    const ed = $('editorView'); const hm = $('homeView');
    if (hm) hm.style.display = 'none';
    if (ed) ed.style.display = 'flex';
  }

  async function loadList() {
    const box = $('list');
    if (!box) return;
    box.innerHTML = '<div style="padding:20px; color:#777;">Loading...</div>';
    try {
      const j = await api('list');
      box.innerHTML = '';
      if (!j.sheets || !j.sheets.length) {
        box.innerHTML = '<div style="padding:20px; color:#777;">No sheets yet. Click "New DENR Interview Sheet" above.</div>';
        return;
      }
      j.sheets.forEach(function (s) {
        const row = document.createElement('div');
        row.className = 'sheet-row';
        const title = s.title || 'Untitled';
        const cand  = s.candidate_name ? '<small>' + esc(s.candidate_name) + '</small>' : '';
        const status = s.status === 'submitted' ? 'submitted' : 'draft';
        const when = s.updated_at ? new Date(s.updated_at.replace(' ', 'T')).toLocaleString() : '';
        row.innerHTML = ''
          + '<div class="name">' + esc(title) + cand + '</div>'
          + '<div class="col-date">' + esc(when) + '</div>'
          + '<div class="col-status"><span class="badge ' + status + '">' + status + '</span></div>'
          + '<div class="col-actions"><button type="button" class="delete-sheet" title="Delete">&#128465;</button></div>';
        row.addEventListener('click', function () { openSheet(s.id); });
        const delBtn = row.querySelector('.delete-sheet');
        if (delBtn) {
          delBtn.addEventListener('click', function (ev) {
            ev.stopPropagation();
            if (!confirm('Delete "' + title + '"?')) return;
            api('delete', { id: s.id }, true).then(loadList).catch(function (e) { alert(e.message); });
          });
        }
        box.appendChild(row);
      });
    } catch (e) {
      box.innerHTML = '<div style="padding:20px; color:#c0392b;">Failed to load: ' + esc(e.message) + '</div>';
      console.error(e);
    }
  }

  async function createNew() {
    try {
      const j = await api('create', { title: 'Interview Sheet - ' + new Date().toLocaleDateString() }, true);
      await openSheet(j.id);
    } catch (e) { alert('Create failed: ' + e.message); }
  }

  async function openSheet(id) {
    try {
      const j = await api('load', { id: id });
      currentSheetId = id;
      layoutData     = j.layout || defaultLayout();
      cellsData      = j.cells  || {};
      officesData    = j.offices || { 'REGIONAL OFFICE': [{ candidate_name: '', position_title: '', interview_date: TODAY_DATE }] };
      activeTabOffice = Object.keys(officesData)[0] || 'REGIONAL OFFICE';

      showEditor();
      renderTabsAndTables();
      const tb = $('titleBar'); if (tb) tb.textContent = (j.sheet && j.sheet.title) || 'Interview Sheet';
      setStatus('Saved');
      hasUnsavedChanges = false;
    } catch (e) {
      console.error(e);
      alert('Open failed: ' + e.message);
    }
  }

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
    bindGlobalEvents();
    on('navHome', 'click', showHome);
    on('navNew',  'click', createNew);
    on('navOpen', 'click', showHome);
    on('tplNew',  'click', createNew);
    on('btnBack', 'click', function () {
      if (hasUnsavedChanges) triggerAutoSave();
      showHome();
    });
    on('saveBtn', 'click', saveNowThenGo);
    
    on('btnAddHeader', 'click', function() { promptAddRow(true); });
    on('btnAddComp', 'click', function() { promptAddRow(false); });

    on('search', 'input', function (e) {
      const q = e.target.value.trim().toLowerCase();
      document.querySelectorAll('.sheet-row').forEach(function (row) {
        const name = row.querySelector('.name');
        const t = name ? name.textContent.toLowerCase() : '';
        row.style.display = (q === '' || t.indexOf(q) !== -1) ? '' : 'none';
      });
    });

    loadList();
  });
})();
</script>
</body>
</html>