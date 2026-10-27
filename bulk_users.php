<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/AuthSchema.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/AccessGroups.php';
require_once __DIR__ . '/Audit.php';

require_admin();

const BULK_USER_ROLES = ['rater', 'user'];
const BULK_USER_CSV_COLUMNS = ['username', 'role', 'salary_grade', 'region', 'field_name'];
const BULK_USER_MAX_ROWS = 500;
const BULK_USER_MAX_CSV_BYTES = 1048576;

function bulk_users_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function bulk_users_read_csv(string $csv): array {
    if (strlen($csv) > BULK_USER_MAX_CSV_BYTES) {
        throw new InvalidArgumentException('CSV must be 1 MB or smaller.');
    }
    $stream = fopen('php://temp', 'r+');
    if ($stream === false) {
        throw new RuntimeException('Unable to read the provided CSV.');
    }
    fwrite($stream, $csv);
    rewind($stream);
    $headers = fgetcsv($stream, null, ',', '"', '');
    if (!is_array($headers) || !$headers) {
        fclose($stream);
        throw new InvalidArgumentException('Provide a CSV with a header row.');
    }
    $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$headers[0]);
    $headers = array_map(static fn($header): string => strtolower(trim((string)$header)), $headers);
    $columnIndexes = [];
    foreach (BULK_USER_CSV_COLUMNS as $column) {
        $index = array_search($column, $headers, true);
        if ($index === false) {
            fclose($stream);
            throw new InvalidArgumentException('CSV headers must include: username, role, salary_grade, region, field_name.');
        }
        $columnIndexes[$column] = $index;
    }

    $rows = [];
    $lineNumber = 1;
    while (($values = fgetcsv($stream, null, ',', '"', '')) !== false) {
        $lineNumber++;
        if ($values === [null] || (count($values) === 1 && trim((string)$values[0]) === '')) {
            continue;
        }
        if (count($rows) >= BULK_USER_MAX_ROWS) {
            fclose($stream);
            throw new InvalidArgumentException('CSV cannot contain more than ' . BULK_USER_MAX_ROWS . ' user rows.');
        }
        $row = ['line' => $lineNumber];
        foreach (BULK_USER_CSV_COLUMNS as $column) {
            $row[$column] = trim((string)($values[$columnIndexes[$column]] ?? ''));
        }
        $rows[] = $row;
    }
    fclose($stream);
    if (!$rows) {
        throw new InvalidArgumentException('The CSV contains no user rows.');
    }
    return $rows;
}

function bulk_users_validate_row(mysqli $conn, array $row, array $salaryGrades): array {
    $errors = [];
    $username = preg_replace('/\s+/', ' ', trim((string)($row['username'] ?? '')));
    $role = strtolower(trim((string)($row['role'] ?? '')));
    $region = trim((string)($row['region'] ?? ''));
    $fieldName = trim((string)($row['field_name'] ?? ''));
    $salaryGrade = trim((string)($row['salary_grade'] ?? ''));

    if (!preg_match('/^[\p{L}0-9 .\-]{3,100}$/u', $username)) {
        $errors[] = 'Username must be 3-100 characters using letters (including ñ), numbers, spaces, dots, and hyphens.';
    }
    if (!in_array($role, BULK_USER_ROLES, true)) {
        $errors[] = 'Role must be rater or user, matching public registration.';
    }
    if ($region !== 'Region VI') {
        $errors[] = 'Registration is currently limited to Region VI.';
    }
    if ($fieldName === '' || $fieldName === 'Other' || !access_field_is_valid($fieldName)) {
        $errors[] = 'Enter a valid field name; use a specific field name instead of Other.';
    }
    if (!access_position_salary_grade_is_valid($conn, $salaryGrade, $salaryGrades)) {
        $errors[] = 'Salary grade must exist in Regional Office positions.';
    }

    if (preg_match('/^[\p{L}0-9 .\-]{3,100}$/u', $username)) {
        if (find_user_by_username($conn, $username) !== null) {
            $errors[] = 'Username already exists.';
        }
    }

    return [
        'line' => (int)($row['line'] ?? 0),
        'username' => $username,
        'role' => $role,
        'salary_grade' => $salaryGrade,
        'region' => $region,
        'field_name' => $fieldName,
        'errors' => array_values(array_unique($errors)),
    ];
}

function bulk_users_mark_duplicates(array $rows): array {
    $indexesByUsername = [];
    foreach ($rows as $index => $row) {
        if (preg_match('/^[\p{L}0-9 .\-]{3,100}$/u', $row['username'])) {
            $key = mb_strtolower($row['username'], 'UTF-8');
            $indexesByUsername[$key][] = $index;
        }
    }
    foreach ($indexesByUsername as $indexes) {
        if (count($indexes) < 2) continue;
        foreach ($indexes as $index) {
            $rows[$index]['errors'][] = 'Duplicate username within this CSV.';
            $rows[$index]['errors'] = array_values(array_unique($rows[$index]['errors']));
        }
    }
    return $rows;
}

function bulk_users_generate_password(): string {
    return bin2hex(random_bytes(12));
}

$salaryGrades = access_position_salary_grades($conn);
$previewRows = [];
$createdResults = [];
$pageError = '';
$pageMessage = '';
$csrfToken = csrf_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        $pageError = 'Your session expired. Reload the page and try again.';
    } elseif (($_POST['action'] ?? '') === 'preview') {
        try {
            $csv = (string)($_POST['csv_text'] ?? '');
            if (isset($_FILES['csv_file']) && (int)($_FILES['csv_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                if ((int)$_FILES['csv_file']['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string)$_FILES['csv_file']['tmp_name'])) {
                    throw new InvalidArgumentException('The CSV upload could not be read.');
                }
                if ((int)$_FILES['csv_file']['size'] > BULK_USER_MAX_CSV_BYTES) {
                    throw new InvalidArgumentException('CSV must be 1 MB or smaller.');
                }
                $uploadedCsv = file_get_contents((string)$_FILES['csv_file']['tmp_name']);
                if ($uploadedCsv === false) {
                    throw new RuntimeException('Unable to read the uploaded CSV.');
                }
                $csv = $uploadedCsv;
            }

            $inputRows = bulk_users_read_csv($csv);
            foreach ($inputRows as $inputRow) {
                $previewRows[] = bulk_users_validate_row($conn, $inputRow, $salaryGrades);
            }
            $previewRows = bulk_users_mark_duplicates($previewRows);
            $batchId = bin2hex(random_bytes(24));
            $_SESSION['bulk_users_preview'] = [
                'id' => $batchId,
                'created_at' => time(),
                'rows' => $inputRows,
            ];
        } catch (InvalidArgumentException $exception) {
            $pageError = $exception->getMessage();
        } catch (Throwable $exception) {
            error_log('bulk_users preview failed: ' . $exception->getMessage());
            $pageError = 'The CSV preview could not be prepared. Check the server error log.';
        }
    } elseif (($_POST['action'] ?? '') === 'confirm') {
        $staged = $_SESSION['bulk_users_preview'] ?? null;
        $batchId = (string)($_POST['batch_id'] ?? '');
        if (!is_array($staged)
            || !isset($staged['id'], $staged['created_at'], $staged['rows'])
            || !is_string($staged['id'])
            || !hash_equals($staged['id'], $batchId)
            || time() - (int)$staged['created_at'] > 900
            || !is_array($staged['rows'])) {
            unset($_SESSION['bulk_users_preview']);
            http_response_code(409);
            $pageError = 'This preview expired or was already used. Upload the CSV again.';
        } else {
            $inputRows = $staged['rows'];
            unset($_SESSION['bulk_users_preview']);
            foreach ($inputRows as $inputRow) {
                $previewRows[] = bulk_users_validate_row($conn, $inputRow, $salaryGrades);
            }
            $previewRows = bulk_users_mark_duplicates($previewRows);
            $validRows = array_values(array_filter($previewRows, static fn(array $row): bool => !$row['errors']));
            if (!$validRows) {
                $pageError = 'No valid rows remain. Review the errors and upload a corrected CSV.';
            } else {
                $transactionStarted = false;
                try {
                    $insertStmt = $conn->prepare('INSERT INTO users (username, password_hash, role, region, field_name, salary_grade)
                        VALUES (?, ?, ?, ?, ?, ?)');
                    $lookupStmt = $conn->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
                    $conn->begin_transaction();
                    $transactionStarted = true;
                    foreach ($validRows as $row) {
                        $username = $row['username'];
                        $lookupStmt->bind_param('s', $username);
                        $lookupStmt->execute();
                        if ($lookupStmt->get_result()->num_rows > 0) {
                            $createdResults[] = ['username' => $username, 'password' => '', 'status' => 'Skipped: username now exists.'];
                            continue;
                        }
                        $password = bulk_users_generate_password();
                        $passwordHash = password_hash($password, PASSWORD_BCRYPT);
                        if ($passwordHash === false) {
                            unset($password);
                            throw new RuntimeException('Password hashing failed.');
                        }
                        $role = $row['role'];
                        $region = $row['region'];
                        $fieldName = $row['field_name'];
                        $salaryGrade = (int)$row['salary_grade'];
                        $insertStmt->bind_param('sssssi', $username, $passwordHash, $role, $region, $fieldName, $salaryGrade);
                        try {
                            $insertStmt->execute();
                        } catch (mysqli_sql_exception $exception) {
                            unset($password, $passwordHash);
                            if ((int)$exception->getCode() === 1062) {
                                $createdResults[] = ['username' => $username, 'password' => '', 'status' => 'Skipped: username now exists.'];
                                continue;
                            }
                            throw $exception;
                        }
                        $newUserId = (int)$conn->insert_id;
                        audit_log($conn, 0, 'role_change', null, [
                            'user_id' => $newUserId,
                            'username' => $username,
                            'role' => $role,
                            'region' => $region,
                            'field_name' => $fieldName,
                            'salary_grade' => $salaryGrade,
                            'via' => 'bulk_users',
                        ]);
                        $createdResults[] = ['username' => $username, 'password' => $password, 'status' => 'Created'];
                        unset($password, $passwordHash);
                    }
                    $conn->commit();
                    $transactionStarted = false;
                    $insertStmt->close();
                    $lookupStmt->close();
                    $pageMessage = 'Valid accounts were processed. Save or download each generated password now; passwords are not retained after this response.';
                } catch (Throwable $exception) {
                    if ($transactionStarted) {
                        try { $conn->rollback(); } catch (Throwable $rollbackException) {
                            error_log('bulk_users rollback failed: ' . $rollbackException->getMessage());
                        }
                    }
                    if (isset($insertStmt) && $insertStmt instanceof mysqli_stmt) $insertStmt->close();
                    if (isset($lookupStmt) && $lookupStmt instanceof mysqli_stmt) $lookupStmt->close();
                    $createdResults = [];
                    error_log('bulk_users create failed: ' . $exception->getMessage());
                    $pageError = 'Bulk account creation failed and was rolled back. Check the server error log.';
                }
            }
        }
    } else {
        http_response_code(400);
        $pageError = 'Unknown action.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Bulk User Accounts</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 28px 16px; background: #f4f6f9; color: #202124; font-family: Arial, Helvetica, sans-serif; }
        main { max-width: 1100px; margin: 0 auto; }
        header, .actions { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; }
        h1 { margin: 0; }
        a { color: #107c41; font-weight: 700; }
        .card { padding: 20px; margin: 18px 0; background: #fff; border: 1px solid #dce1e7; border-radius: 9px; }
        p { color: #555; line-height: 1.5; }
        textarea { width: 100%; min-height: 180px; padding: 10px; font: 13px monospace; }
        input[type="file"] { margin: 10px 0; }
        button { padding: 10px 14px; border: 0; border-radius: 5px; background: #107c41; color: #fff; font: inherit; cursor: pointer; }
        .notice { padding: 11px 13px; margin: 14px 0; border-radius: 5px; }
        .notice.error { background: #fdecea; color: #7a1f16; }
        .notice.success { background: #e8f5e9; color: #14532d; }
        .table-scroll { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 9px; border-bottom: 1px solid #e4e8ed; text-align: left; vertical-align: top; }
        th { background: #f1f4f6; }
        .errors { color: #a12622; }
        .valid { color: #14532d; }
        code { overflow-wrap: anywhere; }
    </style>
</head>
<body>
<main>
    <header><h1>Bulk User Accounts</h1><nav><a href="manage_users.php">Manage Users</a> · <a href="results_2.php">Results</a></nav></header>
    <?php if ($pageError !== ''): ?><div class="notice error"><?php echo bulk_users_h($pageError); ?></div><?php endif; ?>
    <?php if ($pageMessage !== ''): ?><div class="notice success"><?php echo bulk_users_h($pageMessage); ?></div><?php endif; ?>

    <?php if ($createdResults): ?>
        <section class="card">
            <h2>One-time account results</h2>
            <p>Copy or download generated passwords now. They are shown only in this response and are not saved in the database or session.</p>
            <button type="button" id="downloadResults">Download CSV</button>
            <div class="table-scroll">
                <table id="createdUsers">
                    <thead><tr><th>Username</th><th>Generated password</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($createdResults as $result): ?>
                        <tr><td><?php echo bulk_users_h($result['username']); ?></td><td><code><?php echo bulk_users_h($result['password']); ?></code></td><td><?php echo bulk_users_h($result['status']); ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <script>
            document.getElementById('downloadResults').addEventListener('click', function () {
                const rows = Array.from(document.querySelectorAll('#createdUsers tr')).map(row =>
                    Array.from(row.cells).map(cell => {
                        const value = cell.textContent.trim();
                        return /^[=+\-@\t\r]/.test(value) ? "'" + value : value;
                    })
                );
                const csv = rows.map(row => row.map(value => '"' + value.replace(/"/g, '""') + '"').join(',')).join('\r\n');
                const link = document.createElement('a');
                const objectUrl = URL.createObjectURL(new Blob(['\uFEFF' + csv], {type: 'text/csv;charset=utf-8'}));
                link.href = objectUrl;
                link.download = 'created-users.csv';
                link.click();
                setTimeout(() => URL.revokeObjectURL(objectUrl), 1000);
            });
        </script>
    <?php endif; ?>

    <?php if ($previewRows): ?>
        <section class="card">
            <h2>Step 1: Review rows</h2>
            <p>Rows with errors will not be created. Duplicate usernames are flagged. Confirm creates only rows that pass validation at confirmation time.</p>
            <div class="table-scroll">
                <table>
                    <thead><tr><th>CSV row</th><th>Username</th><th>Role</th><th>Salary grade</th><th>Region</th><th>Field</th><th>Validation</th></tr></thead>
                    <tbody>
                    <?php foreach ($previewRows as $row): ?>
                        <tr>
                            <td><?php echo (int)$row['line']; ?></td>
                            <td><?php echo bulk_users_h($row['username']); ?></td>
                            <td><?php echo bulk_users_h($row['role']); ?></td>
                            <td><?php echo bulk_users_h($row['salary_grade']); ?></td>
                            <td><?php echo bulk_users_h($row['region']); ?></td>
                            <td><?php echo bulk_users_h($row['field_name']); ?></td>
                            <td class="<?php echo $row['errors'] ? 'errors' : 'valid'; ?>">
                                <?php echo $row['errors'] ? bulk_users_h(implode(' ', $row['errors'])) : 'Valid'; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php $staged = $_SESSION['bulk_users_preview'] ?? null; ?>
            <?php if (is_array($staged) && isset($staged['id'])): ?>
                <form method="post" class="actions">
                    <input type="hidden" name="csrf_token" value="<?php echo bulk_users_h($csrfToken); ?>">
                    <input type="hidden" name="action" value="confirm">
                    <input type="hidden" name="batch_id" value="<?php echo bulk_users_h($staged['id']); ?>">
                    <button type="submit">Step 2: Create valid accounts</button>
                </form>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <section class="card">
        <h2>Upload or paste a CSV</h2>
        <p>Required columns: <code>username,role,salary_grade,region,field_name</code>. Roles follow registration rules (rater or user), region must be Region VI, and salary grades must exist in Regional Office positions. Maximum 500 rows and 1 MB.</p>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo bulk_users_h($csrfToken); ?>">
            <input type="hidden" name="action" value="preview">
            <label for="csv_file">CSV file</label>
            <input type="file" id="csv_file" name="csv_file" accept=".csv,text/csv">
            <label for="csv_text">Or paste CSV text</label>
            <textarea id="csv_text" name="csv_text" placeholder="username,role,salary_grade,region,field_name&#10;Jane Doe,rater,18,Region VI,Forestry"></textarea>
            <button type="submit">Preview CSV</button>
        </form>
    </section>
</main>
</body>
</html>
