<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/AuthSchema.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/AccessGroups.php';
require_once __DIR__ . '/OfficeTypeMap.php';
require_once __DIR__ . '/PositionsSchema.php';
require_once __DIR__ . '/competency_template_schema.php';

require_role(['rater']);

$profile = find_user_access_profile($conn, (int)current_user_id());
if (!$profile || !access_salary_grade_is_valid($profile['salary_grade'] ?? null)) {
    http_response_code(403);
    exit('No positions can be shown because your account has no valid salary grade saved. Ask an administrator to update your account with a salary grade that exists in Regional Office positions.');
}
if (!array_key_exists((string)($profile['region'] ?? ''), ACCESS_REGIONS)
    || !access_field_is_valid($profile['field_name'] ?? null)) {
    http_response_code(403);
    exit('No positions can be shown because your account is missing a valid region or field. Ask an administrator to update your access profile.');
}
$accountType = account_type_for_profile($profile);
$allowedOfficeTypes = office_types_for_account_type($accountType);
if (!$allowedOfficeTypes) {
    http_response_code(403);
    exit('Your account has no assigned account type. Ask an administrator to update your access profile.');
}

$region = (string)$profile['region'];
$fieldName = (string)$profile['field_name'];
$salaryGrade = (int)$profile['salary_grade'];
$allPositions = [];
$positions = [];
if ($accountType === 'region') {
    $scope = 'REGIONAL_OFFICE';
    $stmt = $conn->prepare("SELECT p.title, p.salary_grade, p.leadership_level,
            (
                SELECT t.id
                FROM competency_templates t
                WHERE t.title = CONCAT('SG ', p.salary_grade, ' - ', p.title)
                    AND t.is_shared = 1
                    AND t.min_salary_grade <= p.salary_grade
                    AND t.max_salary_grade >= p.salary_grade
                    AND (t.region IS NULL OR t.region = ?)
                    AND (t.field_name IS NULL OR t.field_name = ?)
                    AND COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(t.layout_json, '$.office_type')), ''), 'RO') = 'RO'
                ORDER BY t.updated_at DESC, t.id DESC
                LIMIT 1
            ) AS template_id
        FROM positions p
        WHERE p.office_scope = ? AND p.salary_grade = ?
        ORDER BY p.salary_grade DESC, p.title ASC");
    $stmt->bind_param('sssi', $region, $fieldName, $scope, $salaryGrade);
    $stmt->execute();
    $allPositions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $positions = array_values(array_filter($allPositions, static fn(array $position): bool => !empty($position['template_id'])));

    $variantPattern = 'SG ' . $salaryGrade . ' - % [RO:%';
    $variantStmt = $conn->prepare("SELECT id, title FROM competency_templates
        WHERE is_shared = 1
            AND min_salary_grade <= ? AND max_salary_grade >= ?
            AND min_salary_grade <= ? AND max_salary_grade >= ?
            AND (region IS NULL OR region = ?)
            AND (field_name IS NULL OR field_name = ?)
            AND COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(layout_json, '$.office_type')), ''), 'RO') = 'RO'
            AND title LIKE ?
        ORDER BY title ASC");
    $variantStmt->bind_param('iiiisss', $salaryGrade, $salaryGrade, $salaryGrade, $salaryGrade, $region, $fieldName, $variantPattern);
    $variantStmt->execute();
    $variantRows = $variantStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $variantStmt->close();
    $knownPositions = [];
    foreach ($allPositions as $position) $knownPositions[(string)$position['title']] = $position;
    $divisionVariants = [];
    foreach ($variantRows as $variantRow) {
        if (!preg_match('/^SG ' . preg_quote((string)$salaryGrade, '/') . ' - (.+) \[RO: ([^\]]+)\]$/', (string)$variantRow['title'], $matches)) continue;
        $baseTitle = $matches[1];
        if (!isset($knownPositions[$baseTitle])) continue;
        $position = $knownPositions[$baseTitle];
        $position['display_title'] = $baseTitle . ' — ' . $matches[2];
        $position['template_id'] = (int)$variantRow['id'];
        $divisionVariants[$baseTitle][] = $position;
    }
    if ($divisionVariants) {
        $positions = array_values(array_filter($positions, static fn(array $position): bool => !isset($divisionVariants[(string)$position['title']])));
        foreach ($divisionVariants as $variants) array_push($positions, ...$variants);
    }
} else {
    $fieldPositions = field_positions_by_sheet();
    foreach ($fieldPositions as $position) {
        if ($salaryGrade >= (int)$position['min_salary_grade'] && $salaryGrade <= (int)$position['max_salary_grade']) {
            $allPositions[] = $position;
        }
    }
    $stmt = $conn->prepare("SELECT id, title, layout_json FROM competency_templates
        WHERE is_shared = 1 AND min_salary_grade <= ? AND max_salary_grade >= ?
            AND min_salary_grade <= ? AND max_salary_grade >= ?
            AND (region IS NULL OR region = ?) AND (field_name IS NULL OR field_name = ?)
        ORDER BY title ASC, updated_at DESC, id DESC");
    $stmt->bind_param('iiiiss', $salaryGrade, $salaryGrade, $salaryGrade, $salaryGrade, $region, $fieldName);
    $stmt->execute();
    $fieldTemplates = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $templateBySheetPosition = [];
    foreach ($fieldTemplates as $template) {
        $layout = json_decode((string)$template['layout_json'], true);
        if (office_type_for_template_layout($layout, (string)$template['title']) !== 'FO') continue;
        $sourceSheet = trim((string)($layout['source_sheet'] ?? ''));
        if (!preg_match('/^SG ' . preg_quote((string)$salaryGrade, '/') . ' - (.+)$/', (string)$template['title'], $matches)) continue;
        $positionTitle = $matches[1];
        if (!field_position_for_sheet_title($sourceSheet, $positionTitle)) continue;
        $key = $sourceSheet . "\0" . $positionTitle;
        if (!isset($templateBySheetPosition[$key])) $templateBySheetPosition[$key] = (int)$template['id'];
    }
    foreach ($allPositions as $position) {
        $key = $position['sheet_name'] . "\0" . $position['title'];
        if (!isset($templateBySheetPosition[$key])) continue;
        $position['template_id'] = $templateBySheetPosition[$key];
        $position['display_title'] = $position['title'] . ' — ' . $position['sheet_name'];
        $positions[] = $position;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Choose a Position</title>
    <link rel="stylesheet" href="ui-enhancements.css?v=<?php echo filemtime(__DIR__ . '/ui-enhancements.css'); ?>">
    <script src="ui-enhancements.js?v=<?php echo filemtime(__DIR__ . '/ui-enhancements.js'); ?>" defer></script>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 32px 16px; background: #f4f6f9; color: #202124; font-family: Arial, Helvetica, sans-serif; }
        main { max-width: 1000px; margin: 0 auto; }
        header { display: flex; justify-content: space-between; align-items: center; gap: 16px; margin-bottom: 24px; }
        h1 { margin: 0 0 8px; font-size: 26px; }
        p { color: #555; line-height: 1.5; }
        .user-bar { color: #555; font-size: 14px; }
        .user-bar a { color: #107c41; font-weight: 700; }
        #status { margin-bottom: 16px; padding: 14px 16px; background: #fff; border: 1px solid #d5d9e0; border-radius: 8px; }
        .position-search { margin: 0 0 16px; }
        .position-search label { display: block; margin-bottom: 6px; font-size: 14px; font-weight: 700; }
        #positionSearch { width: 100%; padding: 12px 14px; border: 1px solid #bdc5cf; border-radius: 8px; font: inherit; }
        #positionSearch:focus { outline: 3px solid rgba(16,124,65,.2); border-color: #107c41; }
        #positions { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 14px; }
        #positions > .position[hidden] { display: none !important; }
        #noPositionMatches { display: none; padding: 12px 0; color: #687584; }
        .position { display: block; padding: 18px; color: inherit; text-decoration: none; background: #fff; border: 1px solid #d5d9e0; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,.05); }
        a.position { cursor: pointer; transition: transform .16s ease, border-color .16s ease, box-shadow .16s ease, background-color .16s ease; }
        a.position:hover { transform: translateY(-3px); border-color: #107c41; background: #f6fbf7; box-shadow: 0 7px 18px rgba(16,124,65,.16); }
        a.position:focus-visible { outline: 3px solid rgba(16,124,65,.35); outline-offset: 2px; }
        a.position:active { transform: translateY(-1px) scale(.99); background: #eaf5ed; box-shadow: 0 2px 6px rgba(16,124,65,.14); }
        .position h2 { margin: 0 0 8px; color: #107c41; font-size: 17px; }
        .position p { margin: 0; font-size: 14px; }
        .position.unavailable { color: #687584; background: #f8f9fa; }
        .position.unavailable h2 { color: #687584; }
        details.recent-forms { margin: 24px 0; padding: 16px; border: 1px solid #d5d9e0; border-radius: 10px; background: #fff; }
        details.recent-forms summary { cursor: pointer; color: #107c41; font-weight: 700; }
        .recent-form { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 14px 0; border-bottom: 1px solid #eef0f4; }
        .recent-form:last-child { border-bottom: 0; }
        .recent-form p { margin: 4px 0 0; font-size: 13px; }
        .recent-actions { display: flex; flex-wrap: wrap; gap: 8px; }
        .recent-actions a, .recent-actions button, .resume-actions button { min-height: 48px; display: inline-flex; align-items: center; justify-content: center; padding: 10px 16px; border: 1px solid #cbd4ce; border-radius: 7px; background: #fff; color: #21412e; font: inherit; font-weight: 700; text-decoration: none; cursor: pointer; }
        .recent-actions a.primary, .resume-actions button.primary { background: #107c41; color: #fff; border-color: #107c41; }
        .recent-actions button:disabled { opacity: .5; cursor: not-allowed; }
        #recentFormsMessage { color: #65716a; }
        .resume-backdrop { position: fixed; inset: 0; z-index: 1000; display: grid; place-items: center; padding: 16px; background: rgba(0,0,0,.48); }
        .resume-backdrop[hidden] { display: none !important; }
        .resume-dialog { width: min(100%, 440px); padding: 24px; border-radius: 12px; background: #fff; box-shadow: 0 16px 48px rgba(0,0,0,.25); }
        .resume-dialog h2 { margin: 0 0 12px; font-size: 22px; }
        .resume-dialog p { margin: 0; line-height: 1.5; }
        .resume-actions { display: flex; flex-direction: column; gap: 10px; margin-top: 20px; }
        @media (max-width: 520px) { .recent-form { align-items: stretch; flex-direction: column; } .recent-actions > * { flex: 1; } .resume-backdrop { padding: 0; place-items: end stretch; } .resume-dialog { width: 100%; padding: 24px 20px max(24px, env(safe-area-inset-bottom)); border-radius: 16px 16px 0 0; } }
        @media (max-width: 520px) { header { align-items: flex-start; flex-direction: column; } }
    </style>
</head>
<body>
<main>
    <header>
        <div>
            <h1>Choose a <?php echo $accountType === 'field' ? 'Field Office' : 'Regional Office'; ?> Position (SG <?php echo $salaryGrade; ?>)</h1>
            <p>Only positions and forms assigned to your account type are available.</p>
        </div>
        <div class="user-bar">
            Signed in as <strong><?php echo htmlspecialchars((string)($_SESSION['username'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong>
            · <a href="logout.php">Log out</a>
        </div>
    </header>
    <div id="status" role="status" aria-live="polite">
        <?php if (!$allPositions): ?>
            No <?php echo $accountType === 'field' ? 'Field Office' : 'Regional Office'; ?> positions exist at your saved salary grade (SG <?php echo $salaryGrade; ?>). Ask an administrator to update the position mapping or your account profile.
        <?php elseif (!$positions): ?>
            No matching forms are shared with raters yet. Ask an administrator to generate the position sheets and make them available to raters.
        <?php else: ?>
            Select a position to open its shared scoring form.
        <?php endif; ?>
    </div>
    <?php if ($positions): ?>
        <div class="position-search">
            <label for="positionSearch">Search positions</label>
            <input id="positionSearch" type="search" placeholder="Try ATTY 5, Atty V, or Attorney V" autocomplete="off">
        </div>
    <?php endif; ?>
    <details class="recent-forms">
        <summary>My recent forms</summary>
        <div id="recentForms" aria-live="polite">
            <p id="recentFormsMessage">Loading recent forms…</p>
        </div>
    </details>
    <section id="positions" aria-label="<?php echo $accountType === 'field' ? 'Field Office' : 'Regional Office'; ?> positions">
        <?php foreach ($positions as $position): ?>
            <?php
            $positionTitle = (string)$position['title'];
            $displayTitle = (string)($position['display_title'] ?? $positionTitle);
            $salaryGrade = (int)$position['salary_grade'];
            $templateId = (int)($position['template_id'] ?? 0);
            $positionParams = [
                'template_id' => $templateId,
                'salary_grade' => $salaryGrade,
                'position_title' => $positionTitle,
            ];
            if ($accountType === 'field') $positionParams['source_sheet'] = (string)$position['sheet_name'];
            $positionUrl = 'index.php?' . http_build_query($positionParams);
            ?>
                <a class="position" href="<?php echo htmlspecialchars($positionUrl, ENT_QUOTES, 'UTF-8'); ?>">
                <h2><?php echo htmlspecialchars($displayTitle, ENT_QUOTES, 'UTF-8'); ?></h2>
                <p>Salary Grade <?php echo $salaryGrade; ?><?php if (!empty($position['sheet_name'])): ?> · <?php echo htmlspecialchars((string)$position['sheet_name'], ENT_QUOTES, 'UTF-8'); ?><?php endif; ?> · Scoring form ready</p>
                </a>
        <?php endforeach; ?>
    </section>
    <?php if ($positions): ?>
        <p id="noPositionMatches" role="status" aria-live="polite">No positions match your search.</p>
    <?php endif; ?>
</main>
<div id="resumeDraftModal" class="resume-backdrop" hidden>
    <section class="resume-dialog" role="dialog" aria-modal="true" aria-labelledby="resumeDraftTitle" aria-describedby="resumeDraftDescription">
        <h2 id="resumeDraftTitle">Continue your unfinished form?</h2>
        <p id="resumeDraftDescription"></p>
        <div class="resume-actions">
            <button type="button" id="continueDraftBtn" class="primary">Continue editing</button>
            <button type="button" id="discardDraftBtn">Discard</button>
            <button type="button" id="notNowDraftBtn">Not now</button>
        </div>
    </section>
</div>
<script>
    (function () {
        const csrfToken = <?php echo json_encode(csrf_token()); ?>;
        const container = document.getElementById('recentForms');
        const message = document.getElementById('recentFormsMessage');
        const modal = document.getElementById('resumeDraftModal');
        let currentDraft = null;

        async function draftRequest(action, extra = {}) {
            const response = await fetch('draft_api.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ action, csrf_token: csrfToken, ...extra })
            });
            const data = await response.json();
            if (!response.ok || data.status !== 'success') {
                throw new Error(data.message || 'Could not load your forms.');
            }
            return data;
        }

        function escapeDate(value) {
            const date = new Date(String(value).replace(' ', 'T'));
            return Number.isNaN(date.getTime()) ? value : date.toLocaleString();
        }

        function addSavedForm(form) {
            const row = document.createElement('article');
            row.className = 'recent-form';
            const details = document.createElement('div');
            const title = document.createElement('strong');
            title.textContent = form.candidate_name || 'Unnamed candidate';
            const meta = document.createElement('p');
            meta.textContent = [form.position_title || 'No position selected', 'Saved ' + escapeDate(form.updated_at)].join(' · ');
            details.append(title, meta);

            const actions = document.createElement('div');
            actions.className = 'recent-actions';
            if (form.template_available) {
                const params = new URLSearchParams({ saved_id: String(form.id) });
                const open = document.createElement('a');
                open.className = 'primary';
                open.href = 'index.php?' + params.toString();
                open.textContent = 'Open';
                const pdf = document.createElement('a');
                params.set('export_pdf', '1');
                pdf.href = 'index.php?' + params.toString();
                pdf.target = '_blank';
                pdf.rel = 'noopener';
                pdf.textContent = 'Save as PDF';
                actions.append(open, pdf);
            } else {
                const unavailable = document.createElement('span');
                unavailable.textContent = 'This form is no longer available';
                actions.append(unavailable);
            }
            row.append(details, actions);
            container.appendChild(row);
        }

        async function loadRecentForms() {
            try {
                const data = await draftRequest('list_saved');
                container.replaceChildren();
                if (!data.forms.length) {
                    const empty = document.createElement('p');
                    empty.id = 'recentFormsMessage';
                    empty.textContent = 'No saved forms yet.';
                    container.appendChild(empty);
                    return;
                }
                data.forms.forEach(addSavedForm);
            } catch (error) {
                message.textContent = 'Could not load recent forms. Refresh the page to try again.';
            }
        }

        function showDraft(draft) {
            currentDraft = draft;
            const description = document.getElementById('resumeDraftDescription');
            const edited = escapeDate(draft.updated_at);
            description.textContent = [
                draft.candidate_name || 'Unnamed candidate',
                draft.position_title || 'Position not selected',
                'Last edited ' + edited
            ].join(' · ');
            modal.hidden = false;
            document.getElementById('continueDraftBtn').focus();
        }

        document.getElementById('continueDraftBtn').addEventListener('click', () => {
            window.location.href = 'index.php?resume_draft=1';
        });
        document.getElementById('notNowDraftBtn').addEventListener('click', () => { modal.hidden = true; });
        document.getElementById('discardDraftBtn').addEventListener('click', async () => {
            if (!currentDraft || !window.confirm('Discard this draft? This cannot be undone.')) return;
            try {
                await draftRequest('discard_draft', { id: currentDraft.id });
                currentDraft = null;
                modal.hidden = true;
            } catch (error) {
                document.getElementById('resumeDraftDescription').textContent = 'Could not discard the draft. Please try again.';
            }
        });

        Promise.all([
            loadRecentForms(),
            draftRequest('get_draft').then(data => {
                if (data.draft && data.prompt_allowed) showDraft(data.draft);
            }).catch(() => {
                document.getElementById('status').textContent += ' Could not check for an unfinished form. Refresh the page to try again.';
            })
        ]);
    })();
</script>
<?php if ($positions): ?>
<script>
    (function () {
        const input = document.getElementById('positionSearch');
        const cards = Array.from(document.querySelectorAll('#positions .position'));
        const noMatches = document.getElementById('noPositionMatches');

        function normalizePositionSearch(value) {
            const roman = {
                iv: '4', iii: '3', ii: '2', i: '1', v: '5',
                vi: '6', vii: '7', viii: '8', ix: '9', x: '10', xi: '11'
            };
            return String(value || '').toLowerCase().trim()
                .replace(/&/g, ' and ')
                .replace(/[^a-z0-9\s]/g, ' ')
                .replace(/\s+/g, ' ')
                .trim()
                .replace(/\b[a-z]+\b/g, word => roman[word] || word)
                .replace(/\battorney\b|\baty\b/g, 'atty')
                .replace(/\bplanning officer\b/g, 'plo')
                .replace(/\bproject evaluation officer\b/g, 'peo')
                .replace(/\bdevelopment management officer\b/g, 'dmo')
                .replace(/\bsupervising administrative officer\b|\bsadof\b/g, 'sao')
                .replace(/\bsupervising ecosystems management specialist\b/g, 'svems')
                .replace(/\bsenior ecosystems management specialist\b/g, 'srems')
                .replace(/\bsenior communications development officer\b/g, 'srcdo')
                .replace(/\bchief ecosystems management specialist\b/g, 'cecms')
                .replace(/\bchief administrative officer\b|\bcadof\b|\bcaof\b/g, 'cao')
                .replace(/\badministrative officer\b|\badof\b|\bao\b/g, 'adof')
                .replace(/\badministrative assistant\b/g, 'adas')
                .replace(/\badministrative aide\b/g, 'ada')
                .replace(/\bforest technician\b/g, 'ft')
                .replace(/\bforester\b|\bforst\b/g, 'for')
                .replace(/\bland management officer\b|\blamo\b/g, 'lmo')
                .replace(/\bcommunity environment and natural resources officer\b/g, 'cenro')
                .replace(/\bprovincial environment and natural resources officer\b/g, 'penro')
                .replace(/\binformation systems analyst\b|\binfosa\b/g, 'isa')
                .replace(/\bcartographer\b|\bcarto\b|\bcgr\b/g, 'cg')
                .replace(/\bgeodetic engineer\b/g, 'eng')
                .replace(/\bengineer\b/g, 'eng')
                .replace(/\becosystems management specialist\b/g, 'ems')
                .replace(/\s+/g, '')
                .replace(/[^a-z0-9]/g, '');
        }

        function filterPositions() {
            const query = normalizePositionSearch(input.value);
            const rawQuery = input.value.toLowerCase().replace(/[^a-z0-9]/g, '');
            let visibleCount = 0;
            cards.forEach(card => {
                const title = card.querySelector('h2').textContent;
                const rawTitle = title.toLowerCase().replace(/[^a-z0-9]/g, '');
                const normalizedTitle = normalizePositionSearch(title);
                const matches = !query || normalizedTitle.includes(query) || rawTitle.includes(rawQuery);
                card.hidden = !matches;
                if (matches) visibleCount++;
            });
            noMatches.style.display = visibleCount === 0 ? 'block' : 'none';
        }

        input.addEventListener('input', filterPositions);
    })();
</script>
<?php endif; ?>
</body>
</html>
