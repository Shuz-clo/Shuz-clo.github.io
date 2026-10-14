<?php
// Permanently deletes one tab (office) from the results sheet:
//   - all applicants saved under that office, and their scores
//   - the saved rater columns for that office
//   - and remembers the tab as deleted, so built-in tabs don't come back on refresh.
// Admin only, POST only, CSRF-protected. Replies with JSON.

// Buffer everything so a stray PHP warning can never corrupt the JSON reply.
ob_start();

require_once 'db.php';
require_once __DIR__ . '/AuthSchema.php';
require_once 'auth.php';
require_role('admin');
require_once __DIR__ . '/Raters.php';

function json_out(array $payload, int $code = 200): void {
    if (ob_get_length()) ob_clean();
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['status' => 'error', 'message' => 'Invalid request.'], 405);
}

if (!verify_csrf($_POST['csrf_token'] ?? null)) {
    json_out(['status' => 'error', 'message' => 'Your session expired. Please refresh the page and try again.'], 403);
}

$office = strtoupper(mb_substr(trim((string)($_POST['office'] ?? '')), 0, 100));
if ($office === '') {
    json_out(['status' => 'error', 'message' => 'Invalid tab name.'], 422);
}

// The results page shows applicants with an empty office under REGIONAL OFFICE, so delete those with it.
$isRegional = ($office === 'REGIONAL OFFICE');

$sqlScores = "DELETE cs FROM candidate_scores cs
              INNER JOIN candidates c ON c.id = cs.candidate_id
              WHERE (UPPER(TRIM(c.office_name)) = ?"
           . ($isRegional ? " OR c.office_name IS NULL OR TRIM(c.office_name) = ''" : "") . ")";

$sqlCandidates = "DELETE FROM candidates
                  WHERE (UPPER(TRIM(office_name)) = ?"
               . ($isRegional ? " OR office_name IS NULL OR TRIM(office_name) = ''" : "") . ")";

try {
    ensure_schema($conn); // creates removed_tabs if needed (before the transaction: CREATE TABLE commits implicitly)

    $conn->begin_transaction();

    $stmt = $conn->prepare($sqlScores);
    $stmt->bind_param('s', $office);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare($sqlCandidates);
    $stmt->bind_param('s', $office);
    $stmt->execute();
    $deletedCandidates = $stmt->affected_rows;
    $stmt->close();

    $stmt = $conn->prepare("DELETE FROM position_rater_order WHERE office = ?");
    $stmt->bind_param('s', $office);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("INSERT INTO removed_tabs (office) VALUES (?)
        ON DUPLICATE KEY UPDATE removed_at = CURRENT_TIMESTAMP");
    $stmt->bind_param('s', $office);
    $stmt->execute();
    $stmt->close();

    $conn->commit();

    json_out(['status' => 'success', 'deletedCandidates' => max(0, (int)$deletedCandidates)]);
} catch (Exception $e) {
    try { $conn->rollback(); } catch (Exception $ignored) {}
    error_log('delete_tab error: ' . $e->getMessage());
    json_out(['status' => 'error', 'message' => DEBUG ? $e->getMessage() : 'Could not delete the tab.'], 500);
}