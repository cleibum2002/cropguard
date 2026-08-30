<?php
// File Location: api/dashboard/delete_farm.php
// Deletes a farm location - but ONLY if it has no damage reports filed against it.
// A farm with real report history (even old/resolved) is blocked from deletion to
// avoid silently orphaning or losing that history. The farmer would need to contact
// support/MAO if a farm with reports genuinely needs to be removed.
ob_start();
session_start();
header('Content-Type: application/json');

require_once '../config/db_connection.php';

$farmer_id = $_SESSION['User_ID'] ?? null;
if (!$farmer_id) {
    if (ob_get_length()) ob_clean();
    echo json_encode(['status' => 'error', 'message' => 'Session expired. Please login again.']);
    exit;
}

$input = file_get_contents("php://input");
$data = json_decode($input);
if (!$data || empty($data->location_id)) {
    if (ob_get_length()) ob_clean();
    echo json_encode(['status' => 'error', 'message' => 'Missing farm ID.']);
    exit;
}

$location_id = (int)$data->location_id;

try {
    // Ownership check
    $checkStmt = $conn->prepare("SELECT Location_ID FROM farm_location WHERE Location_ID = ? AND Farmer_ID = ?");
    $checkStmt->bind_param("ii", $location_id, $farmer_id);
    $checkStmt->execute();
    if (!$checkStmt->get_result()->fetch_assoc()) {
        if (ob_get_length()) ob_clean();
        echo json_encode(['status' => 'error', 'message' => 'Farm not found or you do not have permission to delete it.']);
        exit;
    }
    $checkStmt->close();

    // Block deletion if any report was ever filed against this farm
    $reportCheck = $conn->prepare("SELECT COUNT(*) as cnt FROM damage_report WHERE Location_ID = ?");
    $reportCheck->bind_param("i", $location_id);
    $reportCheck->execute();
    $reportCount = $reportCheck->get_result()->fetch_assoc()['cnt'];
    $reportCheck->close();

    if ($reportCount > 0) {
        if (ob_get_length()) ob_clean();
        echo json_encode(['status' => 'error', 'message' => 'This farm cannot be deleted because it has ' . $reportCount . ' damage report(s) on record. Contact your MAO office if this farm truly needs to be removed.']);
        exit;
    }

    // Safe to delete - remove the linked crop row(s) first, then the farm itself
    $delCrop = $conn->prepare("DELETE FROM crop WHERE Location_ID = ? AND Farmer_ID = ?");
    $delCrop->bind_param("ii", $location_id, $farmer_id);
    $delCrop->execute();
    $delCrop->close();

    $delFarm = $conn->prepare("DELETE FROM farm_location WHERE Location_ID = ? AND Farmer_ID = ?");
    $delFarm->bind_param("ii", $location_id, $farmer_id);
    if (!$delFarm->execute()) throw new Exception("Failed to delete farm: " . $delFarm->error);

    if (ob_get_length()) ob_clean();
    echo json_encode(['status' => 'success', 'message' => 'Farm deleted successfully.']);

} catch (Exception $e) {
    if (ob_get_length()) ob_clean();
    echo json_encode(['status' => 'error', 'message' => safeErrorMessage($e)]);
}
?>