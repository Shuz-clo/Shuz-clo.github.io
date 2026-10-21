<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/AuthSchema.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/Raters.php';

ensure_auth_schema($conn);
require_role(['user']);
ensure_schema($conn);

function profile_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$userId = (int)current_user_id();
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
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 28px 16px; background: #f4f6f9; color: #202124; font-family: Arial, Helvetica, sans-serif; }
        main { max-width: 960px; margin: 0 auto; }
        header { display: flex; justify-content: space-between; align-items: center; gap: 16px; margin-bottom: 24px; }
        h1 { margin: 0 0 8px; font-size: 26px; }
        p { color: #555; line-height: 1.5; }
        header p { margin: 0; }
        header a { color: #107c41; font-weight: 700; }
        .card { background: #fff; border: 1px solid #dce1e7; border-radius: 10px; padding: 20px; margin-bottom: 18px; }
        .card h2 { margin: 0 0 8px; font-size: 19px; }
        .meta { margin: 0 0 18px; font-size: 14px; }
        .empty { padding: 16px; background: #fff; border: 1px solid #dce1e7; border-radius: 8px; }
        .table-scroll { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { padding: 10px 8px; text-align: left; border-bottom: 1px solid #e9edf1; }
        th { font-size: 12px; color: #555; text-transform: uppercase; }
        @media (max-width: 600px) { header { align-items: flex-start; flex-direction: column; } }
    </style>
</head>
<body>
<main>
    <header>
        <div>
            <h1>My Profile</h1>
            <p>Signed in as <strong><?php echo profile_h($_SESSION['username'] ?? ''); ?></strong></p>
        </div>
        <a href="logout.php">Sign out</a>
    </header>

    <?php if (!$candidates): ?>
        <div class="empty">No interview record is linked to your account or matches your username yet. For automatic matching, the candidate name on the scoring sheet must match your username; otherwise, ask an administrator to link the record.</div>
    <?php endif; ?>

    <?php foreach ($candidates as $candidate): ?>
        <?php $candidateId = (int)$candidate['id']; ?>
        <section class="card">
            <h2><?php echo profile_h($candidate['position_title'] ?: 'Interview record'); ?></h2>
            <p class="meta">
                <?php echo profile_h($candidate['candidate_name'] ?: ''); ?>
                · <?php echo profile_h($candidate['office_name'] ?: ''); ?>
                · <?php echo profile_h($candidate['interview_date'] ?: ''); ?>
            </p>
            <?php if (!empty($candidate['rater_name'])): ?>
                <p>Rater name recorded on sheet: <strong><?php echo profile_h($candidate['rater_name']); ?></strong></p>
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
