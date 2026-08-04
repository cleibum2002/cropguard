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
    // Fetch only the crops registered by THIS farmer (from My Farms)
    $farmer_id = $_SESSION['User_ID'];
    $crops = [];
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