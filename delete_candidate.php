<?php
ob_start();

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/AuthSchema.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/Raters.php';
require_once __DIR__ . '/Audit.php';

require_admin(true);

function delete_candidate_json(array $payload, int $status = 200): void {
    if (ob_get_length()) ob_clean();
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    delete_candidate_json(['status' => 'error', 'message' => 'Invalid request.'], 405);
}
if (!verify_csrf($_POST['csrf_token'] ?? null)) {
    delete_candidate_json(['status' => 'error', 'message' => 'Your session expired. Please refresh the page and try again.'], 403);
}

$candidateId = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($candidateId === false) {
    delete_candidate_json(['status' => 'error', 'message' => 'Invalid applicant.'], 422);
}

try {
    ensure_schema($conn);
    $conn->begin_transaction();

    $stmt = $conn->prepare('SELECT candidate_name, position_title, office_name, interview_date
        FROM candidates WHERE id = ? FOR UPDATE');
    $stmt->bind_param('i', $candidateId);
    $stmt->execute();
    $candidate = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$candidate) {
        $conn->rollback();
        delete_candidate_json(['status' => 'error', 'message' => 'That applicant no longer exists.'], 404);
    }

    $stmt = $conn->prepare('DELETE FROM candidate_scores WHERE candidate_id = ?');
    $stmt->bind_param('i', $candidateId);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare('DELETE FROM candidates WHERE id = ?');
    $stmt->bind_param('i', $candidateId);
    $stmt->execute();
    $stmt->close();

    $conn->commit();
    audit_log($conn, $candidateId, 'delete', $candidate, null);
    delete_candidate_json(['status' => 'success']);
} catch (Throwable $e) {
    try { $conn->rollback(); } catch (Throwable $ignored) {}
    error_log('delete_candidate error: ' . $e->getMessage());
    delete_candidate_json([
        'status' => 'error',
        'message' => DEBUG ? $e->getMessage() : 'Could not delete the applicant.'
    ], 500);
}