<?php
require_once 'auth.php';
require_admin(true);
require_once 'db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id     = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $action = $_POST['action'] ?? 'single_update';

    if ($id <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid Candidate ID']);
        exit();
    }

    if ($action === 'full_update') {
        $name        = trim($_POST['candidate_name'] ?? '');
        $office      = trim($_POST['office_name'] ?? '');
        $psychoAdmin = $_POST['psychoAdmin'] !== '' ? (float)$_POST['psychoAdmin'] : null;
        $psychoEnd   = $_POST['psychoEnd'] !== '' ? (float)$_POST['psychoEnd'] : null;
        $potAdmin    = $_POST['potAdmin'] !== '' ? (float)$_POST['potAdmin'] : null;
        $potEnd      = $_POST['potEnd'] !== '' ? (float)$_POST['potEnd'] : null;

        $stmt = $conn->prepare("UPDATE candidates SET candidate_name = ?, office_name = ?, core_avg = ?, org_avg = ?, lead_avg = ?, func_avg = ? WHERE id = ?");
        $stmt->bind_param("ssddddi", $name, $office, $psychoAdmin, $psychoEnd, $potAdmin, $potEnd, $id);

        if ($stmt->execute()) {
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => $conn->error]);
        }
        $stmt->close();
        exit();
    }

    $field = $_POST['field'] ?? '';
    $val   = $_POST['value'] ?? null;

    $allowedFields = [
        'name'           => 'candidate_name',
        'office'         => 'office_name',
        'psychoAdmin'    => 'core_avg',
        'psychoEndUser'  => 'org_avg',
        'potentialAdmin' => 'lead_avg',
        'potentialEnd'   => 'func_avg'
    ];

    if (array_key_exists($field, $allowedFields)) {
        $dbColumn = $allowedFields[$field];

        if ($field === 'name' || $field === 'office') {
            $stmt = $conn->prepare("UPDATE candidates SET $dbColumn = ? WHERE id = ?");
            $stmt->bind_param("si", $val, $id);
        } else {
            $floatVal = ($val !== '' && $val !== null && is_numeric($val)) ? (float)$val : null;
            $stmt = $conn->prepare("UPDATE candidates SET $dbColumn = ? WHERE id = ?");
            $stmt->bind_param("di", $floatVal, $id);
        }

        if ($stmt->execute()) {
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => $conn->error]);
        }
        $stmt->close();
        exit();
    }
}

echo json_encode(['status' => 'invalid_request']);
?>