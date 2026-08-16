<?php
// File Location: api/dashboard/get_crop_options.php
// Description: This API endpoint retrieves the list of crops and their types from the database. This data is essential for populating dropdown menus or selection fields in the frontend when users are creating or updating damage reports, allowing them to specify which crop was affected and its type.
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['User_ID'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized.']);
    exit;
}

require_once '../config/db_connection.php';

try {
    // Fetch only the crops registered by THIS farmer. If a location_id is passed
    // (from the report form's Farm Location dropdown), narrow to just that farm's crop.
    $farmer_id   = $_SESSION['User_ID'];
    $location_id = isset($_GET['location_id']) ? (int)$_GET['location_id'] : null;
    $crops = [];

    if ($location_id) {
        // Read straight from the farm's own registered Main Crop Type — always
        // exists (required when the farm was added), no separate `crop` row needed.
        $stmtCrops = $conn->prepare("SELECT fl.Type_ID, ct.Crop_Name FROM farm_location fl JOIN crop_type ct ON fl.Type_ID = ct.Type_ID WHERE fl.Location_ID = ? AND fl.Farmer_ID = ?");
        $stmtCrops->bind_param("ii", $location_id, $farmer_id);
        $stmtCrops->execute();
        $cropResult = $stmtCrops->get_result();
        if ($cropResult) {
            while ($row = $cropResult->fetch_assoc()) {
                // Re-use Type_ID as the picker's option value (Crop_ID key kept for
                // frontend compatibility — no real `crop` table row required yet)
                $crops[] = [
                    'Crop_ID'   => $row['Type_ID'],
                    'Crop_Name' => $row['Crop_Name'],
                    'Type_ID'   => $row['Type_ID']
                ];
            }
        }
        $stmtCrops->close();
    } else {
        $stmtCrops = $conn->prepare("SELECT * FROM crop WHERE Farmer_ID = ?");
        $stmtCrops->bind_param("i", $farmer_id);
        $stmtCrops->execute();
        $cropResult = $stmtCrops->get_result();
        if ($cropResult) {
            while($row = $cropResult->fetch_assoc()) {
                $crops[] = $row;
            }
        }
        $stmtCrops->close();
    }

    // Fetch from crop types table
    $types = [];
    $typeResult = $conn->query("SELECT * FROM crop_type"); // Change 'crop_types' to your actual table name if different
    if ($typeResult) {
        while($row = $typeResult->fetch_assoc()) {
            $types[] = $row;
        }
    }

    echo json_encode(['status' => 'success', 'crops' => $crops, 'types' => $types]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => safeErrorMessage($e)]);
}
?>