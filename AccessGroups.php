<?php

const ACCESS_REGIONS = [
    'NCR' => 'National Capital Region (NCR)',
    'CAR' => 'Cordillera Administrative Region (CAR)',
    'Region I' => 'Ilocos Region (Region I)',
    'Region II' => 'Cagayan Valley (Region II)',
    'Region III' => 'Central Luzon (Region III)',
    'Region IV-A' => 'CALABARZON (Region IV-A)',
    'MIMAROPA' => 'MIMAROPA Region',
    'Region V' => 'Bicol Region (Region V)',
    'Region VI' => 'Western Visayas (Region VI)',
    'Region VII' => 'Central Visayas (Region VII)',
    'Region VIII' => 'Eastern Visayas (Region VIII)',
    'Region IX' => 'Zamboanga Peninsula (Region IX)',
    'Region X' => 'Northern Mindanao (Region X)',
    'Region XI' => 'Davao Region (Region XI)',
    'Region XII' => 'SOCCSKSARGEN (Region XII)',
    'Region XIII' => 'Caraga (Region XIII)',
    'BARMM' => 'Bangsamoro Autonomous Region in Muslim Mindanao (BARMM)',
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
    return (is_int($value) || (is_string($value) && preg_match('/^(0|[1-9]|1[0-9]|2[0-5])$/', $value)))
        && (int)$value >= 0
        && (int)$value <= 25;
}
