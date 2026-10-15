<?php
// ============================================================
// save_score.php — FIXED
//
// FIX (Bug 2): office_name was copied verbatim into
//              office_location (VARCHAR(100)). Now truncated.
// FIX:         audit entries collected during the loop and
//              written via audit_log() after a successful commit.
// ============================================================

ob_start();

require_once 'db.php';
require_once __DIR__ . '/AuthSchema.php';
require_once 'auth.php';
require_admin();
require_once __DIR__ . '/Raters.php';
require_once __DIR__ . '/Audit.php';

ensure_schema($conn);

function json_out(array $payload, int $code = 200): void {
    if (ob_get_length()) ob_clean();
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || strpos($contentType, 'application/json') === false) {
    json_out(['status' => 'error', 'message' => 'Invalid request.'], 400);
}

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['candidates']) || !is_array($data['candidates'])) {
    json_out(['status' => 'error', 'message' => 'Invalid data payload.']);
}

if (!verify_csrf($data['csrf_token'] ?? null)) {
    json_out(['status' => 'error', 'message' => 'Your session expired. Please refresh the page.'], 403);
}

function cleanScore($v): ?float {
    if ($v === null || $v === '' || !is_numeric($v)) return null;
    return max(0.0, min(10.0, (float)$v));
}

try {
    $conn->begin_transaction();

    $stmtUpdate = $conn->prepare("UPDATE candidates SET
        candidate_name = ?, office_name = ?, office_location = ?, position_title = ?, interview_date = ?, remarks = ?
        WHERE id = ?");

    $stmtInsert = $conn->prepare("INSERT INTO candidates
        (candidate_name, office_name, office_location, position_title, interview_date, remarks)
        VALUES (?, ?, ?, ?, ?, ?)");

    $stmtDeleteScores = $conn->prepare("DELETE FROM candidate_scores WHERE candidate_id = ?");
    $stmtUpsertScore  = $conn->prepare("INSERT INTO candidate_scores (candidate_id, rater, psycho, potential)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE psycho = VALUES(psycho), potential = VALUES(potential)");

    $savedIds        = [];
    $orderByPosition = [];
    $auditEntries    = [];

    foreach ($data['candidates'] as $c) {
        $id        = (int)($c['id'] ?? 0);
        $tempIndex = (int)($c['tempIndex'] ?? 0);
        $name      = mb_substr(trim($c['name'] ?? ''), 0, 255);
        $office    = mb_substr(trim($c['office'] ?? 'General Branch'), 0, 255);

        // FIX (Bug 2): truncate for the office_location column
        $officeLoc = mb_substr($office, 0, 100);

        $position  = mb_substr(trim($c['position'] ?? ''), 0, 255);
        if ($position === '') $position = 'UNASSIGNED';
        $position  = canonical_position_title($position);

        $remarks   = mb_substr(trim($c['remarks'] ?? ''), 0, 2000);

        $interviewDate = mb_substr(trim($c['interviewDate'] ?? ''), 0, 255);
        if ($interviewDate === '') $interviewDate = date('F d, Y');

        $scores = is_array($c['scores'] ?? null) ? $c['scores'] : [];

        $cleanScores = [];
        $orderKey = $office . "\0" . $position;
        foreach ($scores as $rater => $pt) {
            $rater = trim((string)$rater);
            if ($rater === '') continue;
            $p = cleanScore($pt['p'] ?? null);
            $t = cleanScore($pt['t'] ?? null);
            if ($p === null && $t === null) continue;
            $cleanScores[$rater] = ['p' => $p, 't' => $t];
            if (!isset($orderByPosition[$orderKey])) $orderByPosition[$orderKey] = [];
            if (!in_array($rater, $orderByPosition[$orderKey], true)) $orderByPosition[$orderKey][] = $rater;
        }

        if ($id > 0) {
            $stmtUpdate->bind_param("ssssssi", $name, $office, $officeLoc, $position, $interviewDate, $remarks, $id);
            $stmtUpdate->execute();
            $candidateId = $id;
            $savedIds[] = ['tempIndex' => $tempIndex, 'id' => $id];
            $action = 'edit';
        } elseif ($name !== '') {
            $stmtInsert->bind_param("ssssss", $name, $office, $officeLoc, $position, $interviewDate, $remarks);
            $stmtInsert->execute();
            $candidateId = $conn->insert_id;
            $savedIds[] = ['tempIndex' => $tempIndex, 'id' => $candidateId];
            $action = 'submit';
        } else {
            continue;
        }

        $stmtDeleteScores->bind_param("i", $candidateId);
        $stmtDeleteScores->execute();

        foreach ($cleanScores as $rater => $pt) {
            $stmtUpsertScore->bind_param("isdd", $candidateId, $rater, $pt['p'], $pt['t']);
            $stmtUpsertScore->execute();
        }

        $auditEntries[] = [
            'candidate_id' => $candidateId,
            'action'       => $action,
            'new'          => [
                'candidate_name' => $name,
                'position_title' => $position,
                'office_name'    => $office,
                'raters'         => array_keys($cleanScores)
            ]
        ];
    }

    $deletedPositions = 0;
    if (isset($data['positionsToDelete']) && is_array($data['positionsToDelete'])) {
        $stmtDelScores = $conn->prepare("DELETE cs FROM candidate_scores cs
            JOIN candidates c ON c.id = cs.candidate_id
            WHERE UPPER(TRIM(c.office_name)) = UPPER(?) AND TRIM(c.position_title) = ?");
        $stmtDelCands = $conn->prepare("DELETE FROM candidates
            WHERE UPPER(TRIM(office_name)) = UPPER(?) AND TRIM(position_title) = ?");
        $stmtDelOrder = $conn->prepare("DELETE FROM position_rater_order
            WHERE office = ? AND position = ?");

        foreach ($data['positionsToDelete'] as $entry) {
            if (!is_array($entry)) continue;
            $off = strtoupper(mb_substr(trim((string)($entry['office'] ?? '')), 0, 100));
            $pos = canonical_position_title(mb_substr(trim((string)($entry['position'] ?? '')), 0, 255));
            if ($off === '' || $pos === '' || $pos === 'UNASSIGNED') continue;

            $stmtDelScores->bind_param('ss', $off, $pos);
            $stmtDelScores->execute();

            $stmtDelCands->bind_param('ss', $off, $pos);
            $stmtDelCands->execute();
            $deletedPositions += $stmtDelCands->affected_rows;

            $stmtDelOrder->bind_param('ss', $off, $pos);
            $stmtDelOrder->execute();

            $auditEntries[] = [
                'candidate_id' => 0,
                'action'       => 'delete',
                'new'          => ['office' => $off, 'position' => $pos]
            ];
        }
        $stmtDelScores->close();
        $stmtDelCands->close();
        $stmtDelOrder->close();
    }

    $savedColumns = 0;
    if (isset($data['columns']) && is_array($data['columns'])) {
        $mergedCols = [];
        foreach ($data['columns'] as $col) {
            if (!is_array($col)) continue;
            $colOffice   = strtoupper(mb_substr(trim((string)($col['office'] ?? '')), 0, 100));
            $colPosition = mb_substr(trim((string)($col['position'] ?? '')), 0, 255);
            if ($colPosition === '') $colPosition = 'UNASSIGNED';
            $colPosition = canonical_position_title($colPosition);
            if ($colOffice === '' || !is_array($col['raters'] ?? null)) continue;

            $k = $colOffice . "\0" . $colPosition;
            if (!isset($mergedCols[$k])) $mergedCols[$k] = [];
            foreach (array_slice($col['raters'], 0, 50) as $r) {
                $r = mb_substr(trim((string)$r), 0, 30);
                if ($r !== '' && !in_array($r, $mergedCols[$k], true)) $mergedCols[$k][] = $r;
            }
        }

        foreach ($mergedCols as $k => $raters) {
            [$colOffice, $colPosition] = explode("\0", $k, 2);

            if (count($raters) > 0) {
                $marks = implode(',', array_fill(0, count($raters), '?'));
                $stmtDel = $conn->prepare("DELETE FROM position_rater_order WHERE office = ? AND position = ? AND rater NOT IN ($marks)");
                $stmtDel->bind_param(str_repeat('s', 2 + count($raters)), $colOffice, $colPosition, ...$raters);
            } else {
                $stmtDel = $conn->prepare("DELETE FROM position_rater_order WHERE office = ? AND position = ?");
                $stmtDel->bind_param('ss', $colOffice, $colPosition);
            }
            $stmtDel->execute();
            $stmtDel->close();

            record_rater_order($conn, $colOffice, $colPosition, $raters);
            $savedColumns += count($raters);
        }
    }

    $tabList = [];
    if (isset($data['tabs']) && is_array($data['tabs'])) {
        foreach (array_slice($data['tabs'], 0, 200) as $t) {
            $t = strtoupper(mb_substr(trim((string)$t), 0, 100));
            if ($t !== '' && !in_array($t, $tabList, true)) $tabList[] = $t;
        }
        if (count($tabList) > 0) {
            $marks = implode(',', array_fill(0, count($tabList), '?'));
            $stmtTabs = $conn->prepare("DELETE FROM position_rater_order WHERE office NOT IN ($marks)");
            $stmtTabs->bind_param(str_repeat('s', count($tabList)), ...$tabList);
            $stmtTabs->execute();
            $stmtTabs->close();

            try {
                $stmtShow = $conn->prepare("DELETE FROM removed_tabs WHERE office IN ($marks)");
                $stmtShow->bind_param(str_repeat('s', count($tabList)), ...$tabList);
                $stmtShow->execute();
                $stmtShow->close();
            } catch (Exception $e) {
                error_log('save_score removed_tabs un-hide error: ' . $e->getMessage());
            }
        }
    }

    if (isset($data['hiddenTabs']) && is_array($data['hiddenTabs'])) {
        try {
            $stmtHide = $conn->prepare("INSERT INTO removed_tabs (office) VALUES (?)
                ON DUPLICATE KEY UPDATE removed_at = CURRENT_TIMESTAMP");
            foreach (array_slice($data['hiddenTabs'], 0, 200) as $t) {
                $t = strtoupper(mb_substr(trim((string)$t), 0, 100));
                if ($t === '' || in_array($t, $tabList, true)) continue;
                $stmtHide->bind_param('s', $t);
                $stmtHide->execute();
            }
            $stmtHide->close();
        } catch (Exception $e) {
            error_log('save_score removed_tabs hide error: ' . $e->getMessage());
        }
    }

    foreach ($orderByPosition as $key => $ratersInOrder) {
        [$office, $position] = explode("\0", $key, 2);
        record_rater_order($conn, $office, $position, $ratersInOrder);
    }

    if (isset($data['headers']) && is_array($data['headers'])) {
        $h = $data['headers'];
        $get = function($key, $default = '') use ($h) {
            return mb_substr(trim($h[$key] ?? $default), 0, 100);
        };

        $hash            = $get('hash', '#');
        $name            = $get('name', 'Name');
        $psychoGroup     = $get('psychoGroup', 'PSYCHO-SOCIAL');
        $potentialGroup  = $get('potentialGroup', 'POTENTIAL');
        $core            = $get('core', 'CORE');
        $org             = $get('org', 'ORG');
        $pe1             = $get('psychoExtra1');
        $pe2             = $get('psychoExtra2');
        $pe3             = $get('psychoExtra3');
        $pe4             = $get('psychoExtra4');
        $pAve            = $get('psychoAve', 'AVE.');
        $func            = $get('func', 'FUNC');
        $lead            = $get('lead', 'LEAD');
        $te1             = $get('potentialExtra1');
        $te2             = $get('potentialExtra2');
        $te3             = $get('potentialExtra3');
        $te4             = $get('potentialExtra4');
        $tAve            = $get('potentialAve', 'AVE.');
        $remarksHdr      = $get('remarks', 'REMARKS');

        $stmtHdr = $conn->prepare("INSERT INTO sheet_settings
            (id, hdr_hash, hdr_name, hdr_psycho_group, hdr_potential_group,
             hdr_core, hdr_org, hdr_psycho_extra1, hdr_psycho_extra2, hdr_psycho_extra3, hdr_psycho_extra4, hdr_psycho_ave,
             hdr_func, hdr_lead, hdr_potential_extra1, hdr_potential_extra2, hdr_potential_extra3, hdr_potential_extra4, hdr_potential_ave,
             hdr_remarks)
            VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                hdr_hash=VALUES(hdr_hash), hdr_name=VALUES(hdr_name),
                hdr_psycho_group=VALUES(hdr_psycho_group), hdr_potential_group=VALUES(hdr_potential_group),
                hdr_core=VALUES(hdr_core), hdr_org=VALUES(hdr_org),
                hdr_psycho_extra1=VALUES(hdr_psycho_extra1), hdr_psycho_extra2=VALUES(hdr_psycho_extra2),
                hdr_psycho_extra3=VALUES(hdr_psycho_extra3), hdr_psycho_extra4=VALUES(hdr_psycho_extra4),
                hdr_psycho_ave=VALUES(hdr_psycho_ave),
                hdr_func=VALUES(hdr_func), hdr_lead=VALUES(hdr_lead),
                hdr_potential_extra1=VALUES(hdr_potential_extra1), hdr_potential_extra2=VALUES(hdr_potential_extra2),
                hdr_potential_extra3=VALUES(hdr_potential_extra3), hdr_potential_extra4=VALUES(hdr_potential_extra4),
                hdr_potential_ave=VALUES(hdr_potential_ave),
                hdr_remarks=VALUES(hdr_remarks)");

        $stmtHdr->bind_param(
            str_repeat('s', 19),
            $hash, $name, $psychoGroup, $potentialGroup,
            $core, $org, $pe1, $pe2, $pe3, $pe4, $pAve,
            $func, $lead, $te1, $te2, $te3, $te4, $tAve,
            $remarksHdr
        );
        $stmtHdr->execute();
    }

    $conn->commit();

    // FIX: audit after commit — one entry per touched candidate/position
    foreach ($auditEntries as $entry) {
        audit_log($conn, $entry['candidate_id'], $entry['action'], null, $entry['new']);
    }

    json_out([
        'status'           => 'success',
        'savedIds'         => $savedIds,
        'savedColumns'     => $savedColumns,
        'deletedPositions' => $deletedPositions
    ]);
} catch (Exception $e) {
    $conn->rollback();
    error_log('save_score error: ' . $e->getMessage());
    json_out(['status' => 'error', 'message' => DEBUG ? $e->getMessage() : 'Could not save changes.'], 500);
}
exit;
?>