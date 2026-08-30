<?php
// File Location: api/dashboard/submit_farm.php
// This script handles the submission of a new farm location from the dashboard form. It validates the input, saves the data to the database, and returns a JSON response indicating success or failure. Note that RSBA_Reference_No has been removed from both the form and the database operations as per recent edits.
ob_start(); 
session_start();
header('Content-Type: application/json');

try {
    require_once '../config/db_connection.php'; 

    $input = file_get_contents("php://input");
    $data = json_decode($input);

    if (!$data) throw new Exception('No data received from the form.');

    $farmer_id = $_SESSION['user_id'] ?? $_SESSION['User_ID'] ?? $_SESSION['id'] ?? null;
    if (!$farmer_id) throw new Exception('Session expired. Please login again.');

    // REMOVED: RSBA_Reference_No from the column list and removed one "?" placeholder
    $sql = "INSERT INTO farm_location (Farmer_ID, Type_ID, Barangay, Area_Size, Latitude, Longitude, Land_Type, Cropping_Period) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new Exception("Database prepare failed: " . $conn->error);

    // Prepare data
    // GPS is now required (captured only via the "Use My Location" button on the
    // frontend, which the farmer can no longer type over) - this stops bad manual
    // test values like "26, 33" from ever landing in farm_location again, since a
    // farm with no/garbage coordinates is what was causing random pins on the MAO heatmap.
    if (empty($data->latitude) || empty($data->longitude)) {
        throw new Exception('GPS coordinates are required. Please use the "Use My Location" button.');
    }
    $lat = (float)$data->latitude;
    $lng = (float)$data->longitude;
    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        throw new Exception('Invalid GPS coordinates received.');
    }
    $area = (float)$data->area_size;
    if ($area <= 0) {
        echo json_encode(["status" => "error", "message" => "Area size must be greater than 0."]);
        exit;
    }
    $type_id = (int)$data->type_id;

    // REMOVED: $rsba from the bind_param list
    // Updated bind string to "iisddsss" (8 parameters total)
    $stmt->bind_param(
        "iisddsss", 
        $farmer_id, 
        $type_id, 
        $data->barangay, 
        $area, 
        $lat, 
        $lng, 
        $data->land_type, 
        $data->cropping_period
    );

    if (!$stmt->execute()) {
        throw new Exception("Failed to save farm location: " . $stmt->error);
    }

    // Also create a matching entry in the `crop` table, so this crop immediately
    // shows up in the "Which crop was damaged?" picker when filing a report.
    // (Planting_Date is left blank here — it gets filled in automatically the
    // first time this crop is actually used in a damage report.)
    $cropNameStmt = $conn->prepare("SELECT Crop_Name FROM crop_type WHERE Type_ID = ?");
    $cropNameStmt->bind_param("i", $type_id);
    $cropNameStmt->execute();
    $cropTypeResult = $cropNameStmt->get_result()->fetch_assoc();
    $crop_name = $cropTypeResult['Crop_Name'] ?? 'Registered Crop';
    $cropNameStmt->close();

    $new_location_id = $conn->insert_id;

    $insertCrop = $conn->prepare("INSERT INTO crop (Crop_Name, Type_ID, Area_Hectares, Farmer_ID, Location_ID) VALUES (?, ?, ?, ?, ?)");
    $insertCrop->bind_param("sidii", $crop_name, $type_id, $area, $farmer_id, $new_location_id);
    $insertCrop->execute();
    $insertCrop->close();

    if (ob_get_length()) ob_clean();
    echo json_encode(['status' => 'success', 'message' => 'Farm location successfully added!']);

} catch (Exception $e) {
    if (ob_get_length()) ob_clean();
    echo json_encode(['status' => 'error', 'message' => safeErrorMessage($e)]);
}
?>