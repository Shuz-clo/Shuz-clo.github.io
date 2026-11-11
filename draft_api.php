<?php
ob_start();

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/AuthSchema.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/AccessGroups.php';

require_role(true, ['admin', 'rater']);

function draft_json_out(array $payload, int $status = 200): void {
    if (ob_get_length()) ob_clean();
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

function draft_template_available(mysqli $conn, ?int $templateId, ?int $grade, string $position): array {
    if ($templateId === null) return ['available' => true, 'updated_at' => null];

    $stmt = $conn->prepare('SELECT id, title, updated_at, min_salary_grade, max_salary_grade,
            region, field_name, is_shared
        FROM competency_templates WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $templateId);
    $stmt->execute();
    $template = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$template) return ['available' => false, 'updated_at' => null];

    if (current_role() === 'rater') {
        $profile = find_user_access_profile($conn, (int)current_user_id());
        $userGrade = $profile['salary_grade'] ?? null;
        $expectedTitle = $grade !== null ? 'SG ' . $grade . ' - ' . $position : '';
        $available = is_int($userGrade)
            && array_key_exists((string)($profile['region'] ?? ''), ACCESS_REGIONS)
            && access_field_is_valid($profile['field_name'] ?? null)
            && $grade === $userGrade
            && (int)$template['is_shared'] === 1
            && $template['min_salary_grade'] !== null
            && $template['max_salary_grade'] !== null
            && $grade >= (int)$template['min_salary_grade']
            && $grade <= (int)$template['max_salary_grade']
            && $userGrade >= (int)$template['min_salary_grade']
            && $userGrade <= (int)$template['max_salary_grade']
            && ($template['region'] === null || $template['region'] === ($profile['region'] ?? null))
            && ($template['field_name'] === null || $template['field_name'] === ($profile['field_name'] ?? null))
            && $template['title'] === $expectedTitle;
        return ['available' => $available, 'updated_at' => $template['updated_at']];
    }

    return ['available' => true, 'updated_at' => $template['updated_at']];
}

function draft_summary(array $row): array {
    return [
        'id' => (int)$row['id'],
        'candidate_name' => (string)($row['candidate_name'] ?? ''),
        'position_title' => (string)($row['position_title'] ?? ''),
        'office_name' => (string)($row['office_name'] ?? ''),
        'salary_grade' => $row['salary_grade'] === null ? null : (int)$row['salary_grade'],
        'template_id' => $row['template_id'] === null ? null : (int)$row['template_id'],
        'updated_at' => (string)$row['updated_at'],
    ];
}

function trim_saved_drafts(mysqli $conn, int $userId): void {
    $stmt = $conn->prepare("SELECT id FROM form_drafts
        WHERE user_id = ? AND status = 'saved'
        ORDER BY updated_at DESC, id DESC");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (count($rows) <= 5) return;
    $delete = $conn->prepare("DELETE FROM form_drafts WHERE id = ? AND user_id = ? AND status = 'saved'");
    foreach (array_slice($rows, 5) as $row) {
        $id = (int)$row['id'];
        $delete->bind_param('ii', $id, $userId);
        $delete->execute();
    }
    $delete->close();
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    draft_json_out(['status' => 'error', 'message' => 'POST is required.'], 405);
}

$rawBody = file_get_contents('php://input');
$input = json_decode($rawBody, true);
if (!is_array($input)) {
    draft_json_out(['status' => 'error', 'message' => 'Invalid JSON request.'], 400);
}
if (!verify_csrf(isset($input['csrf_token']) ? (string)$input['csrf_token'] : null)) {
    draft_json_out(['status' => 'error', 'message' => 'Your session expired. Reload the page and try again.'], 403);
}

$userId = (int)current_user_id();
$action = (string)($input['action'] ?? '');

try {
    if ($action === 'save_draft') {
        $formJson = $input['form_json'] ?? null;
        if (!is_string($formJson) || strlen($formJson) > 204800) {
            draft_json_out(['status' => 'error', 'message' => 'Draft form data must be valid JSON no larger than 200 KB.'], 422);
        }
        try {
            $formState = json_decode($formJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            draft_json_out(['status' => 'error', 'message' => 'Draft form data is not valid JSON.'], 422);
        }
        if (!is_array($formState) || !is_array($formState['fields'] ?? null)) {
            draft_json_out(['status' => 'error', 'message' => 'Draft form data has an invalid shape.'], 422);
        }

        $fields = $formState['fields'];
        $templateValue = $fields['competency_template_id'][0]['value'] ?? null;
        $templateId = ($templateValue === null || $templateValue === '') ? null : filter_var($templateValue, FILTER_VALIDATE_INT);
        if ($templateId === false || ($templateId !== null && $templateId < 1)) {
            draft_json_out(['status' => 'error', 'message' => 'Select a valid scoring template before saving this draft.'], 422);
        }
        $gradeValue = $fields['salary_grade'][0]['value'] ?? null;
        $grade = ($gradeValue === null || $gradeValue === '') ? null : filter_var($gradeValue, FILTER_VALIDATE_INT);
        if ($grade === false || ($grade !== null && ($grade < 0 || $grade > 26))) {
            draft_json_out(['status' => 'error', 'message' => 'Salary grade must be between 0 and 26.'], 422);
        }
        $getValue = static function (string $name) use ($fields): string {
            $value = $fields[$name][0]['value'] ?? '';
            return is_string($value) ? mb_substr($value, 0, 255) : '';
        };
        $position = $getValue('position_title');
        $office = $getValue('office_name');
        $candidate = $getValue('candidate_name');
        if ($templateId !== null) {
            $check = $conn->prepare('SELECT id FROM competency_templates WHERE id = ? LIMIT 1');
            $check->bind_param('i', $templateId);
            $check->execute();
            $exists = $check->get_result()->num_rows === 1;
            $check->close();
            if (!$exists) draft_json_out(['status' => 'error', 'message' => 'The selected template no longer exists.'], 422);
        }

        $conn->begin_transaction();
        $lock = $conn->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
        $lock->bind_param('i', $userId);
        $lock->execute();
        $lock->close();
        $cleanup = $conn->prepare('DELETE FROM form_drafts WHERE user_id = ? AND updated_at < DATE_SUB(NOW(), INTERVAL 30 DAY)');
        $cleanup->bind_param('i', $userId);
        $cleanup->execute();
        $cleanup->close();

        $find = $conn->prepare("SELECT id FROM form_drafts WHERE user_id = ? AND status = 'draft' ORDER BY updated_at DESC, id DESC");
        $find->bind_param('i', $userId);
        $find->execute();
        $draftRows = $find->get_result()->fetch_all(MYSQLI_ASSOC);
        $find->close();
        if ($draftRows) {
            $draftId = (int)$draftRows[0]['id'];
            foreach (array_slice($draftRows, 1) as $duplicate) {
                $duplicateId = (int)$duplicate['id'];
                $delete = $conn->prepare("DELETE FROM form_drafts WHERE id = ? AND user_id = ? AND status = 'draft'");
                $delete->bind_param('ii', $duplicateId, $userId);
                $delete->execute();
                $delete->close();
            }
            $update = $conn->prepare("UPDATE form_drafts
                SET template_id = ?, salary_grade = ?, position_title = ?, office_name = ?,
                    candidate_name = ?, form_json = ?
                WHERE id = ? AND user_id = ? AND status = 'draft'");
            $update->bind_param('iissssii', $templateId, $grade, $position, $office, $candidate, $formJson, $draftId, $userId);
            $update->execute();
            $update->close();
        } else {
            $insert = $conn->prepare("INSERT INTO form_drafts
                (user_id, status, template_id, salary_grade, position_title, office_name, candidate_name, form_json)
                VALUES (?, 'draft', ?, ?, ?, ?, ?, ?)");
            $insert->bind_param('iiissss', $userId, $templateId, $grade, $position, $office, $candidate, $formJson);
            $insert->execute();
            $draftId = (int)$conn->insert_id;
            $insert->close();
        }
        $conn->commit();
        draft_json_out(['status' => 'success', 'id' => $draftId, 'saved_at' => date(DATE_ATOM)]);
    }

    if ($action === 'get_draft') {
        $stmt = $conn->prepare("SELECT id, template_id, salary_grade, position_title, office_name,
                candidate_name, form_json, updated_at
            FROM form_drafts WHERE user_id = ? AND status = 'draft'
            ORDER BY updated_at DESC, id DESC LIMIT 1");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) draft_json_out(['status' => 'success', 'draft' => null, 'prompt_allowed' => false]);

        $templateId = $row['template_id'] === null ? null : (int)$row['template_id'];
        $grade = $row['salary_grade'] === null ? null : (int)$row['salary_grade'];
        $availability = draft_template_available($conn, $templateId, $grade, (string)$row['position_title']);
        $promptAllowed = empty($_SESSION['draft_resume_prompt_seen']);
        if ($promptAllowed) $_SESSION['draft_resume_prompt_seen'] = true;
        $savedTemplateTimestamp = is_array($state) ? ($state['template_updated_at'] ?? null) : null;
        draft_json_out([
            'status' => 'success',
            'draft' => draft_summary($row),
            'form_json' => (string)$row['form_json'],
            'template_available' => $availability['available'],
            'template_updated_at' => $availability['updated_at'],
            'template_changed' => $savedTemplateTimestamp !== null
                && $availability['updated_at'] !== null
                && $savedTemplateTimestamp !== $availability['updated_at'],
            'prompt_allowed' => $promptAllowed,
        ]);
    }

    if ($action === 'discard_draft') {
        $draftId = isset($input['id']) ? filter_var($input['id'], FILTER_VALIDATE_INT) : null;
        if ($draftId === false || ($draftId !== null && $draftId < 1)) {
            draft_json_out(['status' => 'error', 'message' => 'Invalid draft.'], 422);
        }
        if ($draftId === null) {
            $stmt = $conn->prepare("DELETE FROM form_drafts WHERE user_id = ? AND status = 'draft'");
            $stmt->bind_param('i', $userId);
        } else {
            $stmt = $conn->prepare("DELETE FROM form_drafts WHERE id = ? AND user_id = ? AND status = 'draft'");
            $stmt->bind_param('ii', $draftId, $userId);
        }
        $stmt->execute();
        $deleted = $stmt->affected_rows;
        $stmt->close();
        if ($draftId !== null && $deleted !== 1) {
            draft_json_out(['status' => 'error', 'message' => 'Draft not found.'], 404);
        }
        draft_json_out(['status' => 'success', 'discarded' => $deleted === 1]);
    }

    if ($action === 'mark_saved') {
        $conn->begin_transaction();
        $lock = $conn->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
        $lock->bind_param('i', $userId);
        $lock->execute();
        $lock->close();
        $find = $conn->prepare("SELECT id FROM form_drafts WHERE user_id = ? AND status = 'draft' ORDER BY updated_at DESC, id DESC LIMIT 1 FOR UPDATE");
        $find->bind_param('i', $userId);
        $find->execute();
        $row = $find->get_result()->fetch_assoc();
        $find->close();
        if (!$row) {
            $conn->commit();
            draft_json_out(['status' => 'success', 'marked_saved' => false]);
        }
        $draftId = (int)$row['id'];
        $update = $conn->prepare("UPDATE form_drafts SET status = 'saved' WHERE id = ? AND user_id = ? AND status = 'draft'");
        $update->bind_param('ii', $draftId, $userId);
        $update->execute();
        $marked = $update->affected_rows === 1;
        $update->close();
        trim_saved_drafts($conn, $userId);
        $conn->commit();
        draft_json_out(['status' => 'success', 'marked_saved' => $marked, 'id' => $draftId]);
    }

    if ($action === 'list_saved') {
        $stmt = $conn->prepare("SELECT id, template_id, salary_grade, position_title, office_name,
                candidate_name, updated_at
            FROM form_drafts WHERE user_id = ? AND status = 'saved'
            ORDER BY updated_at DESC, id DESC LIMIT 5");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $forms = [];
        foreach ($rows as $row) {
            $summary = draft_summary($row);
            $availability = draft_template_available(
                $conn,
                $summary['template_id'],
                $summary['salary_grade'],
                $summary['position_title']
            );
            $summary['template_available'] = $availability['available'];
            $forms[] = $summary;
        }
        draft_json_out(['status' => 'success', 'forms' => $forms]);
    }

    if ($action === 'load') {
        $draftId = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
        if ($draftId === false || $draftId < 1) {
            draft_json_out(['status' => 'error', 'message' => 'Invalid saved form.'], 422);
        }
        $stmt = $conn->prepare("SELECT id, template_id, salary_grade, position_title, office_name,
                candidate_name, form_json, updated_at
            FROM form_drafts WHERE id = ? AND user_id = ? AND status = 'saved' LIMIT 1");
        $stmt->bind_param('ii', $draftId, $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) draft_json_out(['status' => 'error', 'message' => 'Saved form not found.'], 404);
        $summary = draft_summary($row);
        $state = json_decode((string)$row['form_json'], true);
        $availability = draft_template_available($conn, $summary['template_id'], $summary['salary_grade'], $summary['position_title']);
        $savedTemplateTimestamp = is_array($state) ? ($state['template_updated_at'] ?? null) : null;
        draft_json_out([
            'status' => 'success',
            'form' => $summary,
            'form_json' => (string)$row['form_json'],
            'template_available' => $availability['available'],
            'template_updated_at' => $availability['updated_at'],
            'template_changed' => $savedTemplateTimestamp !== null
                && $availability['updated_at'] !== null
                && $savedTemplateTimestamp !== $availability['updated_at'],
        ]);
    }

    draft_json_out(['status' => 'error', 'message' => 'Unknown draft action.'], 400);
} catch (Throwable $exception) {
    try { $conn->rollback(); } catch (Throwable $rollbackException) {
        error_log('draft_api rollback failed: ' . $rollbackException->getMessage());
    }
    error_log('draft_api error: ' . $exception->getMessage());
    draft_json_out([
        'status' => 'error',
        'message' => DEBUG ? $exception->getMessage() : 'Draft service is temporarily unavailable.',
    ], 500);
}
