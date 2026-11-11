<?php
ob_start();

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/AuthSchema.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/PositionsSchema.php';
require_once __DIR__ . '/OfficeTypeMap.php';

function positions_json_out(array $payload, int $status = 200): void {
    if (ob_get_length()) ob_clean();
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
try {
    ensure_auth_schema($conn);
} catch (Throwable $e) {
    error_log('Positions API authentication schema failed: ' . $e->getMessage());
    positions_json_out(['status' => 'error', 'message' => 'Unable to initialize authentication.'], 500);
}
if ($method === 'GET') {
    require_login(true);
} elseif ($method === 'POST') {
    require_admin(true);
} else {
    positions_json_out(['status' => 'error', 'message' => 'Method not allowed.'], 405);
}

try {
    ensure_positions_schema($conn);
} catch (Throwable $e) {
    error_log('Positions API schema initialization failed: ' . $e->getMessage());
    positions_json_out(['status' => 'error', 'message' => 'Unable to initialize the positions database.'], 500);
}

if ($method === 'GET') {
    if (($_GET['action'] ?? '') !== 'list') {
        positions_json_out(['status' => 'error', 'message' => 'Unknown action.'], 400);
    }
    $scope = strtoupper(trim((string)($_GET['scope'] ?? 'REGIONAL_OFFICE')));
    if (!preg_match('/^[A-Z][A-Z0-9_]{0,39}$/', $scope)) {
        positions_json_out(['status' => 'error', 'message' => 'Invalid position scope.'], 422);
    }

    try {
        $isAdmin = current_role() === 'admin';
        $accountType = null;
        $allowedOfficeTypes = ['RO', 'FO'];
        if (!$isAdmin) {
            $profile = find_user_access_profile($conn, (int)current_user_id());
            $accountType = account_type_for_profile($profile);
            $allowedOfficeTypes = office_types_for_account_type($accountType);
            if (!$allowedOfficeTypes) {
                positions_json_out(['status' => 'error', 'message' => 'Your account has no assigned account type. Ask an administrator to update your access profile.'], 403);
            }
        }

        $positions = [];
        if ($scope === 'REGIONAL_OFFICE' && in_array('RO', $allowedOfficeTypes, true)) {
        $stmt = $conn->prepare('SELECT title, salary_grade, leadership_level FROM positions WHERE office_scope = ? ORDER BY salary_grade DESC, title ASC');
        $stmt->bind_param('s', $scope);
        $stmt->execute();
        $regionalPositions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        foreach ($regionalPositions as &$position) {
            $position['salary_grade'] = (int)$position['salary_grade'];
            $position['office_type'] = 'RO';
        }
        unset($position);
        array_push($positions, ...$regionalPositions);
        }
        if ($scope === 'REGIONAL_OFFICE' && in_array('FO', $allowedOfficeTypes, true)) {
            foreach (field_positions_by_sheet() as $position) {
                $position['salary_grade'] = null;
                $position['leadership_level'] = null;
                $positions[] = $position;
            }
        }
        positions_json_out(['status' => 'success', 'positions' => $positions]);
    } catch (Throwable $e) {
        error_log('Positions API list failed: ' . $e->getMessage());
        positions_json_out(['status' => 'error', 'message' => 'Unable to load positions.'], 500);
    }
}

if (($_POST['action'] ?? '') !== 'import') {
    positions_json_out(['status' => 'error', 'message' => 'Unknown action.'], 400);
}
if (!verify_csrf(isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : null)) {
    positions_json_out(['status' => 'error', 'message' => 'Your session expired. Please refresh the page.'], 403);
}

$scope = strtoupper(trim((string)($_POST['scope'] ?? 'REGIONAL_OFFICE')));
if (!preg_match('/^[A-Z][A-Z0-9_]{0,39}$/', $scope)) {
    positions_json_out(['status' => 'error', 'message' => 'Scope must use letters, numbers, and underscores, and start with a letter.'], 422);
}
[$rows, $errors] = parse_positions_csv((string)($_POST['csv'] ?? ''));
if (!$rows) {
    positions_json_out([
        'status' => 'error',
        'message' => 'No valid position rows were found.',
        'inserted' => 0,
        'updated' => 0,
        'skipped' => count($errors),
        'errors' => $errors,
    ], 422);
}

try {
    $existsStmt = $conn->prepare('SELECT id FROM positions WHERE office_scope = ? AND title = ? LIMIT 1');
    $upsertStmt = $conn->prepare("INSERT INTO positions (office_scope, title, salary_grade, leadership_level)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE salary_grade = VALUES(salary_grade), leadership_level = VALUES(leadership_level)");
    $inserted = 0;
    $updated = 0;
    foreach ($rows as $position) {
        $title = $position['title'];
        $salaryGrade = $position['salary_grade'];
        $level = $position['leadership_level'];
        $existsStmt->bind_param('ss', $scope, $title);
        $existsStmt->execute();
        $exists = $existsStmt->get_result()->num_rows > 0;
        $upsertStmt->bind_param('ssis', $scope, $title, $salaryGrade, $level);
        $upsertStmt->execute();
        if ($exists) $updated++;
        else $inserted++;
    }
    $existsStmt->close();
    $upsertStmt->close();
    positions_json_out([
        'status' => 'success',
        'inserted' => $inserted,
        'updated' => $updated,
        'skipped' => count($errors),
        'errors' => $errors,
    ]);
} catch (Throwable $e) {
    error_log('Positions API import failed: ' . $e->getMessage());
    positions_json_out(['status' => 'error', 'message' => 'Unable to import positions.'], 500);
}
