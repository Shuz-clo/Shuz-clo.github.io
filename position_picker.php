<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/AuthSchema.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/AccessGroups.php';
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

$region = (string)$profile['region'];
$fieldName = (string)$profile['field_name'];
$salaryGrade = (int)$profile['salary_grade'];
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
            ORDER BY t.updated_at DESC, t.id DESC
            LIMIT 1
        ) AS template_id
    FROM positions p

    WHERE p.office_scope = ? AND p.salary_grade = ? 
    
    ORDER BY p.salary_grade DESC, p.title ASC");
$stmt->bind_param('sssi', $region, $fieldName, $scope, $salaryGrade);
$stmt->execute();
$positions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Choose a Position</title>
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
        @media (max-width: 520px) { header { align-items: flex-start; flex-direction: column; } }
    </style>
</head>
<body>
<main>
    <header>
        <div>
            <h1>Choose a Regional Office Position (SG <?php echo $salaryGrade; ?>)</h1>
            <p>Only positions at your account’s salary grade are available. The matching scoring form will be filled in automatically.</p>
        </div>
        <div class="user-bar">
            Signed in as <strong><?php echo htmlspecialchars((string)($_SESSION['username'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong>
            · <a href="logout.php">Log out</a>
        </div>
    </header>
    <div id="status" role="status" aria-live="polite">
        <?php if (!$positions): ?>
            No Regional Office positions exist at your saved salary grade (SG <?php echo $salaryGrade; ?>). Ask an administrator to seed or import positions for this grade, or update your account to a grade that has positions.
        <?php elseif (!array_filter($positions, static fn(array $position): bool => !empty($position['template_id']))): ?>
            No matching forms are shared with raters yet. Ask an administrator to generate the position sheets and make them available to raters.
        <?php else: ?>
            Positions without a shared form are shown as unavailable. Ask an administrator to share their generated sheet.
        <?php endif; ?>
    </div>
    <?php if ($positions): ?>
        <div class="position-search">
            <label for="positionSearch">Search positions</label>
            <input id="positionSearch" type="search" placeholder="Try ATTY 5, Atty V, or Attorney V" autocomplete="off">
        </div>
    <?php endif; ?>
    <section id="positions" aria-label="Regional Office positions">
        <?php foreach ($positions as $position): ?>
            <?php
            $positionTitle = (string)$position['title'];
            $salaryGrade = (int)$position['salary_grade'];
            $templateId = (int)($position['template_id'] ?? 0);
            $positionUrl = 'index.php?' . http_build_query([
                'template_id' => $templateId,
                'salary_grade' => $salaryGrade,
                'position_title' => $positionTitle,
            ]);
            ?>
            <?php if ($templateId > 0): ?>
                <a class="position" href="<?php echo htmlspecialchars($positionUrl, ENT_QUOTES, 'UTF-8'); ?>">
            <?php else: ?>
                <div class="position unavailable" aria-disabled="true">
            <?php endif; ?>
                <h2><?php echo htmlspecialchars($positionTitle, ENT_QUOTES, 'UTF-8'); ?></h2>
                <p>Salary Grade <?php echo $salaryGrade; ?><?php echo $templateId > 0 ? ' · Scoring form ready' : ' · No shared form'; ?></p>
            <?php if ($templateId > 0): ?>
                </a>
            <?php else: ?>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </section>
    <?php if ($positions): ?>
        <p id="noPositionMatches" role="status" aria-live="polite">No positions match your search.</p>
    <?php endif; ?>
</main>
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
