<?php
session_start();
header('Content-Type: application/json');
require_once '../config/db_connection.php';

if (!isset($_SESSION['User_ID']) || $_SESSION['Role'] !== 'Encoder') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized.']);
    exit;
}

$farmer_id = isset($_GET['farmer_id']) ? (int)$_GET['farmer_id'] : 0;

if (!$farmer_id) {
    echo json_encode(['status' => 'error', 'message' => 'Missing farmer ID.']);
    exit;
}

try {
    $locStmt = $conn->prepare("SELECT Location_ID, Barangay, Area_Size FROM farm_location WHERE Farmer_ID = ?");
    $locStmt->bind_param("i", $farmer_id);
    $locStmt->execute();
    $locations = $locStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $locStmt->close();

    $cropStmt = $conn->prepare("SELECT Crop_ID, Crop_Name FROM crop WHERE Farmer_ID = ?");
    $cropStmt->bind_param("i", $farmer_id);
    $cropStmt->execute();
    $crops = $cropStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $cropStmt->close();

    echo json_encode(['status' => 'success', 'locations' => $locations, 'crops' => $crops]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => safeErrorMessage($e)]);
}
?>