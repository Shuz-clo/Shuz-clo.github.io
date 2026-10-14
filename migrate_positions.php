<?php
require_once 'db.php';
require_once 'auth.php';
require_once __DIR__ . '/Raters.php';

require_admin();

ensure_schema($conn);

$res = $conn->query("SELECT id, position_title FROM candidates");
$upd = $conn->prepare("UPDATE candidates SET position_title = ? WHERE id = ?");

$count = 0;
$changes = [];

while ($row = $res->fetch_assoc()) {
    $old = (string)$row['position_title'];
    $new = canonical_position_title($old);
    if ($new !== $old) {
        $upd->bind_param('si', $new, $row['id']);
        $upd->execute();
        $count++;
        $changes[] = htmlspecialchars("$old  ->  $new");
    }
}
$upd->close();

// Also migrate position_rater_order
$res2 = $conn->query("SELECT DISTINCT office, position FROM position_rater_order");
$upd2 = $conn->prepare("UPDATE position_rater_order SET position = ? WHERE office = ? AND position = ?");
$count2 = 0;

while ($row = $res2->fetch_assoc()) {
    $old = (string)$row['position'];
    $new = canonical_position_title($old);
    if ($new !== $old) {
        $upd2->bind_param('sss', $new, $row['office'], $old);
        $upd2->execute();
        $count2++;
        $changes[] = htmlspecialchars("[" . $row['office'] . "] $old  ->  $new");
    }
}
$upd2->close();

echo "<pre style='font: 13px/1.5 monospace; padding:20px;'>";
echo "Migrated $count candidate row(s).\n";
echo "Migrated $count2 rater-order row(s).\n\n";
if ($changes) {
    echo "Changes:\n";
    foreach ($changes as $c) echo "  $c\n";
} else {
    echo "No changes — all positions were already canonical.\n";
}
echo "\n<b style='color:#b00;'>DELETE THIS FILE NOW.</b>\n";
echo "</pre>";