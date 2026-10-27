<?php
ob_start();

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/AuthSchema.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/Raters.php';
require_once __DIR__ . '/competency_template_schema.php';
require_once __DIR__ . '/Audit.php';

require_admin(true);

function delete_competency_json(array $payload, int $status = 200): void {
    if (ob_get_length()) ob_clean();
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    delete_competency_json(['status' => 'error', 'message' => 'Invalid request.'], 405);
}
if (!verify_csrf($_POST['csrf_token'] ?? null)) {
    delete_competency_json(['status' => 'error', 'message' => 'Your session expired. Please refresh the page and try again.'], 403);
}

$sectionId = (string)($_POST['section_id'] ?? '');
$competencyId = (string)($_POST['competency_id'] ?? '');
if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $sectionId)
    || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $competencyId)) {
    delete_competency_json(['status' => 'error', 'message' => 'Invalid competency selection.'], 422);
}

try {
    ensure_schema($conn);
    ensure_sheets_schema($conn);
    ensure_competency_template_schema($conn);
    $conn->begin_transaction();

    $templatesChanged = 0;
    $templateRows = $conn->query('SELECT id, layout_json FROM competency_templates FOR UPDATE');
    while ($template = $templateRows->fetch_assoc()) {
        $layout = json_decode((string)$template['layout_json'], true);
        if (!is_array($layout) || !is_array($layout['sections'] ?? null)) {
            throw new RuntimeException('A competency template contains invalid layout data.');
        }
        $section = $layout['sections'][$sectionId] ?? null;
        if (!is_array($section) || empty($section['custom']) || !is_array($section['rows'] ?? null)) {
            continue;
        }
        $before = count($section['rows']);
        $section['rows'] = array_values(array_filter(
            $section['rows'],
            static fn($row): bool => !is_array($row) || (string)($row['id'] ?? '') !== $competencyId
        ));
        if (count($section['rows']) === $before) {
            continue;
        }
        $layout['sections'][$sectionId] = $section;
        $layoutJson = json_encode($layout, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($layoutJson === false) {
            throw new RuntimeException('Could not encode the updated competency template.');
        }
        $stmt = $conn->prepare('UPDATE competency_templates SET layout_json = ? WHERE id = ?');
        $templateId = (int)$template['id'];
        $stmt->bind_param('si', $layoutJson, $templateId);
        $stmt->execute();
        $stmt->close();
        $templatesChanged++;
    }

    $recordsChanged = 0;
    $candidateRows = $conn->query('SELECT id, custom_competencies_json FROM candidates WHERE custom_competencies_json IS NOT NULL FOR UPDATE');
    while ($candidate = $candidateRows->fetch_assoc()) {
        $rawCustom = (string)$candidate['custom_competencies_json'];
        $sections = json_decode($rawCustom, true);
        if (!is_array($sections)) {
            throw new RuntimeException('A candidate record contains invalid competency data.');
        }
        $changed = false;
        foreach ($sections as $index => $section) {
            if (!is_array($section) || (string)($section['id'] ?? '') !== $sectionId || !is_array($section['rows'] ?? null)) {
                continue;
            }
            $before = count($section['rows']);
            $section['rows'] = array_values(array_filter(
                $section['rows'],
                static fn($row): bool => !is_array($row) || (string)($row['id'] ?? '') !== $competencyId
            ));
            if (count($section['rows']) !== $before) {
                $changed = true;
                if ($section['rows'] === []) {
                    unset($sections[$index]);
                } else {
                    $sections[$index] = $section;
                }
            }
        }
        if (!$changed) {
            continue;
        }
        $customJson = json_encode(array_values($sections), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($customJson === false) {
            throw new RuntimeException('Could not encode the updated candidate competency data.');
        }
        $stmt = $conn->prepare('UPDATE candidates SET custom_competencies_json = ? WHERE id = ?');
        $candidateId = (int)$candidate['id'];
        $stmt->bind_param('si', $customJson, $candidateId);
        $stmt->execute();
        $stmt->close();
        $recordsChanged++;
    }

    if ($templatesChanged === 0 && $recordsChanged === 0) {
        $conn->rollback();
        delete_competency_json(['status' => 'error', 'message' => 'That competency was not found in templates or candidate records.'], 404);
    }

    $conn->commit();
    audit_log($conn, 0, 'delete', null, [
        'section_id' => $sectionId,
        'competency_id' => $competencyId,
        'templates_changed' => $templatesChanged,
        'candidate_records_changed' => $recordsChanged
    ]);
    delete_competency_json([
        'status' => 'success',
        'templatesChanged' => $templatesChanged,
        'candidateRecordsChanged' => $recordsChanged
    ]);
} catch (Throwable $e) {
    try { $conn->rollback(); } catch (Throwable $ignored) {}
    error_log('delete_competency error: ' . $e->getMessage());
    delete_competency_json([
        'status' => 'error',
        'message' => DEBUG ? $e->getMessage() : 'Could not delete the competency.'
    ], 500);
}
