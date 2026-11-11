<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/AuthSchema.php';
require_once __DIR__ . '/auth.php';

require_role(['user']);

function profile_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$userId = (int)current_user_id();
$profileStmt = $conn->prepare('SELECT salary_grade FROM users WHERE id = ? LIMIT 1');
$profileStmt->bind_param('i', $userId);
$profileStmt->execute();
$profileSalaryGrade = $profileStmt->get_result()->fetch_column();
$profileStmt->close();

$profileUsername = (string)($_SESSION['username'] ?? '');
$stmt = $conn->prepare('SELECT id, candidate_name, position_title, office_name, interview_date, rater_name
    FROM candidates
    WHERE user_id = ?
       OR (user_id IS NULL AND LOWER(TRIM(candidate_name)) = LOWER(TRIM(?)))
    ORDER BY id DESC');
$stmt->bind_param('is', $userId, $profileUsername);
$stmt->execute();
$candidates = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$scoresByCandidate = [];
$scoreStmt = $conn->prepare('SELECT cs.rater, cs.rated_at, u.username AS account_name
    FROM candidate_scores cs
    LEFT JOIN users u ON u.id = cs.rated_by
    WHERE cs.candidate_id = ?
    ORDER BY cs.rated_at DESC, cs.rater ASC');
foreach ($candidates as $candidate) {
    $candidateId = (int)$candidate['id'];
    $scoreStmt->bind_param('i', $candidateId);
    $scoreStmt->execute();
    $scoresByCandidate[$candidateId] = $scoreStmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
$scoreStmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Profile</title>
    <link rel="stylesheet" href="ui-enhancements.css?v=<?php echo filemtime(__DIR__ . '/ui-enhancements.css'); ?>">
    <script src="ui-enhancements.js?v=<?php echo filemtime(__DIR__ . '/ui-enhancements.js'); ?>" defer></script>
    <style>
        * { box-sizing: border-box; }
        :root { --profile-green: #107c41; --profile-ink: #1c2420; --profile-muted: #68746d; --profile-line: #e0e7e2; }
        body { margin: 0; padding: 40px 20px; background: radial-gradient(ellipse at top left, #e8f2eb 0, transparent 38%), #f3f6f4; color: var(--profile-ink); font-family: "Public Sans", "Segoe UI", Arial, sans-serif; }
        main { width: min(100%, 1040px); margin: 0 auto; }
        header.profile-header { display: flex; justify-content: space-between; align-items: center; gap: 20px; margin-bottom: 28px; }
        .profile-kicker { margin: 0 0 6px; color: var(--profile-green); font-size: 11px; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; }
        h1 { margin: 0 0 7px; font-size: clamp(26px, 4vw, 34px); letter-spacing: -.035em; }
        p { color: #56625b; line-height: 1.55; }
        header p { margin: 0; }
        .header-actions { display: flex; align-items: center; gap: 10px; }
        .header-actions a { display: inline-flex; min-height: 42px; align-items: center; justify-content: center; padding: 0 16px; border: 1px solid var(--profile-line); border-radius: 9px; background: #fff; color: var(--profile-ink); font-size: 13px; font-weight: 650; text-decoration: none; }
        .header-actions a:hover { border-color: #b9d1c1; background: #f8fbf8; color: var(--profile-green); }
        .card { margin-bottom: 16px; padding: 24px; border: 1px solid rgba(32, 54, 40, .08); border-radius: 16px; background: rgba(255,255,255,.96); box-shadow: 0 8px 26px rgba(27, 55, 39, .055); }
        .card h2 { margin: 0 0 8px; font-size: 19px; letter-spacing: -.015em; }
        .salary-card { display: grid; grid-template-columns: minmax(0, 1fr) auto; align-items: center; gap: 24px; border-top: 3px solid var(--profile-green); }
        .card-intro { max-width: 540px; margin: 0; color: var(--profile-muted); font-size: 13px; }
        .salary-grade-display { display: grid; justify-items: start; gap: 5px; }
        .salary-grade-display .label { color: #536057; font-size: 12px; font-weight: 700; }
        .salary-grade-value { display: inline-flex; min-width: 76px; min-height: 42px; align-items: center; justify-content: center; padding: 0 14px; border: 1px solid #c9dfd0; border-radius: 10px; background: #f0f7f2; color: var(--profile-green); font-size: 20px; font-weight: 750; }
        .salary-grade-note { margin: 0; color: var(--profile-muted); font-size: 12px; }
        .meta { display: flex; flex-wrap: wrap; gap: 5px 10px; margin: 0 0 20px; color: var(--profile-muted); font-size: 13px; }
        .meta span + span::before { content: "·"; margin-right: 10px; color: #a0aaa3; }
        .interview-card h3 { margin: 22px 0 10px; font-size: 14px; }
        .record-rater { margin: 0; color: var(--profile-muted); font-size: 13px; }
        .empty { padding: 20px; border: 1px solid #dbe5de; border-radius: 12px; background: #fff; color: #536057; line-height: 1.55; }
        .notice { margin-bottom: 16px; padding: 13px 15px; border: 1px solid transparent; border-radius: 10px; font-size: 13px; }
        .notice.error { border-color: #f2c8c4; background: #fff3f1; color: #7a1f16; }
        .notice.success { border-color: #c9e5d0; background: #eff8f1; color: #14532d; }
        .table-scroll { overflow-x: auto; border: 1px solid var(--profile-line); border-radius: 10px; -webkit-overflow-scrolling: touch; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { padding: 12px 14px; text-align: left; border-bottom: 1px solid #edf1ee; }
        th { background: #f7f9f7; color: #647168; font-size: 10px; font-weight: 700; letter-spacing: .07em; text-transform: uppercase; }
        tbody tr:last-child td { border-bottom: 0; }
        tbody tr:hover { background: #f9fbf9; }
        @media (max-width: 680px) {
            body { padding: 24px 12px; }
            header.profile-header { align-items: flex-start; flex-direction: column; margin-bottom: 20px; }
            .header-actions { width: 100%; }
            .header-actions a { flex: 1; }
            .card { padding: 18px 15px; border-radius: 13px; }
            .salary-card { grid-template-columns: minmax(0, 1fr); gap: 18px; }
            th, td { padding: 10px; }
            .table-scroll table { min-width: 520px; }
        }
    </style>
</head>
<body>
<main>
    <header class="profile-header">
        <div>
            <p class="profile-kicker">Account overview</p>
            <h1>My Profile</h1>
            <p>Signed in as <strong><?php echo profile_h($_SESSION['username'] ?? ''); ?></strong></p>
        </div>
        <nav class="header-actions" aria-label="Account actions">
            <a href="logout.php">Sign out</a>
        </nav>
    </header>

    <section class="card salary-card">
        <div>
            <h2>Salary Grade</h2>
            <p class="card-intro">Your grade determines which competency forms are available to your account.</p>
        </div>
        <div class="salary-grade-display" aria-label="Assigned salary grade">
            <span class="label">Assigned grade</span>
            <strong class="salary-grade-value"><?php echo $profileSalaryGrade !== null ? profile_h($profileSalaryGrade) : 'Not set'; ?></strong>
            <p class="salary-grade-note">Managed by your administrator</p>
        </div>
    </section>

    <?php if (!$candidates): ?>
        <div class="empty">No interview record is linked to your account or matches your username yet. For automatic matching, the candidate name on the scoring sheet must match your username; otherwise, ask an administrator to link the record.</div>
    <?php endif; ?>

    <?php foreach ($candidates as $candidate): ?>
        <?php $candidateId = (int)$candidate['id']; ?>
        <section class="card interview-card">
            <h2><?php echo profile_h($candidate['position_title'] ?: 'Interview record'); ?></h2>
            <p class="meta">
                <span><?php echo profile_h($candidate['candidate_name'] ?: ''); ?></span>
                <span><?php echo profile_h($candidate['office_name'] ?: ''); ?></span>
                <span><?php echo profile_h($candidate['interview_date'] ?: ''); ?></span>
            </p>
            <?php if (!empty($candidate['rater_name'])): ?>
                <p class="record-rater">Rater name recorded on sheet: <strong><?php echo profile_h($candidate['rater_name']); ?></strong></p>
            <?php endif; ?>
            <h3>Who rated this record</h3>
            <div class="table-scroll">
                <table>
                    <thead>
                        <tr><th>Raters role</th><th>Rater Name</th><th>Rated on</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($scoresByCandidate[$candidateId] as $score): ?>
                        <tr>
                            <td><?php echo profile_h($score['rater']); ?></td>
                            <td><?php echo profile_h($score['account_name'] ?: 'Not recorded'); ?></td>
                            <td><?php echo profile_h($score['rated_at'] ?: 'Not recorded'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($scoresByCandidate[$candidateId])): ?>
                        <tr><td colspan="3">No ratings recorded yet.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endforeach; ?>
</main>
</body>
</html>
