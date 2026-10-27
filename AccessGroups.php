<?php

const ACCESS_REGIONS = [
    'Region VI' => 'Western Visayas (Region VI)',
];

const ACCESS_FIELDS = [
    'Forestry',
    'Biodiversity',
    'Land Management',
    'Environmental Management',
    'Mines and Geosciences',
    'Coastal and Marine',
    'Administration',
    'Planning',
    'Legal',
    'Regional Office',
    'Other',
];

function access_field_is_valid($value): bool {
    if (!is_string($value)) return false;
    $value = trim($value);
    return $value !== ''
        && mb_strlen($value) <= 100
        && preg_match('/^[\p{L}\p{N} .&()\/-]+$/u', $value) === 1;
}

function access_salary_grade_is_valid($value): bool {
    return (is_int($value) || (is_string($value) && preg_match('/^(0|[1-9]|1[0-9]|2[0-6])$/', $value)))
        && (int)$value >= 0
        && (int)$value <= 26;
}

function access_position_salary_grades(mysqli $conn): array {
    $stmt = $conn->prepare("SELECT DISTINCT salary_grade
        FROM positions
        WHERE office_scope = 'REGIONAL_OFFICE'
        ORDER BY salary_grade ASC");
    $stmt->execute();
    $result = $stmt->get_result();
    $grades = [];
    while ($row = $result->fetch_assoc()) {
        $grade = (int)$row['salary_grade'];
        if (access_salary_grade_is_valid($grade)) {
            $grades[] = $grade;
        }
    }
    $stmt->close();
    return $grades;
}

function access_position_salary_grade_is_valid(mysqli $conn, $value, ?array $grades = null): bool {
    if (!access_salary_grade_is_valid($value)) {
        return false;
    }
    $grades = $grades ?? access_position_salary_grades($conn);
    return in_array((int)$value, $grades, true);
}
