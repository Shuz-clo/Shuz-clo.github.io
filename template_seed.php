<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/AuthSchema.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/PositionsSchema.php';

require_admin(true);


require_once __DIR__ . '/sheets_schema.php';
require_once __DIR__ . '/competency_template_schema.php';

$message = '';
$messageType = '';
if (($seedMessage = flash_get('success')) !== null) {
    $message = $seedMessage;
    $messageType = 'success';
} elseif (($seedMessage = flash_get('error')) !== null) {
    $message = $seedMessage;
    $messageType = 'error';
}
$generationSummary = null;
$functionalSummary = null;
$functionalErrors = [];
$positions = [];
$generatedTemplates = [];
$csrfToken = csrf_token();

function template_seed_positions(mysqli $conn): array {
    $tableName = 'positions';
    $check = $conn->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
    $check->bind_param('s', $tableName);
    $check->execute();
    $exists = $check->get_result()->num_rows > 0;
    $check->close();
    if (!$exists) {
        throw new RuntimeException('Required positions table is missing. Create and seed it before generating templates.');
    }

    $scope = 'REGIONAL_OFFICE';
    $stmt = $conn->prepare('SELECT title, salary_grade, leadership_level FROM positions WHERE office_scope = ? ORDER BY salary_grade DESC, title ASC');
    $stmt->bind_param('s', $scope);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function template_seed_copy_rows(array $section): array {
    $rows = [];
    foreach (($section['rows'] ?? []) as $row) {
        $rows[] = [
            'id' => (string)$row['id'],
            'title' => (string)$row['title'],
            'desc' => (string)$row['desc'],
        ];
    }
    return $rows;
}

function template_seed_functional_rows(string $positionTitle): array {
    $functionalSets = [
        'ras' => [
            ['RAS1', 'PROCUREMENT MANAGEMENT', 'Assists in the conduct of procurement activities'],
            ['RAS2', 'PROPERTY MANAGEMENT (PROPERTY INVENTORY AND DISPOSAL MANAGEMENT)', 'Assists in the conduct of property management activities'],
            ['RAS3', 'RECORDS MANAGEMENT', 'Assists in records management activities'],
            ['RAS4', 'COMPUTERIZED RECORDS MANAGEMENT', 'Assists in computerized records management'],
            ['RAS5', 'COURIER, POSTAL AND MESSENGERIAL SERVICES', 'Performs routine courier, postal and messengerial services'],
            ['RAS6', 'CLERICAL/SECRETARIAL/EXECUTIVE ASSISTANCE SKILLS', 'Undertakes routine clerical and secretarial functions'],
            ['RAS7', 'BUILDING MAINTENANCE AND ADMINISTRATION', 'Performs basic maintenance activities of buildings and other facilities'],
            ['RAS8', 'REPAIR AND FABRICATION', 'Performs minor repairs of equipment, electrical works, plumbing and airconditioning and simple fabrication of equipment, furniture and fixtures.'],
        ],
        'rfm' => [
            ['RFM1', 'GENERAL ACCOUNTING', "Records, classifies and summarizes DENR's financial transactions"],
            ['RFM2', 'BUDGET PREPARATION', "Assists in budget preparation activities to determine the DENR's financial requirement"],
            ['RFM3', 'BUDGET ADMINISTRATION AND CONTROL', 'Assists in the preparation of budget allocation of departments/divisions'],
            ['RFM4', 'ORGANIZATION AND MANAGEMENT SYSTEMS IMPROVEMENT', 'Assists in the conduct of organization and management systems improvement activities'],
            ['RFM5', 'CASH MANAGEMENT', 'Performs cash management activities'],
        ],
        'planning' => [
            ['RP1', 'PLANNING AND PROGRAMMING', 'Assists in the conduct of planning and programming activities'],
            ['RP2', 'MONITORING AND EVALUATION', 'Assists in the monitoring and evaluation of DENR-RO programs and projects'],
            ['RP3', 'STATISTICAL ANALYSIS, DATA MANAGEMENT AND PRODUCTION OF KNOWLEDGE PRODUCTS', 'Assists in the conduct of statistical analysis, data management and production of knowledge products'],
            ['RP4', 'ORGANIZATION AND MANAGEMENT SYSTEMS IMPROVEMENT', 'Assists in the conduct of organization and management systems improvement activities'],
        ],
        'information_systems' => [
            ['RIS1', 'STATISTICAL ANALYSIS, CONVERSION OF STATISTICAL DATA TO SPATIAL DATA AND CONVERSION TO KNOWLEDGE PRODUCTS', 'Assists in the conduct of data management, conversion to spatial data, spatial analysis, and production of knowledge products'],
            ['RIS2', 'SOFTWARE DEVELOPMENT', 'Develops simple software programs'],
            ['RIS3', 'NETWORK INFRASTRUCTURE MANAGEMENT', 'Undertakes basic network infrastructure troubleshooting and maintenance'],
            ['RIS4', 'SYSTEMS ANALYSIS AND DESIGN', 'Provides support or assists in system analysis, design, planning, implementation, and maintenance activities'],
            ['RIS5', 'WEB DEVELOPMENT', 'Provides support or assists in web development, design, planning, and maintenance activities'],
            ['RIS6', 'INFORMATION AND COMMUNICATION TECHNOLOGIES (ICT) RESOURCE MANAGEMENT', 'Performs basic computer hardware, software and network configuration and maintenance'],
        ],
        'hr' => [
            ['RHR6', 'LEARNING NEEDS ASSESSMENT (LNA)', 'Assists in the conduct of LNA activities'],
            ['RHR7', 'PREPARATION OF LEARNING DESIGN', 'Assists in the preparation of learning design'],
            ['RHR8', 'LEARNING EVENT MANAGEMENT', 'Assists in the conduct of learning program management activities'],
            ['RHR9', 'LEARNING EVENT FACILITATION', 'Assists in facilitating learning events'],
            ['RHR10', 'NETWORK AND LINKAGING WITH HR PARTNERS', 'Communicates and coordinates with stakeholders/partners'],
            ['RHR11', 'MONITORING AND EVALUATION (M&E) OF LEARNING AND DEVELOPMENT (L&D) PROGRAMS', 'Assists in the conduct of monitoring and development M&E activities'],
            ['RHR12', 'SCHOLARSHIP ADMINISTRATION', 'Assists in the implementation of scholarship administration activities'],
            ['RHR13', 'HR PLANNING', 'Assists in HR planning activities'],
            ['RHR14', 'CAREER DEVELOPMENT', 'Assists in career development activities'],
        ],
        'legal' => [
            ['RLS1', 'LEGAL NOTE TAKING', 'Acts as support staff in the conduct of legal proceedings'],
            ['RLS2', 'LEGAL RECORDS MANAGEMENT', 'Assists in records management activities'],
            ['RLS3', 'COMPUTERIZED RECORDS MANAGEMENT', 'Assists in computerized records management'],
            ['RLS4', 'CLERICAL/SECRETARIAL/EXECUTIVE ASSISTANCE SKILLS', 'Assists in discharging complete staff work'],
        ],
        'conservation' => [
            ['RO1', 'CONCEPT AND APPLICATION OF INTEGRATED ECOSYSTEMS MANAGEMENT (IEM)', 'Assists in the application of IEM and other related approaches to NRM'],
            ['RO2', 'IDENTIFICATION OF INTERVENTIONS AND INTEGRATING STRATEGIES ACROSS SECTORS AND ZONING FOR STRATEGIC MANAGEMENT', 'Assists in the identification of interventions and integrating strategies across sectors'],
            ['RO3', 'CHARACTERIZATION OF ECOSYSTEM AND USE OF PLANNING TOOLS AND PROCEDURES', 'Assists in the characterization of ecosystem and use of planning tools and procedures'],
            ['RO4', 'RESOURCE MANAGEMENT AND RESTORATION/REHABILITATION OF DEGRADED ECOSYSTEMS', 'Assists in identifying appropriate resource management and restoration strategies of various ecosystems'],
            ['RO5', 'PREPARATION OF NATURAL RESOURCES MANAGEMENT (NRM)-RELATED PLANS (FLUP, CRMP, ISWMP, PAMP, IRBM, IWRM)', 'Assists in the preparation of NRM-related plans (FLUP, CRMP, ISWMP, PAMP, IRBM, IWRM)'],
            ['RO9', 'ENVIRONMENTAL GOVERNANCE', 'Assists in environmental governance activities that include transparency, accountability, participation, collaboration and functionality'],
            ['RO10', 'CLIMATE CHANGE AND ENVIRONMENTAL MANAGEMENT', 'Assists in applying mitigating measures and adaptive solutions to climate change'],
        ],
        'survey' => [
            ['RO15', 'GEOGRAPHIC INFORMATION SYSTEM (GIS)', 'Knows basic knowledge in GIS and other related software'],
            ['RO16', 'SURVEYING', 'Assists in land surveying activities'],
            ['RO17', 'SURVEY VERIFICATION', 'Assists in survey verification activities'],
            ['RO18', 'MAPPING', 'Assists in land mapping activities'],
            ['RO19', 'LAND MANAGEMENT INFORMATION SYSTEM ADMINISTRATION', 'Knows basic knowledge in LAMS and other related software'],
            ['RO20', 'LAND RECORDS MANAGEMENT', 'Assists in land records management activities'],
        ],
        'land_management' => [
            ['RO21', 'LAND DISPOSITION AND MANAGEMENT', 'Assists in the disposition of A&D land and management of the government lands'],
            ['RO22', 'FOREST, WATER AND WILDLIFE RESOURCES REGULATION', 'Assists in the forest, water and wildlife resource utilization and regulation activities'],
            ['RO23', 'TENURE AND RIGHTS ASSESSMENT', 'Assists in the evaluation of effectiveness of tenure holders based on organizational, stewardship, protection and management capabilities'],
            ['RO24', 'TENURIAL INSTRUMENTS AND PERMITS FOR IMPROVED RESOURCE MANAGEMENT', 'Assists in the preparation of tenurial instruments and permits for improved resource management'],
        ],
        'enforcement' => [
            ['RO14', 'ENR LAW ENFORCEMENT', 'Assists in ENR law enforcement activities'],
        ],
    ];

    $positionSets = [
        'ADMINISTRATIVE ASSISTANT I' => ['planning', 'information_systems', 'rfm', 'hr', 'ras', 'legal', 'survey', 'land_management'],
        'ADMINISTRATIVE ASSISTANT II' => ['ras', 'rfm', 'hr'],
        'ADMINISTRATIVE ASSISTANT III' => ['ras', 'planning', 'information_systems', 'rfm'],
        'ADMINISTRATIVE ASSISTANT I COMPUTER OPERATOR I' => ['rfm', 'legal'],
        'ADMINISTRATIVE ASSISTANT II BUDGETING ASST' => ['rfm'],
        'ADMINISTRATIVE ASSISTANT II SENIOR BOOKKEEPER' => ['rfm'],
        'ADMINISTRATIVE ASSISTANT II BOOKKEEPER' => ['rfm'],
        'ADMINISTRATIVE ASSISTANT III COMPUTER OPERATOR II' => ['planning', 'information_systems'],
        'ADMINISTRATIVE ASSISTANT III SENIOR BOOKKEEPER' => ['rfm'],
        'ADMINISTRATIVE ASSISTANT II COMPUTER OPERATOR I' => ['ras', 'rfm'],
        'ADMINISTRATIVE ASSISTANT I COMPUTER OPERATOR III' => ['planning', 'information_systems'],
        'ADMINISTRATIVE AIDE VI' => ['ras', 'legal', 'conservation', 'survey', 'land_management', 'enforcement'],
        'CREDIT OFFICER I' => ['rfm'],
        'ZOOLOGY TECHNICIAN' => ['conservation'],
        'DRAFTSMAN I' => ['conservation'],
        'DRAFTSMAN II' => ['land_management'],
        'CARTOGRAPHER I' => ['survey', 'land_management'],
        'ENGINEERING AIDE' => ['survey'],
        'MATHEMATICIAN AIDE I' => ['survey'],
        'MATHEMATICIAN AIDE II' => ['survey'],
        'TRACER' => ['survey', 'land_management'],
    ];

    $normalizedTitle = strtoupper(trim((string)preg_replace('/[^A-Z0-9]+/i', ' ', $positionTitle)));
    $setNames = $positionSets[$normalizedTitle] ?? null;
    if ($setNames === null) {
        $baseTitle = strtoupper(trim((string)preg_replace('/[^A-Z0-9]+/i', ' ', preg_replace('/\([^)]*\)/', '', $positionTitle))));
        $setNames = $positionSets[$baseTitle] ?? null;
    }
    if ($setNames === null) return [];

    $rows = [];
    $seen = [];
    foreach ($setNames as $setName) {
        foreach ($functionalSets[$setName] as [$code, $title, $description]) {
            if (isset($seen[$code])) continue;
            $seen[$code] = true;
            $rows[] = [
                'id' => 'f' . (count($rows) + 1),
                'title' => $code . ' - ' . $title,
                'desc' => $description,
            ];
        }
    }
    return $rows;
}

function template_seed_layout(array $position, array $defaultLayout): array {
    $sections = [
        'core' => [
            'standard' => true,
            'label' => 'Core Competencies',
            'level' => 'Evidence',
            'rows' => template_seed_copy_rows($defaultLayout['sections']['core']),
        ],
        'org' => [
            'standard' => true,
            'label' => 'Organizational Competencies',
            'level' => 'Superior',
            'rows' => template_seed_copy_rows($defaultLayout['sections']['org']),
        ],
    ];

    $leadershipLevel = strtoupper(trim((string)($position['leadership_level'] ?? '')));
    if ($leadershipLevel === 'B' || $leadershipLevel === 'I') {
        $sections['lead'] = [
            'standard' => true,
            'label' => 'Leadership Competencies',
            'level' => $leadershipLevel === 'B' ? 'Basic' : 'Advanced',
            'rows' => template_seed_copy_rows($defaultLayout['sections']['lead']),
        ];
    }

    $sections['func'] = [
        'custom' => true,
        'label' => 'Functional Competencies',
        'level' => 'Superior',
        'rows' => template_seed_copy_rows($defaultLayout['sections']['func']),
    ];

    return ['sections' => $sections];
}

function template_seed_parse_functional_rows(string $text): array {
    $rows = [];
    $errors = [];
    foreach (preg_split('/\r\n|\n|\r/', $text) as $index => $line) {
        $lineNumber = $index + 1;
        if (trim($line) === '') continue;
        if (str_contains($line, "\t")) {
            $fields = explode("\t", $line, 2);
        } else {
            $fields = str_getcsv($line);
            if (count($fields) > 2) {
                $fields = [$fields[0], implode(',', array_slice($fields, 1))];
            }
        }
        if (count($fields) !== 2) {
            $errors[] = ['line' => $lineNumber, 'message' => 'Expected a title and description separated by a tab or comma.'];
            continue;
        }
        $title = trim((string)$fields[0]);
        $description = trim((string)$fields[1]);
        if ($title === '' || $description === '' || mb_strlen($title) > 255 || mb_strlen($description) > 2000) {
            $errors[] = ['line' => $lineNumber, 'message' => 'Title and description are required (maximum 255 and 2,000 characters).'];
            continue;
        }
        $rows[] = ['title' => $title, 'desc' => $description];
    }
    return [$rows, $errors];
}

try {
    $positions = template_seed_positions($conn);
    $defaultLayout = default_sheets_layout();

    $listStmt = $conn->prepare("SELECT id, title, min_salary_grade FROM competency_templates
        WHERE title LIKE 'SG % - %' AND min_salary_grade = max_salary_grade
        ORDER BY min_salary_grade DESC, title ASC");
    $listStmt->execute();
    $generatedTemplates = $listStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $listStmt->close();

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if (!verify_csrf(isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : null)) {
            http_response_code(403);
            $message = 'Your session expired. Please reload the page and try again.';
            $messageType = 'error';
        } else {
            $action = (string)($_POST['action'] ?? '');
            if ($action === 'seed_positions') {
                if ($positions) {
                    http_response_code(409);
                    $message = 'Positions already exist; position seeding was not run.';
                    $messageType = 'error';
                } else {
                    $resetStmt = $conn->prepare('UPDATE positions_seed_state_v2 SET seed_version = 0 WHERE id = 1');
                    $resetStmt->execute();
                    $resetStmt->close();
                    ensure_positions_schema($conn);
                    $positions = template_seed_positions($conn);
                    if (!$positions) {
                        throw new RuntimeException('Position seeding completed but no Regional Office positions were found.');
                    }
                    flash_set('success', count($positions) . ' Regional Office positions are now available.');
                    header('Location: template_seed.php');
                    exit;
                }
            } elseif ($action === 'generate') {
                $shared = isset($_POST['share_generated']) && $_POST['share_generated'] === '1';
                $ownerId = (int)current_user_id();
                $insertStmt = $conn->prepare('INSERT INTO competency_templates (owner_id, title, layout_json, min_salary_grade, max_salary_grade, region, field_name, is_shared) VALUES (?, ?, ?, ?, ?, NULL, NULL, ?)');
                $existsStmt = $conn->prepare('SELECT id, layout_json FROM competency_templates WHERE owner_id = ? AND title = ? LIMIT 1');
                $updateStmt = $conn->prepare('UPDATE competency_templates SET layout_json = ? WHERE id = ? AND owner_id = ?');
                $shareStmt = $conn->prepare('UPDATE competency_templates SET is_shared = 1 WHERE owner_id = ? AND title = ?');
                $created = 0;
                $updated = 0;
                $skipped = 0;
                $sharedCount = 0;
                $conn->begin_transaction();
                try {
                    foreach ($positions as $position) {
                        $sg = (int)$position['salary_grade'];
                        $title = 'SG ' . $sg . ' - ' . (string)$position['title'];
                        $existsStmt->bind_param('is', $ownerId, $title);
                        $existsStmt->execute();
                        $existingTemplate = $existsStmt->get_result()->fetch_assoc();
                        if ($existingTemplate) {
                            if ($shared) {
                                $shareStmt->bind_param('is', $ownerId, $title);
                                $shareStmt->execute();
                                $sharedCount += $shareStmt->affected_rows > 0 ? 1 : 0;
                            }
                            $functionalRows = template_seed_copy_rows($defaultLayout['sections']['func']);
                            $existingLayout = json_decode((string)$existingTemplate['layout_json'], true);
                            $existingFunctionalRows = $existingLayout['sections']['func']['rows'] ?? null;
                            $isBlankPlaceholder = is_array($existingFunctionalRows)
                                && count($existingFunctionalRows) === 1
                                && (string)($existingFunctionalRows[0]['id'] ?? '') === 'f1'
                                && trim((string)($existingFunctionalRows[0]['title'] ?? '')) === ''
                                && trim((string)($existingFunctionalRows[0]['desc'] ?? '')) === '';
                            if ($functionalRows && $isBlankPlaceholder && is_array($existingLayout['sections']['func'] ?? null)) {
                                $existingLayout['sections']['func']['rows'] = $functionalRows;
                                $existingLayout['sections']['func']['custom'] = true;
                                $existingLayout['sections']['func']['level'] = 'Superior';
                                unset($existingLayout['sections']['func']['standard']);
                                $updatedLayoutJson = json_encode($existingLayout, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                                if ($updatedLayoutJson === false) {
                                    throw new RuntimeException('Unable to encode the updated functional competencies.');
                                }
                                $templateId = (int)$existingTemplate['id'];
                                $updateStmt->bind_param('sii', $updatedLayoutJson, $templateId, $ownerId);
                                $updateStmt->execute();
                                $updated++;
                                continue;
                            }
                            $skipped++;
                            continue;
                        }

                        $layout = json_encode(
                            template_seed_layout($position, $defaultLayout),
                            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                        );
                        $insertStmt->bind_param('issiii', $ownerId, $title, $layout, $sg, $sg, $shared);
                        $insertStmt->execute();
                        $created++;
                        if ($shared) $sharedCount++;
                    }
                    $conn->commit();
                    $generationSummary = ['created' => $created, 'updated' => $updated, 'skipped' => $skipped, 'shared' => $sharedCount];
                    $message = "Generation complete: {$created} created, {$updated} functional sections updated, {$skipped} skipped, {$sharedCount} shared with raters.";
                    $messageType = 'success';
                } catch (Throwable $e) {
                    $conn->rollback();
                    throw $e;
                } finally {
                    $insertStmt->close();
                    $existsStmt->close();
                    $updateStmt->close();
                    $shareStmt->close();
                }
                $listStmt = $conn->prepare("SELECT id, title, min_salary_grade FROM competency_templates
                    WHERE title LIKE 'SG % - %' AND min_salary_grade = max_salary_grade
                    ORDER BY min_salary_grade DESC, title ASC");
                $listStmt->execute();
                $generatedTemplates = $listStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $listStmt->close();
            } elseif ($action === 'import_functional') {
                $templateId = filter_var($_POST['template_id'] ?? null, FILTER_VALIDATE_INT);
                $append = ($_POST['import_mode'] ?? 'replace') === 'append';
                [$rowsToImport, $functionalErrors] = template_seed_parse_functional_rows((string)($_POST['functional_csv'] ?? ''));
                if ($templateId === false || $templateId < 1) {
                    http_response_code(422);
                    $message = 'Select a generated template.';
                    $messageType = 'error';
                } elseif (!$rowsToImport) {
                    http_response_code(422);
                    $message = 'No valid functional competency rows were provided.';
                    $messageType = 'error';
                } else {
                    $conn->begin_transaction();
                    try {
                        $templateStmt = $conn->prepare("SELECT layout_json FROM competency_templates
                            WHERE id = ? AND title LIKE 'SG % - %' AND min_salary_grade = max_salary_grade
                            LIMIT 1 FOR UPDATE");
                        $templateStmt->bind_param('i', $templateId);
                        $templateStmt->execute();
                        $templateRow = $templateStmt->get_result()->fetch_assoc();
                        $templateStmt->close();
                        if (!$templateRow) {
                            $conn->rollback();
                            http_response_code(404);
                            $message = 'Generated template not found.';
                            $messageType = 'error';
                        } else {
                            $layout = json_decode((string)$templateRow['layout_json'], true);
                            if (!is_array($layout) || !is_array($layout['sections'] ?? null)) {
                                throw new RuntimeException('Template layout data is invalid.');
                            }
                            if (!isset($layout['sections']['func']) || !is_array($layout['sections']['func'])) {
                                $layout['sections']['func'] = [
                                    'label' => 'Functional Competencies',
                                    'level' => 'Advanced',
                                    'rows' => [],
                                ];
                            }
                            $functionalSection = &$layout['sections']['func'];
                            $existingRows = is_array($functionalSection['rows'] ?? null) ? $functionalSection['rows'] : [];
                            $nextNumber = 1;
                            if ($append) {
                                foreach ($existingRows as $existingRow) {
                                    if (preg_match('/^f([1-9][0-9]*)$/', (string)($existingRow['id'] ?? ''), $matches)) {
                                        $nextNumber = max($nextNumber, (int)$matches[1] + 1);
                                    }
                                }
                            } else {
                                $existingRows = [];
                            }
                            foreach ($rowsToImport as $row) {
                                $existingRows[] = [
                                    'id' => 'f' . $nextNumber++,
                                    'title' => $row['title'],
                                    'desc' => $row['desc'],
                                ];
                            }
                            $functionalSection['rows'] = $existingRows;
                            unset($functionalSection);
                            $layoutJson = json_encode($layout, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                            $updateStmt = $conn->prepare('UPDATE competency_templates SET layout_json = ? WHERE id = ?');
                            $updateStmt->bind_param('si', $layoutJson, $templateId);
                            $updateStmt->execute();
                            $updateStmt->close();
                            $conn->commit();
                            $functionalSummary = count($rowsToImport);
                            $message = "Functional competencies saved: {$functionalSummary} row(s) imported.";
                            $messageType = 'success';
                        }
                    } catch (Throwable $e) {
                        if ($conn->errno || $conn->thread_id) {
                            try { $conn->rollback(); } catch (Throwable $ignored) {}
                        }
                        throw $e;
                    }
                }
            } else {
                http_response_code(400);
                $message = 'Unknown action.';
                $messageType = 'error';
            }
        }
    }
} catch (Throwable $e) {
    error_log('Template seed operation failed: ' . $e->getMessage());
    if ($messageType === '') {
        http_response_code(500);
        $message = 'Unable to process templates. Check the server error log.';
        $messageType = 'error';
    }
}

if (!$generatedTemplates) {
    try {
        $listStmt = $conn->prepare("SELECT id, title, min_salary_grade FROM competency_templates
            WHERE title LIKE 'SG % - %' AND min_salary_grade = max_salary_grade
            ORDER BY min_salary_grade DESC, title ASC");
        $listStmt->execute();
        $generatedTemplates = $listStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $listStmt->close();
    } catch (Throwable $e) {
        error_log('Template seed generated-template list failed: ' . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Generate Position Sheets</title>
    <style>
        :root { --ink:#202a35; --muted:#687584; --line:#d5dce2; --paper:#fff; --canvas:#edf1f3; --green:#107c41; }
        * { box-sizing:border-box; }
        body { margin:0; padding:28px 16px; background:var(--canvas); color:var(--ink); font:14px "Segoe UI",Arial,sans-serif; }
        main { max-width:1100px; margin:0 auto; }
        section { margin-bottom:22px; padding:22px; background:var(--paper); border:1px solid var(--line); border-radius:8px; }
        h1,h2 { margin-top:0; }
        p { color:var(--muted); line-height:1.5; }
        label { display:block; margin:12px 0 6px; font-weight:600; }
        select,textarea,button { font:inherit; }
        select,textarea { width:100%; padding:9px; border:1px solid #b8c2cb; border-radius:4px; }
        textarea { min-height:190px; resize:vertical; font-family:ui-monospace,Consolas,monospace; }
        button,.button { display:inline-block; padding:9px 14px; border:1px solid var(--green); border-radius:4px; background:var(--green); color:#fff; text-decoration:none; cursor:pointer; }
        button.secondary,.button.secondary { background:#fff; color:var(--green); }
        table { width:100%; margin-top:14px; border-collapse:collapse; }
        th,td { padding:8px; border:1px solid var(--line); text-align:left; }
        th { background:#f4f7f8; }
        .notice { padding:11px 13px; border-radius:4px; background:#eef4ff; border:1px solid #c7d8f5; }
        .notice.error { color:#7a1f16; background:#fdecea; border-color:#f5c2c0; }
        .notice.success { color:#14532d; background:#e8f5e9; border-color:#b7dfbd; }
        .actions { display:flex; flex-wrap:wrap; align-items:center; gap:12px; margin-top:14px; }
        .inline { display:inline-flex; align-items:center; gap:7px; margin:0; font-weight:400; }
        .inline input { margin:0; }
        .radio-group { display:flex; gap:18px; margin:10px 0; }
        .radio-group label { margin:0; font-weight:400; }
    </style>
</head>
<body>
<main>
    <section>
        <h1>Generate Regional Office Position Sheets</h1>
        <p>Generate one scoring sheet per Regional Office position. Select the sharing option so raters can open the forms from the position picker. Existing competency content is preserved.</p>
        <?php if ($message !== ''): ?>
            <div class="notice <?php echo htmlspecialchars($messageType, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <?php if (count($positions) === 0): ?>
            <div class="notice error">No Regional Office positions were found. Seed the built-in positions or ask an administrator to import position data.</div>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="seed_positions">
                <div class="actions"><button type="submit">Seed positions now</button></div>
            </form>
        <?php else: ?>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="generate">
                <label class="inline"><input type="checkbox" name="share_generated" value="1" checked> Make generated position sheets available to raters (also shares previously generated sheets)</label>
                <div class="actions"><button type="submit">Generate sheets</button></div>
            </form>
            <?php if ($generationSummary !== null): ?>
                <p>Summary: <?php echo (int)$generationSummary['created']; ?> created; <?php echo (int)$generationSummary['updated']; ?> functional sections updated; <?php echo (int)$generationSummary['skipped']; ?> skipped; <?php echo (int)$generationSummary['shared']; ?> made available to raters.</p>
            <?php endif; ?>
            <table>
                <thead><tr><th>SG</th><th>Position</th><th>Leadership</th><th>Action</th></tr></thead>
                <tbody>
                <?php
                $existingTitles = [];
                $ownerId = (int)current_user_id();
                $existingStmt = $conn->prepare('SELECT title FROM competency_templates WHERE owner_id = ?');
                $existingStmt->bind_param('i', $ownerId);
                $existingStmt->execute();
                foreach ($existingStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $existingTemplate) {
                    $existingTitles[(string)$existingTemplate['title']] = true;
                }
                $existingStmt->close();
                foreach ($positions as $position):
                    $positionTitle = (string)$position['title'];
                    $sg = (int)$position['salary_grade'];
                    $templateTitle = 'SG ' . $sg . ' - ' . $positionTitle;
                    $level = strtoupper(trim((string)($position['leadership_level'] ?? '')));
                    $leadershipLabel = $level === 'B' ? 'Basic' : ($level === 'I' ? 'Advanced' : '—');
                ?>
                    <tr>
                        <td><?php echo $sg; ?></td>
                        <td><?php echo htmlspecialchars($positionTitle, ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($leadershipLabel, ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo isset($existingTitles[$templateTitle]) ? 'skip' : 'create'; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <section>
        <h2>Import Functional Competencies</h2>
        <p>Paste two columns from Excel: title and description. Use a tab or comma between the columns. Blank lines are ignored.</p>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="import_functional">
            <label for="template_id">Generated template</label>
            <select id="template_id" name="template_id" required>
                <option value="">Select a template</option>
                <?php foreach ($generatedTemplates as $generatedTemplate): ?>
                    <option value="<?php echo (int)$generatedTemplate['id']; ?>" <?php echo (isset($_POST['template_id']) && (int)$_POST['template_id'] === (int)$generatedTemplate['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars((string)$generatedTemplate['title'], ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <div class="radio-group">
                <label><input type="radio" name="import_mode" value="replace" checked> Replace functional section</label>
                <label><input type="radio" name="import_mode" value="append"> Append</label>
            </div>
            <label for="functional_csv">Functional competencies</label>
            <textarea id="functional_csv" name="functional_csv" placeholder="Title&#9;Description"></textarea>
            <div class="actions"><button type="submit">Import functional competencies</button></div>
        </form>
        <?php if ($functionalSummary !== null): ?>
            <p>Summary: <?php echo (int)$functionalSummary; ?> functional competency row(s) added.</p>
        <?php endif; ?>
        <?php if ($functionalErrors): ?>
            <div class="notice error">
                <strong>Rejected lines:</strong>
                <ul>
                <?php foreach ($functionalErrors as $functionalError): ?>
                    <li>Line <?php echo (int)$functionalError['line']; ?>: <?php echo htmlspecialchars($functionalError['message'], ENT_QUOTES, 'UTF-8'); ?></li>
                <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
