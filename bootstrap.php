<?php

require_once __DIR__ . '/config.php';

$appDevMode = defined('APP_DEV_MODE') && APP_DEV_MODE === true;
error_reporting(E_ALL);
ini_set('display_errors', $appDevMode ? '1' : '0');
ini_set('display_startup_errors', $appDevMode ? '1' : '0');
ini_set('log_errors', '1');

$configuredLogDir = getenv('APP_ERROR_LOG_DIR');
if ($configuredLogDir !== false && $configuredLogDir !== '') {
    $appLogDir = $configuredLogDir;
} elseif (!empty($_SERVER['DOCUMENT_ROOT'])) {
    $appLogDir = dirname(rtrim((string)$_SERVER['DOCUMENT_ROOT'], DIRECTORY_SEPARATOR))
        . DIRECTORY_SEPARATOR . 'interview_rating_logs';
} else {
    $appLogDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'interview_rating_logs';
}

if (!is_dir($appLogDir) && !@mkdir($appLogDir, 0700, true) && !is_dir($appLogDir)) {
    $appLogDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'interview_rating_logs';
    if (!is_dir($appLogDir) && !@mkdir($appLogDir, 0700, true) && !is_dir($appLogDir)) {
        error_log('Unable to create the private application log directory.');
    }
}

ini_set('error_log', $appLogDir . DIRECTORY_SEPARATOR . 'php-errors.log');

set_exception_handler(static function (Throwable $exception) use ($appDevMode): void {
    error_log((string)$exception);
    http_response_code(500);

    if ($exception instanceof mysqli_sql_exception && (int)$exception->getCode() === 1146) {
        http_response_code(503);
        $message = 'Application setup is incomplete. Run setup.php from the command line, then reload this page.';
    } else {
        $message = 'The request could not be completed. Check the private application error log.';
    }
    if ($appDevMode) {
        $message .= ' Details: ' . $exception->getMessage();
    }

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    $wantsJson = str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
        || in_array(basename((string)($_SERVER['SCRIPT_NAME'] ?? '')), [
            'positions_api.php', 'sheets_api.php', 'template_api.php',
        ], true);
    if ($wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => $message]);
        exit;
    }

    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
});

require_once __DIR__ . '/db.php';
ini_set('display_errors', $appDevMode ? '1' : '0');
ini_set('display_startup_errors', $appDevMode ? '1' : '0');
ini_set('log_errors', '1');

function app_require_tables(mysqli $conn, array $tables): void {
    $tables = array_values(array_unique($tables));
    if (!$tables) return;

    foreach ($tables as $table) {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            throw new InvalidArgumentException('Invalid required table name.');
        }
    }
    $quotedTables = array_map(
        static fn(string $table): string => "'" . $conn->real_escape_string($table) . "'",
        $tables
    );
    $result = $conn->query(
        'SELECT TABLE_NAME FROM information_schema.TABLES '
        . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . implode(',', $quotedTables) . ')'
    );
    $found = [];
    while ($row = $result->fetch_assoc()) {
        $found[] = $row['TABLE_NAME'];
    }

    $missing = array_values(array_diff($tables, $found));
    if (!$missing) return;

    error_log('Required database tables are missing: ' . implode(', ', $missing));
    http_response_code(503);
    $message = 'Application setup is incomplete. Missing database table(s): '
        . implode(', ', $missing) . '. Run setup.php from the command line, then reload this page.';
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    $wantsJson = str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
        || in_array(basename((string)($_SERVER['SCRIPT_NAME'] ?? '')), [
            'positions_api.php', 'sheets_api.php', 'template_api.php',
        ], true);
    if ($wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => $message]);
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        echo $message;
    }
    exit;
}

if (!defined('APP_SKIP_SCHEMA_CHECK') || APP_SKIP_SCHEMA_CHECK !== true) {
    app_require_tables($conn, [
        'users', 'remember_tokens', 'login_attempts', 'submission_attempts',
        'score_audit', 'candidates', 'candidate_scores', 'sheet_settings',
        'position_rater_order', 'removed_tabs', 'sheet_documents',
        'competency_templates', 'sheets', 'sheet_cells', 'evidence_options',
        'positions', 'positions_seed_state_v2',
    ]);
}
