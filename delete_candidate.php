<?php
// Buffer everything so a stray PHP warning can never corrupt the JSON reply.
ob_start();

require_once 'auth.php';
require_admin(true);
require_once 'db.php';
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

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($id <= 0) {
    json_out(['status' => 'error', 'message' => 'Invalid Candidate ID']);
}

try {
    ensure_schema($conn);

    $conn->begin_transaction();

    $sc = $conn->prepare("DELETE FROM candidate_scores WHERE candidate_id = ?");
    $sc->bind_param("i", $id);
    $sc->execute();
    $sc->close();

    $stmt = $conn->prepare("DELETE FROM candidates WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $deleted = $stmt->affected_rows;
    $stmt->close();

    $conn->commit();

    if ($deleted < 1) {
        // Nothing matched: it was already gone (or the id was wrong). Report it instead of pretending.
        json_out(['status' => 'error', 'message' => 'That applicant was not found in the database (it may already be deleted). Refresh the page.']);
    }

    json_out(['status' => 'success']);
} catch (Exception $e) {
    try { $conn->rollback(); } catch (Exception $ignored) {}
    error_log('delete_candidate error: ' . $e->getMessage());
    json_out(['status' => 'error', 'message' => DEBUG ? $e->getMessage() : 'Could not delete.'], 500);
}