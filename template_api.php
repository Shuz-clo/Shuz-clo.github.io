<?php
ob_start();

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/AuthSchema.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/competency_template_schema.php';
require_once __DIR__ . '/AccessGroups.php';
require_once __DIR__ . '/Raters.php';

function sheets_json_out(array $payload, int $status = 200): void {
    if (ob_get_length()) ob_clean();
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

$initializationStage = 'authentication schema';
try {
    ensure_auth_schema($conn);
    $initializationStage = 'role check';
    require_role(true, ['admin', 'rater']);
    $initializationStage = 'competency-template database schema';
    ensure_competency_template_schema($conn);
} catch (Throwable $e) {
    error_log("Template API initialization failed during {$initializationStage}: " . $e->getMessage());
    $message = DEBUG
        ? $e->getMessage()
        : "Unable to initialize the sheets service during {$initializationStage}. Check the server error log; the database account may need permission to create or alter tables.";
    sheets_json_out(['status' => 'error', 'message' => $message], 500);
}

function clean_template_title($title): string {
    if (!is_string($title)) throw new InvalidArgumentException('Template name must be text.');
    $title = trim($title);
    return mb_substr($title !== '' ? $title : 'Untitled template', 0, 120);
}

function clean_template_grade_range($data): array {
    $min = $data['min_salary_grade'] ?? null;
    $max = $data['max_salary_grade'] ?? null;
    if (!is_numeric($min) || !is_numeric($max) || (int)$min != $min || (int)$max != $max) {
        throw new InvalidArgumentException('Salary-grade limits must be whole numbers.');
    }
    $min = (int)$min;
    $max = (int)$max;
    if ($min < 0 || $max > 25 || $min > $max) {
        throw new InvalidArgumentException('Choose a valid salary-grade range from 0 to 25.');
    }
    if (!isset($data['is_shared']) || !is_bool($data['is_shared'])) {
        throw new InvalidArgumentException('Choose whether this form is available to raters.');
    }
    return [$min, $max, $data['is_shared'] ? 1 : 0];
}

function clean_template_scope($data): array {
    $region = $data['region'] ?? null;
    $fieldName = $data['field_name'] ?? null;
    if ($region === '') $region = null;
    if ($fieldName === '') $fieldName = null;
    if ($region !== null && (!is_string($region) || !array_key_exists($region, ACCESS_REGIONS))) {
        throw new InvalidArgumentException('Choose a valid region scope.');
    }
    if ($fieldName !== null && !access_field_is_valid($fieldName)) {
        throw new InvalidArgumentException('Choose a valid field scope.');
    }
    return [$region, $fieldName];
}

function clean_template_layout($layout): array {
    if (!is_array($layout) || !is_array($layout['sections'] ?? null)) {
        throw new InvalidArgumentException('Invalid template layout.');
    }
    if (count($layout['sections']) > 100) {
        throw new InvalidArgumentException('A template can contain at most 100 sections.');
    }

    $labelKeys = [
        'department', 'region_line', 'board', 'sheet_title', 'candidate_name',
        'position', 'office', 'interview_date', 'candidate_salary_grade',
        'competency_template', 'rated_by', 'raters'
    ];
    $labels = $layout['labels'] ?? [];
    if (!is_array($labels)) {
        throw new InvalidArgumentException('Invalid sheet labels.');
    }
    $cleanLabels = [];
    foreach ($labelKeys as $key) {
        if (isset($labels[$key]) && !is_string($labels[$key])) {
            throw new InvalidArgumentException('Sheet labels must be text.');
        }
        if (isset($labels[$key])) {
            $cleanLabels[$key] = mb_substr($labels[$key], 0, 255);
        }
    }

    $sections = [];
    $totalRows = 0;
    foreach ($layout['sections'] as $key => $section) {
        $key = (string)$key;
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $key) || !is_array($section)) {
            throw new InvalidArgumentException('Invalid template section.');
        }
        $isCustom = ($section['custom'] ?? false) === true;
        $isStandard = ($section['standard'] ?? false) === true;
        if ($isCustom && $isStandard) {
            throw new InvalidArgumentException('A template section cannot be both standard and custom.');
        }
        if (!$isCustom && !$isStandard) continue;
        if ($isStandard && !in_array($key, ['core', 'org', 'lead', 'func'], true)) {
            throw new InvalidArgumentException('Invalid standard template section.');
        }
        foreach (['label', 'level', 'rating_label'] as $field) {
            if (isset($section[$field]) && !is_string($section[$field])) {
                throw new InvalidArgumentException('Section labels must be strings.');
            }
        }
        $rows = $section['rows'] ?? [];
        if (!is_array($rows) || count($rows) > 200) {
            throw new InvalidArgumentException('A section can contain at most 200 competencies.');
        }
        $totalRows += count($rows);
        if ($totalRows > 200) {
            throw new InvalidArgumentException('A template can contain at most 200 competencies.');
        }

        $cleanRows = [];
        $rowIds = [];
        foreach ($rows as $row) {
            if (!is_array($row)) throw new InvalidArgumentException('Invalid competency row.');
            if (isset($row['id']) && !is_string($row['id'])) {
                throw new InvalidArgumentException('Invalid competency row ID.');
            }
            $id = (string)($row['id'] ?? '');
            if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id)) {
                throw new InvalidArgumentException('Invalid competency row ID.');
            }
            if (isset($rowIds[$id])) {
                throw new InvalidArgumentException('Competency row IDs must be unique within a section.');
            }
            $rowIds[$id] = true;
            foreach (['title', 'desc'] as $field) {
                if (isset($row[$field]) && !is_string($row[$field])) {
                    throw new InvalidArgumentException('Competency text must be a string.');
                }
            }
            $cleanRows[] = [
                'id' => $id,
                'title' => mb_substr((string)($row['title'] ?? ''), 0, 255),
                'desc' => mb_substr((string)($row['desc'] ?? ''), 0, 2000),
                'is_header' => false
            ];
        }
        $cleanSection = [
            'label' => mb_substr((string)($section['label'] ?? ''), 0, 255),
            'level' => mb_substr((string)($section['level'] ?? ''), 0, 100),
            'rating_label' => mb_substr((string)($section['rating_label'] ?? 'Rating'), 0, 100),
            'rows' => $cleanRows
        ];
        $cleanSection[$isStandard ? 'standard' : 'custom'] = true;
        $sections[$key] = $cleanSection;
    }
    return ['sections' => $sections, 'labels' => $cleanLabels];
}

function clean_sheet_cells($cells): array {
    if (!is_array($cells) || count($cells) > 200) {
        throw new InvalidArgumentException('A sheet can contain at most 200 rows.');
    }

    $clean = [];
    foreach ($cells as $row) {
        if (!is_array($row) || count($row) > 50) {
            throw new InvalidArgumentException('A sheet can contain at most 50 columns.');
        }
        $cleanRow = [];
        foreach ($row as $cell) {
            if (!is_string($cell) && !is_numeric($cell)) {
                throw new InvalidArgumentException('Cell values must be text.');
            }
            $value = (string)$cell;
            if (mb_strlen($value) > 5000) {
                throw new InvalidArgumentException('Each cell is limited to 5,000 characters.');
            }
            $cleanRow[] = $value;
        }
        $clean[] = $cleanRow;
    }
    return $clean;
}

$ownerId = (int)current_user_id();
try {
    $viewer = find_user_access_profile($conn, $ownerId);
} catch (Throwable $e) {
    error_log('Template API profile lookup failed: ' . $e->getMessage());
    sheets_json_out(['status' => 'error', 'message' => 'Unable to load your account profile. Check the server error log.'], 500);
}
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    try {
    if (($_GET['action'] ?? '') === 'template_list') {
        $manageTemplates = current_role() === 'admin' && ($_GET['manage_templates'] ?? '') === '1';
        if ($manageTemplates) {
            $stmt = $conn->prepare('SELECT id, title, updated_at, min_salary_grade, max_salary_grade, region, field_name, is_shared FROM competency_templates ORDER BY min_salary_grade DESC, title ASC');
        } else {
            if (!access_salary_grade_is_valid($viewer['salary_grade'] ?? null)
                || !array_key_exists((string)($viewer['region'] ?? ''), ACCESS_REGIONS)
                || !access_field_is_valid($viewer['field_name'] ?? null)) {
                sheets_json_out(['status' => 'error', 'message' => 'Your account is missing its region, field, or salary grade. Ask an administrator to update your access profile.'], 403);
            }
            $gradeValue = (string)($_GET['salary_grade'] ?? $viewer['salary_grade']);
            if (!access_salary_grade_is_valid($gradeValue)) {
                sheets_json_out(['status' => 'error', 'message' => 'Select a salary grade to see available forms.'], 400);
            }
            $candidateGrade = (int)$gradeValue;
            $userGrade = (int)$viewer['salary_grade'];
            $region = $viewer['region'];
            $fieldName = $viewer['field_name'];
            $stmt = $conn->prepare('SELECT id, title, updated_at, min_salary_grade, max_salary_grade, region, field_name, is_shared FROM competency_templates WHERE is_shared = 1 AND min_salary_grade <= ? AND max_salary_grade >= ? AND min_salary_grade <= ? AND max_salary_grade >= ? AND (region IS NULL OR region = ?) AND (field_name IS NULL OR field_name = ?) ORDER BY min_salary_grade DESC, title ASC');
            $stmt->bind_param('iiiiss', $candidateGrade, $candidateGrade, $userGrade, $userGrade, $region, $fieldName);
        }
        $stmt->execute();
        $templates = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        foreach ($templates as &$template) $template['id'] = (int)$template['id'];
        unset($template);
        sheets_json_out(['status' => 'success', 'templates' => $templates]);
    }

    if (isset($_GET['template_id'])) {
        $id = (int)$_GET['template_id'];
        if ($id < 1) sheets_json_out(['status' => 'error', 'message' => 'Invalid template.'], 400);
        $manageTemplates = current_role() === 'admin' && ($_GET['manage_templates'] ?? '') === '1';
        if ($manageTemplates) {
            $stmt = $conn->prepare('SELECT id, title, layout_json, updated_at, min_salary_grade, max_salary_grade, region, field_name, is_shared FROM competency_templates WHERE id = ? LIMIT 1');
            $stmt->bind_param('i', $id);
        } else {
            $gradeValue = (string)($_GET['salary_grade'] ?? '');
            if (!access_salary_grade_is_valid($viewer['salary_grade'] ?? null)
                || !array_key_exists((string)($viewer['region'] ?? ''), ACCESS_REGIONS)
                || !access_field_is_valid($viewer['field_name'] ?? null)) {
                sheets_json_out(['status' => 'error', 'message' => 'Your account is missing its region, field, or salary grade. Ask an administrator to update your access profile.'], 403);
            }
            if (!access_salary_grade_is_valid($gradeValue)) {
                sheets_json_out(['status' => 'error', 'message' => 'Select a salary grade to load this form.'], 400);
            }
            $candidateGrade = (int)$gradeValue;
            $userGrade = (int)$viewer['salary_grade'];
            $region = $viewer['region'];
            $fieldName = $viewer['field_name'];
            $stmt = $conn->prepare('SELECT id, title, layout_json, updated_at, min_salary_grade, max_salary_grade, region, field_name, is_shared FROM competency_templates WHERE id = ? AND is_shared = 1 AND min_salary_grade <= ? AND max_salary_grade >= ? AND min_salary_grade <= ? AND max_salary_grade >= ? AND (region IS NULL OR region = ?) AND (field_name IS NULL OR field_name = ?) LIMIT 1');
            $stmt->bind_param('iiiiiss', $id, $candidateGrade, $candidateGrade, $userGrade, $userGrade, $region, $fieldName);
        }
        $stmt->execute();
        $template = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$template) sheets_json_out(['status' => 'error', 'message' => 'Template not found.'], 404);
        $template['id'] = (int)$template['id'];
        $template['layout'] = json_decode($template['layout_json'], true);
        unset($template['layout_json']);
        sheets_json_out(['status' => 'success', 'template' => $template]);
    }

    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $stmt = $conn->prepare('SELECT id, title, cells, updated_at FROM sheet_documents WHERE id = ? AND owner_id = ? LIMIT 1');
        $stmt->bind_param('ii', $id, $ownerId);
        $stmt->execute();
        $sheet = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$sheet) sheets_json_out(['status' => 'error', 'message' => 'Sheet not found.'], 404);
        $sheet['id'] = (int)$sheet['id'];
        $sheet['cells'] = json_decode($sheet['cells'], true) ?: [];
        sheets_json_out(['status' => 'success', 'sheet' => $sheet]);
    }

    $stmt = $conn->prepare('SELECT id, title, updated_at FROM sheet_documents WHERE owner_id = ? ORDER BY updated_at DESC, id DESC');
    $stmt->bind_param('i', $ownerId);
    $stmt->execute();
    $sheets = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($sheets as &$sheet) $sheet['id'] = (int)$sheet['id'];
    unset($sheet);
    sheets_json_out(['status' => 'success', 'sheets' => $sheets]);
    } catch (Throwable $e) {
        error_log('Template API GET failed: ' . $e->getMessage());
        sheets_json_out(['status' => 'error', 'message' => 'Unable to load sheets. Check the database schema and server error log.'], 500);
    }
}

if ($method !== 'POST') sheets_json_out(['status' => 'error', 'message' => 'Method not allowed.'], 405);

$requestBody = file_get_contents('php://input');
if (strlen($requestBody) > 2097152) {
    sheets_json_out(['status' => 'error', 'message' => 'Sheet data is too large.'], 413);
}
$data = json_decode($requestBody, true);
if (!is_array($data)) sheets_json_out(['status' => 'error', 'message' => 'Invalid request.'], 400);
if (!verify_csrf($data['csrf_token'] ?? null)) {
    sheets_json_out(['status' => 'error', 'message' => 'Your session expired. Please refresh the page.'], 403);
}

$action = $data['action'] ?? '';
if (in_array($action, ['template_create', 'template_save', 'template_delete'], true)) {
    require_admin(true);
}
if ($action === 'template_update_leadership_labels') {
    require_role(true, ['rater']);
}

try {
    if ($action === 'template_update_leadership_labels') {
        $id = (int)($data['id'] ?? 0);
        $gradeValue = (string)($data['salary_grade'] ?? '');
        $label = trim((string)($data['label'] ?? ''));
        $level = trim((string)($data['level'] ?? ''));
        if ($id < 1) sheets_json_out(['status' => 'error', 'message' => 'Invalid template.'], 400);
        if (!access_salary_grade_is_valid($gradeValue)
            || !access_salary_grade_is_valid($viewer['salary_grade'] ?? null)
            || !array_key_exists((string)($viewer['region'] ?? ''), ACCESS_REGIONS)
            || !access_field_is_valid($viewer['field_name'] ?? null)) {
            sheets_json_out(['status' => 'error', 'message' => 'Your account profile or selected salary grade is invalid.'], 403);
        }
        if ($label === '' || mb_strlen($label) > 255 || $level === '' || mb_strlen($level) > 100) {
            throw new InvalidArgumentException('Enter a Leadership heading and level label within the allowed lengths.');
        }

        $candidateGrade = (int)$gradeValue;
        if (position_is_basic_level($candidateGrade)) {
            sheets_json_out(['status' => 'error', 'message' => 'Basic-level forms do not have a Leadership section.'], 422);
        }
        $userGrade = (int)$viewer['salary_grade'];
        $region = $viewer['region'];
        $fieldName = $viewer['field_name'];
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare('SELECT layout_json FROM competency_templates WHERE id = ? AND is_shared = 1 AND min_salary_grade <= ? AND max_salary_grade >= ? AND min_salary_grade <= ? AND max_salary_grade >= ? AND (region IS NULL OR region = ?) AND (field_name IS NULL OR field_name = ?) LIMIT 1 FOR UPDATE');
            $stmt->bind_param('iiiiiss', $id, $candidateGrade, $candidateGrade, $userGrade, $userGrade, $region, $fieldName);
            $stmt->execute();
            $template = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$template) {
                $conn->rollback();
                sheets_json_out(['status' => 'error', 'message' => 'This sheet is no longer available for your profile and salary grade.'], 404);
            }

            $layout = json_decode($template['layout_json'], true);
            $layout = clean_template_layout($layout);
            if (!isset($layout['sections']['lead']) || empty($layout['sections']['lead']['standard'])) {
                throw new InvalidArgumentException('This sheet does not have an editable standard Leadership section.');
            }
            $layout['sections']['lead']['label'] = $label;
            $layout['sections']['lead']['level'] = $level;
            $layoutJson = json_encode($layout, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $stmt = $conn->prepare('UPDATE competency_templates SET layout_json = ? WHERE id = ?');
            $stmt->bind_param('si', $layoutJson, $id);
            $stmt->execute();
            $stmt->close();
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }

        sheets_json_out(['status' => 'success', 'label' => $label, 'level' => $level]);
    }

    if ($action === 'template_create') {
        $title = clean_template_title($data['title'] ?? 'Untitled template');
        $layout = json_encode(clean_template_layout($data['layout'] ?? null), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        [$minGrade, $maxGrade, $isShared] = clean_template_grade_range($data);
        [$region, $fieldName] = clean_template_scope($data);
        $stmt = $conn->prepare('INSERT INTO competency_templates (owner_id, title, layout_json, min_salary_grade, max_salary_grade, region, field_name, is_shared) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('issiissi', $ownerId, $title, $layout, $minGrade, $maxGrade, $region, $fieldName, $isShared);
        $stmt->execute();
        $id = (int)$conn->insert_id;
        $stmt->close();
        sheets_json_out(['status' => 'success', 'id' => $id, 'title' => $title]);
    }

    if ($action === 'template_save' || $action === 'template_delete') {
        $id = (int)($data['id'] ?? 0);
        if ($id < 1) sheets_json_out(['status' => 'error', 'message' => 'Invalid template.'], 400);
        if ($action === 'template_save') {
            $title = clean_template_title($data['title'] ?? 'Untitled template');
            $layout = json_encode(clean_template_layout($data['layout'] ?? null), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            [$minGrade, $maxGrade, $isShared] = clean_template_grade_range($data);
            [$region, $fieldName] = clean_template_scope($data);
            $stmt = $conn->prepare('UPDATE competency_templates SET title = ?, layout_json = ?, min_salary_grade = ?, max_salary_grade = ?, region = ?, field_name = ?, is_shared = ? WHERE id = ?');
            $stmt->bind_param('ssiissii', $title, $layout, $minGrade, $maxGrade, $region, $fieldName, $isShared, $id);
            $stmt->execute();
            $updated = $stmt->affected_rows > 0;
            $stmt->close();
            if (!$updated) {
                $check = $conn->prepare('SELECT id FROM competency_templates WHERE id = ? LIMIT 1');
                $check->bind_param('i', $id);
                $check->execute();
                $exists = $check->get_result()->num_rows > 0;
                $check->close();
                if (!$exists) sheets_json_out(['status' => 'error', 'message' => 'Template not found.'], 404);
            }
            sheets_json_out(['status' => 'success', 'title' => $title]);
        }
        $stmt = $conn->prepare('DELETE FROM competency_templates WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $deleted = $stmt->affected_rows > 0;
        $stmt->close();
        if (!$deleted) sheets_json_out(['status' => 'error', 'message' => 'Template not found.'], 404);
        sheets_json_out(['status' => 'success']);
    }

    if ($action === 'create') {
        $title = trim((string)($data['title'] ?? 'Untitled sheet'));
        $title = mb_substr($title !== '' ? $title : 'Untitled sheet', 0, 120);
        $cells = json_encode(clean_sheet_cells($data['cells'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $conn->prepare('INSERT INTO sheet_documents (owner_id, title, cells) VALUES (?, ?, ?)');
        $stmt->bind_param('iss', $ownerId, $title, $cells);
        $stmt->execute();
        $id = (int)$conn->insert_id;
        $stmt->close();
        sheets_json_out(['status' => 'success', 'id' => $id, 'title' => $title]);
    }

    $id = (int)($data['id'] ?? 0);
    if ($id < 1) sheets_json_out(['status' => 'error', 'message' => 'Invalid sheet.'], 400);

    if ($action === 'save') {
        $title = trim((string)($data['title'] ?? 'Untitled sheet'));
        $title = mb_substr($title !== '' ? $title : 'Untitled sheet', 0, 120);
        $cells = json_encode(clean_sheet_cells($data['cells'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $conn->prepare('UPDATE sheet_documents SET title = ?, cells = ? WHERE id = ? AND owner_id = ?');
        $stmt->bind_param('ssii', $title, $cells, $id, $ownerId);
        $stmt->execute();
        $updated = $stmt->affected_rows > 0;
        $stmt->close();
        if (!$updated) {
            $check = $conn->prepare('SELECT id FROM sheet_documents WHERE id = ? AND owner_id = ? LIMIT 1');
            $check->bind_param('ii', $id, $ownerId);
            $check->execute();
            $exists = $check->get_result()->num_rows > 0;
            $check->close();
            if (!$exists) sheets_json_out(['status' => 'error', 'message' => 'Sheet not found.'], 404);
        }
        sheets_json_out(['status' => 'success', 'title' => $title]);
    }

    if ($action === 'delete') {
        $stmt = $conn->prepare('DELETE FROM sheet_documents WHERE id = ? AND owner_id = ?');
        $stmt->bind_param('ii', $id, $ownerId);
        $stmt->execute();
        $deleted = $stmt->affected_rows > 0;
        $stmt->close();
        if (!$deleted) sheets_json_out(['status' => 'error', 'message' => 'Sheet not found.'], 404);
        sheets_json_out(['status' => 'success']);
    }

    sheets_json_out(['status' => 'error', 'message' => 'Unknown action.'], 400);
} catch (InvalidArgumentException $e) {
    sheets_json_out(['status' => 'error', 'message' => $e->getMessage()], 400);
} catch (Throwable $e) {
    error_log('Sheet operation failed: ' . $e->getMessage());
    sheets_json_out(['status' => 'error', 'message' => 'Unable to save this sheet right now.'], 500);
}
