<?php
// File Location: api/dashboard/update_farm.php
// Updates an existing farm's details (crop type, cropping period, area, land type, GPS).
// The address (Barangay) is intentionally NOT editable here - it's only ever captured
// as a flattened display string, not separate Province/City/Barangay codes, so there's
// no reliable way to pre-select the cascading picker for editing. Re-adding the farm
// is the only way to change its address.
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

// Ownership check - this farm must actually belong to the logged-in farmer
$checkStmt = $conn->prepare("SELECT Location_ID FROM farm_location WHERE Location_ID = ? AND Farmer_ID = ?");
$checkStmt->bind_param("ii", $location_id, $farmer_id);
$checkStmt->execute();
if (!$checkStmt->get_result()->fetch_assoc()) {
    if (ob_get_length()) ob_clean();
    echo json_encode(['status' => 'error', 'message' => 'Farm not found or you do not have permission to edit it.']);
    exit;
}
$checkStmt->close();

$area = (float)($data->area_size ?? 0);
if ($area <= 0) {
    if (ob_get_length()) ob_clean();
    echo json_encode(['status' => 'error', 'message' => 'Area size must be greater than 0.']);
    exit;
}

$type_id = (int)$data->type_id;
$land_type = $data->land_type ?? '';
$cropping_period = $data->cropping_period ?? '';
$lat = !empty($data->latitude) ? (float)$data->latitude : null;
$lng = !empty($data->longitude) ? (float)$data->longitude : null;

if ($lat !== null && ($lat < -90 || $lat > 90)) {
    if (ob_get_length()) ob_clean();
    echo json_encode(['status' => 'error', 'message' => 'Invalid GPS coordinates.']);
    exit;
}
if ($lng !== null && ($lng < -180 || $lng > 180)) {
    if (ob_get_length()) ob_clean();
    echo json_encode(['status' => 'error', 'message' => 'Invalid GPS coordinates.']);
    exit;
}

try {
    $sql = "UPDATE farm_location SET Type_ID = ?, Area_Size = ?, Land_Type = ?, Cropping_Period = ?, Latitude = ?, Longitude = ? WHERE Location_ID = ? AND Farmer_ID = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("idssddii", $type_id, $area, $land_type, $cropping_period, $lat, $lng, $location_id, $farmer_id);
    if (!$stmt->execute()) throw new Exception("Failed to update farm: " . $stmt->error);

    // Keep the matching `crop` table row(s) for this location in sync too
    $cropNameStmt = $conn->prepare("SELECT Crop_Name FROM crop_type WHERE Type_ID = ?");
    $cropNameStmt->bind_param("i", $type_id);
    $cropNameStmt->execute();
    $cropTypeResult = $cropNameStmt->get_result()->fetch_assoc();
    $crop_name = $cropTypeResult['Crop_Name'] ?? 'Registered Crop';
    $cropNameStmt->close();

    $updateCrop = $conn->prepare("UPDATE crop SET Crop_Name = ?, Type_ID = ?, Area_Hectares = ? WHERE Location_ID = ? AND Farmer_ID = ?");
    $updateCrop->bind_param("sidii", $crop_name, $type_id, $area, $location_id, $farmer_id);
    $updateCrop->execute();
    $updateCrop->close();

    require_once '../audit/log_action.php';
    logAction($conn, $farmer_id, $_SESSION['Role'] ?? 'Farmer',
        'Update Farm',
        "Updated farm #{$location_id} ({$crop_name}, {$area} ha)",
        'Farm Management'
    );

    if (ob_get_length()) ob_clean();
    echo json_encode(['status' => 'success', 'message' => 'Farm updated successfully!']);

} catch (Exception $e) {
    if (ob_get_length()) ob_clean();
    echo json_encode(['status' => 'error', 'message' => safeErrorMessage($e)]);
}
?>