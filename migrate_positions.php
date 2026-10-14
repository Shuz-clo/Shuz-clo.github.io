<?php
require_once 'db.php';
require_once 'auth.php';
require_once __DIR__ . '/Raters.php';

require_admin();

header('Content-Type: text/plain; charset=utf-8');

echo "======== DIAGNOSTIC ========\n\n";

// 1. Which functions actually exist?
echo "function_exists('canonical_position_title'): " . (function_exists('canonical_position_title') ? "YES" : "NO") . "\n";
echo "function_exists('compact_position'):         " . (function_exists('compact_position') ? "YES" : "NO") . "\n";
echo "function_exists('compact_position_no_parens'):" . (function_exists('compact_position_no_parens') ? "YES" : "NO") . "\n";
echo "function_exists('numeral_to_digit_map'):     " . (function_exists('numeral_to_digit_map') ? "YES" : "NO") . "\n";
echo "function_exists('digit_to_numeral_map'):     " . (function_exists('digit_to_numeral_map') ? "YES" : "NO") . "\n";
echo "function_exists('leadership_level_titles'):  " . (function_exists('leadership_level_titles') ? "YES" : "NO") . "\n";
echo "function_exists('leadership_level_aliases'): " . (function_exists('leadership_level_aliases') ? "YES" : "NO") . "\n";
echo "\n";

// 2. What does the function actually return?
echo "======== FUNCTION OUTPUT ========\n\n";
$tests = [
    'Atty 5', 'atty 5', 'ATTY 5', 'atty5', 'ATTY-5',
    'Attorney V', 'ATTY V', 'Attorney 5',
    'atty 4', 'Attorney IV',
    'PLO 5', 'plo-5', 'Planning Officer 5',
    'svems', 'SVEMS', 'penro', 'PENRO',
    'Cartographer I',
];
foreach ($tests as $t) {
    $result = function_exists('canonical_position_title')
        ? canonical_position_title($t)
        : '(function missing)';
    printf("%-25s => %s\n", $t, $result);
}
echo "\n";

// 3. What's in the database right now?
echo "======== DB ROWS ========\n\n";
ensure_schema($conn);

echo "-- candidates --\n";
$res = $conn->query("SELECT id, candidate_name, position_title, office_name FROM candidates");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        printf("  id=%d  name=%s  position=[%s]  office=%s\n",
            $row['id'], $row['candidate_name'], $row['position_title'], $row['office_name']);
    }
}

echo "\n-- position_rater_order --\n";
$res = $conn->query("SELECT office, position, rater FROM position_rater_order");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        printf("  office=%s  position=[%s]  rater=%s\n",
            $row['office'], $row['position'], $row['rater']);
    }
}

echo "\n======== RUN MIGRATION ========\n\n";
$res = $conn->query("SELECT id, position_title FROM candidates");
$upd = $conn->prepare("UPDATE candidates SET position_title = ? WHERE id = ?");
$count = 0;
while ($row = $res->fetch_assoc()) {
    $old = (string)$row['position_title'];
    $new = function_exists('canonical_position_title')
        ? canonical_position_title($old)
        : $old;
    if ($new !== $old) {
        $upd->bind_param('si', $new, $row['id']);
        $upd->execute();
        $count++;
        echo "  candidate #{$row['id']}:  '$old'  ->  '$new'\n";
    }
}
$upd->close();
echo "  Candidates updated: $count\n";

echo "\n======== DONE ========\n";
echo "Delete this file now.\n";
