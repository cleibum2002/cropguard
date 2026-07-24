<?php
// File Location: api/dashboard/get_farm_locations.php
// This script retrieves all farm locations for the logged-in farmer, including the crop type name for display on the dashboard.
ob_start();
session_start();
header('Content-Type: application/json');

try {
    require_once '../config/db_connection.php';

    // Get the logged-in Farmer's ID
    $farmer_id = $_SESSION['user_id'] ?? $_SESSION['User_ID'] ?? $_SESSION['id'] ?? null;
    
    if (!$farmer_id) throw new Exception('Unauthorized. Please log in.');

    // We replaced "f.*" with the exact explicit column names so it NEVER looks for RSBA_Reference_No
    $sql = "SELECT 
                f.Location_ID, 
                f.Farmer_ID, 
                f.Type_ID, 
                f.Barangay, 
                f.Area_Size, 
                f.Latitude, 
                f.Longitude, 
                f.Land_Type, 
                f.Cropping_Period, 
                c.Crop_Name, 
                c.Commodity_Type 
            FROM farm_location f 
            LEFT JOIN crop_type c ON f.Type_ID = c.Type_ID 
            WHERE f.Farmer_ID = ?";
            
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $farmer_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $locations = [];
    while ($row = $result->fetch_assoc()) {
        $locations[] = $row;
    }

    if (ob_get_length()) ob_clean();
    echo json_encode(['status' => 'success', 'locations' => $locations]);

} catch (Exception $e) {
    if (ob_get_length()) ob_clean();
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>