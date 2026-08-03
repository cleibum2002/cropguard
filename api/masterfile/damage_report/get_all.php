<?php
// File Location: api/masterfile/damage_report/get_all.php
ini_set('display_errors', 0);
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['User_ID']) || !in_array($_SESSION['Role'], ['Admin', 'MAO'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized.']);
    exit;
}

try {
    include_once '../../config/db_connection.php'; // Adjust path if needed

    // Notice we now JOIN the 'users' table twice! Once for the Farmer, once for the Tech.
    $query = "
        SELECT 
            dr.Damage_Report_ID as id, 
            dr.Farmer_ID as farmer_id,
            f.Fullname as farmer, 
            c.Crop_Name as crop_name, 
            c.Planting_Date as planting_date,
            c.Area_Hectares as area,
            DATE(dr.Report_Date) as date,
            dr.Damage_Type as damage_type, 
            dr.Damage_Severity as severity, 
            dr.Status as status,
            dr.Description as description,
            t.Fullname as technician_name
        FROM damage_report dr
        LEFT JOIN users f ON dr.Farmer_ID = f.User_ID AND f.Role = 'Farmer'
        LEFT JOIN crop c ON dr.Crop_ID = c.Crop_ID
        LEFT JOIN users t ON dr.Technician_ID = t.User_ID AND t.Role = 'Technician'
        WHERE dr.Status != 'Archived'
        ORDER BY dr.Damage_Report_ID DESC
    ";
    
    $result = $conn->query($query);
    if (!$result) throw new Exception($conn->error);

    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }

    echo json_encode(["status" => "success", "data" => $data]);
} catch (Throwable $e) {
    echo json_encode(["status" => "error", "message" => safeErrorMessage($e)]);
}
?>