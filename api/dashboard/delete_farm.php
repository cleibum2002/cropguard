<?php
// File Location: api/dashboard/delete_farm.php
// "Deletes" a farm by archiving it (Is_Archived = 1), same soft-delete pattern already
// used for damage_report.Status = 'Archived'. Nothing is actually removed from the
// database, and any crop/report history tied to this farm stays completely intact -
// the farm just stops showing up in the farmer's active list.
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
    $stmt = $conn->prepare("UPDATE farm_location SET Is_Archived = 1 WHERE Location_ID = ? AND Farmer_ID = ?");
    $stmt->bind_param("ii", $location_id, $farmer_id);
    if (!$stmt->execute()) throw new Exception("Failed to archive farm: " . $stmt->error);

    if ($stmt->affected_rows === 0) {
        if (ob_get_length()) ob_clean();
        echo json_encode(['status' => 'error', 'message' => 'Farm not found or you do not have permission to archive it.']);
        exit;
    }

    if (ob_get_length()) ob_clean();
    echo json_encode(['status' => 'success', 'message' => 'Farm archived successfully.']);

} catch (Exception $e) {
    if (ob_get_length()) ob_clean();
    echo json_encode(['status' => 'error', 'message' => safeErrorMessage($e)]);
}
?>