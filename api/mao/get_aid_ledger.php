<?php
// File Location: api/mao/get_aid_ledger.php
session_start();
header('Content-Type: application/json');
require_once '../config/db_connection.php';

// Role check
if (!isset($_SESSION['User_ID']) || !in_array($_SESSION['Role'], ['MAO', 'Admin'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized.']);
    exit;
}

try {
    $query = "
        SELECT 
            ad.Distribution_ID  as dist_id,
            ad.Damage_Report_ID as report_id,
            ad.Category         as category,
            ad.Quantity         as quantity,
            ad.Unit             as unit,
            ad.Status           as status,
            ad.Date_Distributed as date_distributed,
            u_farmer.Fullname   as farmer_name,
            u_admin.Fullname    as admin_name,
            dr.Damage_Type      as damage_type,
            dr.Damage_Severity  as damage_severity,
            c.Crop_Name         as crop_name,
            fl.Barangay         as barangay_raw,
            u_tech.Fullname     as technician_name
        FROM aid_distribution ad
        JOIN users u_farmer ON ad.Farmer_ID  = u_farmer.User_ID
        JOIN users u_admin  ON ad.Distributed_By = u_admin.User_ID
        LEFT JOIN damage_report dr ON ad.Damage_Report_ID = dr.Damage_Report_ID
        LEFT JOIN crop c           ON dr.Crop_ID = c.Crop_ID
        LEFT JOIN farm_location fl ON dr.Location_ID = fl.Location_ID
        LEFT JOIN users u_tech     ON dr.Technician_ID = u_tech.User_ID
        ORDER BY 
            CASE WHEN ad.Status = 'Pending Distribution' THEN 1 ELSE 2 END,
            ad.Distribution_ID DESC
    ";

    $result = $conn->query($query);
    if (!$result) throw new Exception($conn->error);

    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }

    echo json_encode(['status' => 'success', 'data' => $data]);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>