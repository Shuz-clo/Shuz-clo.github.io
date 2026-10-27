<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

define('APP_SKIP_SCHEMA_CHECK', true);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/AuthSchema.php';
require_once __DIR__ . '/PositionsSchema.php';
require_once __DIR__ . '/Raters.php';
require_once __DIR__ . '/Audit.php';
require_once __DIR__ . '/competency_template_schema.php';
require_once __DIR__ . '/sheets_schema.php';

try {
    ensure_auth_schema($conn);
    ensure_positions_schema($conn);
    ensure_schema($conn);
    ensure_audit_table($conn);
    ensure_competency_template_schema($conn);
    ensure_sheets_schema($conn);
    fwrite(STDOUT, "Database setup completed successfully.\n");
} catch (Throwable $exception) {
    error_log('setup.php failed: ' . $exception);
    fwrite(STDERR, "Database setup failed. Check the private application error log.\n");
    if (defined('APP_DEV_MODE') && APP_DEV_MODE === true) {
        fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    }
    exit(1);
}
