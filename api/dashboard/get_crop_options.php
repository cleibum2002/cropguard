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
        // Read from the farm's own registered Main Crop Type — always exists
        // (required when the farm was added) — LEFT JOINed against `crop` so
        // the frontend can tell whether a crop cycle is already on record for
        // this farm+type. When one exists, the report form locks Planting
        // Date to it instead of letting a second report silently overwrite
        // the real planting date of a crop that's still standing.
        $stmtCrops = $conn->prepare(
            "SELECT fl.Type_ID, ct.Crop_Name, c.Crop_ID AS Real_Crop_ID, c.Planting_Date
             FROM farm_location fl
             JOIN crop_type ct ON fl.Type_ID = ct.Type_ID
             LEFT JOIN crop c ON c.Farmer_ID = fl.Farmer_ID
                              AND c.Location_ID = fl.Location_ID
                              AND c.Type_ID = fl.Type_ID
             WHERE fl.Location_ID = ? AND fl.Farmer_ID = ?"
        );
        $stmtCrops->bind_param("ii", $location_id, $farmer_id);
        $stmtCrops->execute();
        $cropResult = $stmtCrops->get_result();
        if ($cropResult) {
            while ($row = $cropResult->fetch_assoc()) {
                // Falls back to Type_ID as a placeholder Crop_ID when no real
                // `crop` row exists yet (first report ever for this farm+type) -
                // same fallback behavior as before, now also carrying the real
                // Crop_ID and Planting_Date once one does exist.
                $crops[] = [
                    'Crop_ID'       => $row['Real_Crop_ID'] ?: $row['Type_ID'],
                    'Crop_Name'     => $row['Crop_Name'],
                    'Type_ID'       => $row['Type_ID'],
                    'Planting_Date' => $row['Planting_Date']
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