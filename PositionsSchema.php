<?php

const POSITIONS_SEED_VERSION = 2;

const POSITIONS_SEED_CSV = <<<'CSV'
Accountant I,12,
Accountant II,16,
Accountant III,19,B
Accounting Analyst,11,
Administrative Aide I,1,
Administrative Aide III,3,
Administrative Aide IV,4,
Administrative Aide VI,6,
Administrative Aide VI (Driver II),6,
Administrative Assistant I,7,
Administrative Assistant I (Computer Operator I),7,
Administrative Assistant II,8,
Administrative Assistant II (Bookkeeper),8,
Administrative Assistant II (Budgeting Asst.),8,
Administrative Assistant II (Senior Bookkeeper),8,
Administrative Assistant III,9,
Administrative Assistant III (Computer Operator II),9,
Administrative Assistant III (Senior Bookkeeper),9,
Administrative Officer I,10,
Administrative Officer I (Cashier I),10,
Administrative Officer I (Records Officer I),10,
Administrative Officer I (Supply Officer I),10,
Administrative Officer II,11,
Administrative Officer II (Budget Officer I),11,
Administrative Officer III,14,
Administrative Officer IV,15,
Administrative Officer IV (Budget Officer II),15,
Administrative Officer IV (HRMO II),15,
Administrative Officer V,18,B
Administrative Officer V (Budget Officer III),18,B
Administrative Officer V (Cashier III),18,B
Administrative Officer V (HRMO III),18,B
Attorney II,18,B
Attorney III,21,B
Attorney IV,23,B
Attorney V,25,I
Cartographer I,6,
Cartographer II,8,
Cartographer III,11,
Cartographer IV,15,
Chief Administrative Officer,24,I
Chief Ecosystems Management Specialist,24,I
Communication Development Officer I,11,
Communication Development Officer II,15,
Community Development Assistant II,3,
Community Development Officer II,4,
Community Environment & Natural Resources Officer,24,I
Community Environment & Natural Resources Officer (CENRO),24,I
Credit Officer I,9,
Development Management Officer I,11,
Development Management Officer II,15,
Development Management Officer III,18,B
Development Management Officer IV,22,B
Development Management Officer V,24,I
Draftsman I,6,
Draftsman II,8,
Ecosystems Management Specialist I,11,
Ecosystems Management Specialist II,15,
Engineer I (Geodetic Engineer),12,
Engineer II (Geodetic Engineer),16,B
Engineer III (Geodetic Engineer),19,B
Engineer IV (Geodetic Engineer),22,B
Engineer V (Geodetic Engineer),24,I
Engineering Aide,4,
Forest Ranger,4,
Forest Technician I,6,
Forest Technician II,8,
Forester I,11,
Forester II,15,
Forester III,18,B
Information Officer I,11,
Information Officer II,15,
Information Systems Analyst I,12,
Information Systems Analyst II,16,
Information Systems Analyst III,19,B
Laborer I,1,
Land Management Inspector,6,
Land Management Officer I,11,
Land Management Officer II,15,
Land Management Officer III,18,B
Legal Assistant II,12,
Mathematician Aide I,6,
Mathematician Aide II,8,
Mathematician I,10,
Park Attendant II,4,
Park Maintenance Foreman,8,
Park Operation Superintendent IV,22,
Park Operation Superintendent V,24,
Planning Officer I,11,
Planning Officer II,15,
Planning Officer III,18,B
Planning Officer IV,22,B
Planning Officer V,24,I
Project Evaluation Officer I,11,
Project Evaluation Officer II,15,
Project Evaluation Officer III,18,B
Provincial Environment & Natural Resources Officer,26,I
Scaler,5,
Senior Communications Development Officer,18,B
Senior Ecosystems Management Specialist,18,B
Special Investigator I,11,
Statistician I,11,
Statistician II,15,
Supervising Administrative Officer,22,B
Supervising Ecosystems Management Specialist,22,B
Tracer,3,
Tree Marker,2,
Utility Worker I,1,
Zoology Technician,6,
CSV;

function parse_positions_csv(string $csv): array {
    $rows = [];
    $errors = [];
    $lines = preg_split('/\r\n|\n|\r/', $csv);

    foreach ($lines as $index => $line) {
        $lineNumber = $index + 1;
        if (trim($line) === '') continue;
        $fields = str_contains($line, "\t")
            ? array_map('trim', explode("\t", $line))
            : array_map('trim', str_getcsv($line));
        if (count($fields) >= 2
            && in_array(strtolower($fields[0]), ['title', 'position', 'position title'], true)
            && in_array(strtolower($fields[1]), ['salary_grade', 'salary grade', 'sg'], true)) {
            continue;
        }
        if (count($fields) !== 3) {
            $errors[] = ['line' => $lineNumber, 'message' => 'Expected title, salary grade, and level.'];
            continue;
        }

        [$title, $salaryGrade, $level] = $fields;
        $level = strtoupper($level);
        if ($title === '' || mb_strlen($title) > 255) {
            $errors[] = ['line' => $lineNumber, 'message' => 'Title must contain 1 to 255 characters.'];
            continue;
        }
        if (!preg_match('/^(?:[1-9]|[12][0-9]|3[0-3])$/', $salaryGrade)) {
            $errors[] = ['line' => $lineNumber, 'message' => 'Salary grade must be an integer from 1 to 33.'];
            continue;
        }
        if (!in_array($level, ['', 'B', 'I'], true)) {
            $errors[] = ['line' => $lineNumber, 'message' => 'Level must be blank, B, or I.'];
            continue;
        }

        $rows[] = [
            'line' => $lineNumber,
            'title' => $title,
            'salary_grade' => (int)$salaryGrade,
            'leadership_level' => $level === '' ? null : $level,
        ];
    }

    return [$rows, $errors];
}

function ensure_positions_schema(mysqli $conn): void {
    if (!app_allow_ddl()) return;
    $conn->query("CREATE TABLE IF NOT EXISTS positions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        office_scope VARCHAR(40) NOT NULL DEFAULT 'REGIONAL_OFFICE',
        title VARCHAR(255) NOT NULL,
        salary_grade TINYINT UNSIGNED NOT NULL,
        leadership_level CHAR(1) NULL,
        UNIQUE KEY uq_scope_title (office_scope, title)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS positions_seed_state_v2 (
        id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
        seed_version SMALLINT UNSIGNED NOT NULL
    ) ENGINE=InnoDB");
    $conn->query('INSERT IGNORE INTO positions_seed_state_v2 (id, seed_version) VALUES (1, 0)');

    [$seedRows, $errors] = parse_positions_csv(POSITIONS_SEED_CSV);
    if ($errors) {
        throw new RuntimeException('Built-in position seed data is invalid.');
    }

    $conn->begin_transaction();
    try {
        $versionResult = $conn->query('SELECT seed_version FROM positions_seed_state_v2 WHERE id = 1 FOR UPDATE');
        $versionRow = $versionResult->fetch_assoc();
        if (!$versionRow) {
            throw new RuntimeException('Position seed version could not be read.');
        }

        if ((int)$versionRow['seed_version'] < POSITIONS_SEED_VERSION) {
            $stmt = $conn->prepare("INSERT INTO positions (office_scope, title, salary_grade, leadership_level)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE salary_grade = VALUES(salary_grade), leadership_level = VALUES(leadership_level)");
            $scope = 'REGIONAL_OFFICE';
            foreach ($seedRows as $position) {
                $title = $position['title'];
                $salaryGrade = $position['salary_grade'];
                $level = $position['leadership_level'];
                $stmt->bind_param('ssis', $scope, $title, $salaryGrade, $level);
                $stmt->execute();
            }
            $stmt->close();

            $stmt = $conn->prepare('UPDATE positions_seed_state_v2 SET seed_version = ? WHERE id = 1');
            $version = POSITIONS_SEED_VERSION;
            $stmt->bind_param('i', $version);
            $stmt->execute();
            $stmt->close();
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}
