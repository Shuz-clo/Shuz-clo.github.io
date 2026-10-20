<?php
// ============================================================
// sheets_api.php — JSON API for draft sheets
// ============================================================

ob_start();
require_once 'db.php';
require_once __DIR__ . '/AuthSchema.php';
require_once 'auth.php';
require_once __DIR__ . '/sheets_schema.php';

require_login(true);

function json_out(array $payload, int $code = 200): void {
    if (ob_get_length()) ob_clean();
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

function own_sheet(mysqli $conn, int $id, int $uid): ?array {
    $st = $conn->prepare("SELECT * FROM sheets WHERE id = ? AND user_id = ? LIMIT 1");
    $st->bind_param('ii', $id, $uid);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: null;
}

ensure_sheets_schema($conn);

$uid    = (int)current_user_id();
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verify_csrf($_POST['csrf_token'] ?? null)) {
    json_out(['status' => 'error', 'message' => 'Session expired. Please refresh the page.'], 403);
}

try {
    switch ($action) {

        case 'list': {
            $st = $conn->prepare("SELECT id, title, candidate_name, status, updated_at FROM sheets WHERE user_id = ? ORDER BY updated_at DESC");
            $st->bind_param('i', $uid);
            $st->execute();
            $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
            $st->close();
            json_out(['status' => 'success', 'sheets' => $rows]);
        }

        case 'create': {
            $title = mb_substr(trim($_POST['title'] ?? ''), 0, 150) ?: 'Untitled sheet';
            $layout = json_encode(default_sheets_layout(), JSON_UNESCAPED_UNICODE);
            $st = $conn->prepare("INSERT INTO sheets (user_id, title, layout_json) VALUES (?, ?, ?)");
            $st->bind_param('iss', $uid, $title, $layout);
            $st->execute();
            $id = $conn->insert_id;
            $st->close();
            json_out(['status' => 'success', 'id' => $id]);
        }

        case 'load': {
            $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
            $sheet = own_sheet($conn, $id, $uid);
            if (!$sheet) json_out(['status' => 'error', 'message' => 'Sheet not found.'], 404);

            $layout = json_decode($sheet['layout_json'] ?? '', true);
            if (!is_array($layout) || empty($layout['sections'])) {
                $layout = default_sheets_layout();
            }

            $st = $conn->prepare("SELECT cell_key, `value` FROM sheet_cells WHERE sheet_id = ?");
            $st->bind_param('i', $id);
            $st->execute();
            $res = $st->get_result();
            $cells = [];
            while ($row = $res->fetch_assoc()) {
                $cells[$row['cell_key']] = $row['value'];
            }
            $st->close();

            json_out([
                'status' => 'success',
                'sheet'  => [
                    'id'             => (int)$sheet['id'],
                    'title'          => $sheet['title'],
                    'candidate_name' => $sheet['candidate_name'],
                    'position_title' => $sheet['position_title'],
                    'office_name'    => $sheet['office_name'],
                    'interview_date' => $sheet['interview_date'],
                    'status'         => $sheet['status'],
                ],
                'layout'          => $layout,
                'cells'           => $cells,
                'evidence_options'=> load_evidence_options($conn),
            ]);
        }

        case 'save': {
            $id = (int)($_POST['id'] ?? 0);
            $sheet = own_sheet($conn, $id, $uid);
            if (!$sheet) json_out(['status' => 'error', 'message' => 'Sheet not found.'], 404);

            $header = json_decode($_POST['header'] ?? '{}', true);
            if (!is_array($header)) $header = [];

            $layoutRaw = $_POST['layout_json'] ?? '';
            $layout = json_decode($layoutRaw, true);
            if (!is_array($layout)) $layout = null;

            $cells = json_decode($_POST['cells'] ?? '{}', true);
            if (!is_array($cells)) $cells = [];

            $conn->begin_transaction();

            $title   = mb_substr(trim($header['title']   ?? $sheet['title']), 0, 150) ?: 'Untitled sheet';
            $cand    = mb_substr(trim($header['candidate_name'] ?? ''), 0, 255);
            $pos     = mb_substr(trim($header['position_title'] ?? ''), 0, 255);
            $office  = mb_substr(trim($header['office_name'] ?? ''), 0, 255);
            $date    = mb_substr(trim($header['interview_date'] ?? ''), 0, 255);

            if ($layout !== null) {
                $layoutJson = json_encode($layout, JSON_UNESCAPED_UNICODE);
                $st = $conn->prepare("UPDATE sheets SET title = ?, candidate_name = ?, position_title = ?, office_name = ?, interview_date = ?, layout_json = ? WHERE id = ?");
                $st->bind_param('ssssssi', $title, $cand, $pos, $office, $date, $layoutJson, $id);
            } else {
                $st = $conn->prepare("UPDATE sheets SET title = ?, candidate_name = ?, position_title = ?, office_name = ?, interview_date = ? WHERE id = ?");
                $st->bind_param('sssssi', $title, $cand, $pos, $office, $date, $id);
            }
            $st->execute();
            $st->close();

            $up  = $conn->prepare("INSERT INTO sheet_cells (sheet_id, cell_key, `value`) VALUES (?, ?, ?)
                                   ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");
            $del = $conn->prepare("DELETE FROM sheet_cells WHERE sheet_id = ? AND cell_key = ?");

            foreach ($cells as $key => $val) {
                if (!is_string($key)) continue;
                $key = mb_substr($key, 0, 80);
                $val = is_scalar($val) ? (string)$val : '';
                $val = mb_substr($val, 0, 5000);
                if ($val === '') {
                    $del->bind_param('is', $id, $key);
                    $del->execute();
                } else {
                    $up->bind_param('iss', $id, $key, $val);
                    $up->execute();
                }
            }
            $up->close();
            $del->close();

            $conn->commit();
            json_out(['status' => 'success', 'updated_at' => date('c')]);
        }

        case 'delete': {
            $id = (int)($_POST['id'] ?? 0);
            $sheet = own_sheet($conn, $id, $uid);
            if (!$sheet) json_out(['status' => 'error', 'message' => 'Sheet not found.'], 404);
            $conn->begin_transaction();
            $st = $conn->prepare("DELETE FROM sheet_cells WHERE sheet_id = ?");
            $st->bind_param('i', $id); $st->execute(); $st->close();
            $st = $conn->prepare("DELETE FROM sheets WHERE id = ?");
            $st->bind_param('i', $id); $st->execute(); $st->close();
            $conn->commit();
            json_out(['status' => 'success']);
        }

        case 'add_evidence_option': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                json_out(['status' => 'error', 'message' => 'POST required.'], 405);
            }
            $label = trim((string)($_POST['label'] ?? ''));
            $label = mb_substr($label, 0, 60);
            if ($label === '') {
                json_out(['status' => 'error', 'message' => 'Label is required.'], 422);
            }
            $st = $conn->prepare("INSERT IGNORE INTO evidence_options (label) VALUES (?)");
            $st->bind_param('s', $label);
            $st->execute();
            $st->close();
            json_out(['status' => 'success', 'options' => load_evidence_options($conn)]);
        }

        case 'list_evidence_options': {
            json_out(['status' => 'success', 'options' => load_evidence_options($conn)]);
        }

        default:
            json_out(['status' => 'error', 'message' => 'Unknown action.'], 400);
    }
} catch (Throwable $e) {
    try { $conn->rollback(); } catch (Throwable $ignored) {}
    error_log('sheets_api error: ' . $e->getMessage());
    json_out(['status' => 'error', 'message' => DEBUG ? $e->getMessage() : 'Something went wrong.'], 500);
}