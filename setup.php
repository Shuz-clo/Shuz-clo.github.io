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
require_once __DIR__ . '/competency_template_schema.php';
require_once __DIR__ . '/sheets_schema.php';
require_once __DIR__ . '/DraftsSchema.php';

try {
    if (!app_allow_ddl()) {
        throw new RuntimeException('Database schema changes are disabled by app_allow_ddl().');
    }

    ensure_auth_schema($conn);
    ensure_positions_schema($conn);
    ensure_schema($conn);
    ensure_competency_template_schema($conn);
    ensure_sheets_schema($conn);
    ensure_drafts_schema($conn);
    app_require_tables($conn, ['password_reset_requests', 'form_drafts']);
    fwrite(STDOUT, "Database setup completed successfully for '" . DB_NAME . "'.\n");
} catch (Throwable $exception) {
    error_log('setup.php failed: ' . $exception);
    fwrite(STDERR, "Database setup failed for '" . DB_NAME . "' at " . DB_HOST . ':' . DB_PORT . ".\n");
    fwrite(STDERR, "Error: " . $exception->getMessage() . PHP_EOL);
    exit(1);
}