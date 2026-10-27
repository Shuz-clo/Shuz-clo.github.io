<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/AuthSchema.php';
require_once __DIR__ . '/auth.php';

require_admin();

const AUDIT_VIEWER_ACTIONS = [
    'submit', 'edit', 'delete', 'role_change', 'password_reset',
    'login_failure', 'login_success', 'logout',
];
const AUDIT_VIEWER_PAGE_SIZE = 50;

function audit_viewer_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function audit_viewer_date_is_valid(string $date): bool {
    $parsed = DateTime::createFromFormat('!Y-m-d', $date);
    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}

function audit_viewer_bind(mysqli_stmt $stmt, string $types, array &$params): void {
    if ($types === '') return;
    $arguments = [$types];
    foreach ($params as &$param) {
        $arguments[] = &$param;
    }
    call_user_func_array([$stmt, 'bind_param'], $arguments);
}

function audit_viewer_csv_cell($value): string {
    $value = (string)($value ?? '');
    return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
}

$filters = [
    'username' => trim((string)($_GET['username'] ?? '')),
    'action' => trim((string)($_GET['action'] ?? '')),
    'candidate_id' => trim((string)($_GET['candidate_id'] ?? '')),
    'date_from' => trim((string)($_GET['date_from'] ?? '')),
    'date_to' => trim((string)($_GET['date_to'] ?? '')),
];
$errors = [];
if (mb_strlen($filters['username']) > 100) {
    $errors[] = 'Username filter must be 100 characters or fewer.';
    $filters['username'] = mb_substr($filters['username'], 0, 100);
}
if ($filters['action'] !== '' && !in_array($filters['action'], AUDIT_VIEWER_ACTIONS, true)) {
    $errors[] = 'Choose a valid audit action.';
    $filters['action'] = '';
}
if ($filters['candidate_id'] !== '' && !ctype_digit($filters['candidate_id'])) {
    $errors[] = 'Candidate ID must be a positive whole number.';
    $filters['candidate_id'] = '';
} elseif ($filters['candidate_id'] !== '' && (int)$filters['candidate_id'] < 1) {
    $errors[] = 'Candidate ID must be a positive whole number.';
    $filters['candidate_id'] = '';
}
foreach (['date_from', 'date_to'] as $dateKey) {
    if ($filters[$dateKey] !== '' && !audit_viewer_date_is_valid($filters[$dateKey])) {
        $errors[] = 'Enter dates using YYYY-MM-DD.';
        $filters[$dateKey] = '';
    }
}
if ($filters['date_from'] !== '' && $filters['date_to'] !== ''
    && $filters['date_from'] > $filters['date_to']) {
    $errors[] = 'The start date must not be later than the end date.';
}

$where = [];
$filterTypes = '';
$filterParams = [];
if ($filters['username'] !== '') {
    $where[] = 'username LIKE ?';
    $filterTypes .= 's';
    $filterParams[] = '%' . $filters['username'] . '%';
}
if ($filters['action'] !== '') {
    $where[] = '`action` = ?';
    $filterTypes .= 's';
    $filterParams[] = $filters['action'];
}
if ($filters['candidate_id'] !== '') {
    $where[] = 'candidate_id = ?';
    $filterTypes .= 'i';
    $filterParams[] = (int)$filters['candidate_id'];
}
if ($filters['date_from'] !== '') {
    $where[] = 'created_at >= ?';
    $filterTypes .= 's';
    $filterParams[] = $filters['date_from'] . ' 00:00:00';
}
if ($filters['date_to'] !== '') {
    $where[] = 'created_at < DATE_ADD(?, INTERVAL 1 DAY)';
    $filterTypes .= 's';
    $filterParams[] = $filters['date_to'] . ' 00:00:00';
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$export = ($_GET['export'] ?? '') === 'csv';

if (!$errors && $export) {
    $stmt = $conn->prepare('SELECT id, candidate_id, user_id, username, `action`, old_value, new_value, ip, user_agent, created_at
        FROM score_audit' . $whereSql . ' ORDER BY created_at DESC, id DESC');
    audit_viewer_bind($stmt, $filterTypes, $filterParams);
    $stmt->execute();
    $result = $stmt->get_result();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="score-audit.csv"');
    $output = fopen('php://output', 'w');
    if ($output === false) {
        throw new RuntimeException('Unable to open CSV output stream.');
    }
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, ['ID', 'Candidate ID', 'User ID', 'Username', 'Action', 'Old Value', 'New Value', 'IP', 'User Agent', 'Created At'], ',', '"', '');
    while ($row = $result->fetch_assoc()) {
        fputcsv($output, array_map('audit_viewer_csv_cell', array_values($row)), ',', '"', '');
    }
    fclose($output);
    $stmt->close();
    exit;
}

$page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT);
$page = $page === false || $page < 1 ? 1 : $page;
$totalRows = 0;
$rows = [];
$totalPages = 1;
if (!$errors) {
    $countStmt = $conn->prepare('SELECT COUNT(*) AS total FROM score_audit' . $whereSql);
    audit_viewer_bind($countStmt, $filterTypes, $filterParams);
    $countStmt->execute();
    $totalRows = (int)$countStmt->get_result()->fetch_assoc()['total'];
    $countStmt->close();
    $totalPages = max(1, (int)ceil($totalRows / AUDIT_VIEWER_PAGE_SIZE));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * AUDIT_VIEWER_PAGE_SIZE;
    $queryParams = $filterParams;
    $queryParams[] = AUDIT_VIEWER_PAGE_SIZE;
    $queryParams[] = $offset;
    $stmt = $conn->prepare('SELECT id, candidate_id, user_id, username, `action`, old_value, new_value, ip, user_agent, created_at
        FROM score_audit' . $whereSql . ' ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?');
    audit_viewer_bind($stmt, $filterTypes . 'ii', $queryParams);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$queryFilters = array_filter($filters, static fn(string $value): bool => $value !== '');
$csvUrl = '?' . http_build_query($queryFilters + ['export' => 'csv']);
$pageUrl = static function (int $targetPage) use ($queryFilters): string {
    return '?' . http_build_query($queryFilters + ['page' => $targetPage]);
};

function audit_viewer_value(?string $value): string {
    if ($value === null || $value === '') return '—';
    $length = mb_strlen($value);
    $preview = $length > 180 ? mb_substr($value, 0, 180) . '…' : $value;
    $html = '<span>' . audit_viewer_h($preview) . '</span>';
    if ($length > 180) {
        $html .= '<details><summary>Show more</summary><div>' . nl2br(audit_viewer_h($value)) . '</div></details>';
    }
    return $html;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Score Audit</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 28px 16px; background: #f4f6f9; color: #202124; font-family: Arial, Helvetica, sans-serif; }
        main { max-width: 1400px; margin: 0 auto; }
        header, .actions { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; }
        header { margin-bottom: 18px; }
        h1 { margin: 0; }
        a { color: #107c41; font-weight: 700; }
        .card { padding: 18px; margin-bottom: 16px; background: #fff; border: 1px solid #dce1e7; border-radius: 9px; }
        .filters { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px; align-items: end; }
        label { display: block; margin-bottom: 5px; font-weight: 700; }
        input, select, button { width: 100%; padding: 9px; font: inherit; }
        button { border: 0; border-radius: 5px; background: #107c41; color: #fff; cursor: pointer; }
        .notice { padding: 10px 12px; margin-bottom: 12px; border-radius: 5px; background: #fdecea; color: #7a1f16; }
        .table-scroll { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; background: #fff; }
        th, td { padding: 9px; border-bottom: 1px solid #e4e8ed; text-align: left; vertical-align: top; }
        th { background: #f1f4f6; }
        td.details { min-width: 220px; max-width: 380px; overflow-wrap: anywhere; }
        details { margin-top: 5px; }
        summary { color: #107c41; cursor: pointer; }
        .pagination { display: flex; justify-content: center; gap: 14px; align-items: center; margin-top: 16px; }
        @media (max-width: 600px) { body { padding: 16px 8px; } }
    </style>
</head>
<body>
<main>
    <header>
        <h1>Score Audit</h1>
        <nav><a href="results_2.php">Results</a> · <a href="manage_users.php">Manage Users</a></nav>
    </header>
    <section class="card">
        <form method="get" class="filters">
            <div><label for="username">Username</label><input id="username" name="username" maxlength="100" value="<?php echo audit_viewer_h($filters['username']); ?>"></div>
            <div><label for="action">Action</label><select id="action" name="action">
                <option value="">All actions</option>
                <?php foreach (AUDIT_VIEWER_ACTIONS as $action): ?>
                    <option value="<?php echo audit_viewer_h($action); ?>" <?php echo $filters['action'] === $action ? 'selected' : ''; ?>><?php echo audit_viewer_h($action); ?></option>
                <?php endforeach; ?>
            </select></div>
            <div><label for="candidate_id">Candidate ID</label><input id="candidate_id" name="candidate_id" inputmode="numeric" value="<?php echo audit_viewer_h($filters['candidate_id']); ?>"></div>
            <div><label for="date_from">From</label><input type="date" id="date_from" name="date_from" value="<?php echo audit_viewer_h($filters['date_from']); ?>"></div>
            <div><label for="date_to">To</label><input type="date" id="date_to" name="date_to" value="<?php echo audit_viewer_h($filters['date_to']); ?>"></div>
            <div><button type="submit">Filter</button></div>
        </form>
    </section>
    <?php foreach ($errors as $error): ?><div class="notice"><?php echo audit_viewer_h($error); ?></div><?php endforeach; ?>
    <?php if (!$errors): ?>
        <section class="card">
            <div class="actions"><span><?php echo $totalRows; ?> matching audit row(s)</span><a href="<?php echo audit_viewer_h($csvUrl); ?>">Export CSV</a></div>
            <div class="table-scroll">
                <table>
                    <thead><tr><th>ID</th><th>Created</th><th>Candidate</th><th>User</th><th>Action</th><th>Old value</th><th>New value</th><th>IP</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?php echo (int)$row['id']; ?></td>
                            <td><?php echo audit_viewer_h($row['created_at']); ?></td>
                            <td><?php echo (int)$row['candidate_id']; ?></td>
                            <td><?php echo audit_viewer_h($row['username'] ?? ''); ?><br><small><?php echo $row['user_id'] === null ? '' : (int)$row['user_id']; ?></small></td>
                            <td><?php echo audit_viewer_h($row['action']); ?></td>
                            <td class="details"><?php echo audit_viewer_value($row['old_value']); ?></td>
                            <td class="details"><?php echo audit_viewer_value($row['new_value']); ?></td>
                            <td><?php echo audit_viewer_h($row['ip'] ?? ''); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows): ?><tr><td colspan="8">No audit rows match these filters.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($totalPages > 1): ?>
                <nav class="pagination" aria-label="Audit pagination">
                    <?php if ($page > 1): ?><a href="<?php echo audit_viewer_h($pageUrl($page - 1)); ?>">Previous</a><?php endif; ?>
                    <span>Page <?php echo $page; ?> of <?php echo $totalPages; ?></span>
                    <?php if ($page < $totalPages): ?><a href="<?php echo audit_viewer_h($pageUrl($page + 1)); ?>">Next</a><?php endif; ?>
                </nav>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</main>
</body>
</html>