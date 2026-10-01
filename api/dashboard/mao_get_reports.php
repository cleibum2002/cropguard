<?php
// File Location: api/dashboard/mao_get_reports.php
ob_start();
session_start();
header('Content-Type: application/json');

// Role check
if (!isset($_SESSION['User_ID']) || !in_array($_SESSION['Role'], ['MAO', 'Admin'])) {
    ob_clean();
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized.']);
    exit;
}

try {
    require_once '../config/db_connection.php';

    $sql = "SELECT 
                dr.Damage_Report_ID,
                dr.Report_Date,
                dr.Damage_Type,
                dr.Damage_Severity,
                dr.Status,
                dr.Description,
                u.Fullname       AS Farmer_Name,
                c.Crop_Name,
                COALESCE(dr.Affected_Area, c.Area_Hectares) AS Area_Hectares,
                ct.Crop_Name     AS Crop_Type,
                fl.Barangay
            FROM damage_report dr
            LEFT JOIN users          u  ON dr.Farmer_ID   = u.User_ID
            LEFT JOIN crop           c  ON dr.Crop_ID     = c.Crop_ID
            LEFT JOIN crop_type      ct ON c.Type_ID      = ct.Type_ID
            LEFT JOIN farm_location  fl ON dr.Location_ID = fl.Location_ID
            ORDER BY dr.Report_Date DESC";

    $result = $conn->query($sql);
    if (!$result) throw new Exception("Query failed: " . $conn->error);

    $reports = [];
    while ($row = $result->fetch_assoc()) {
        $reports[] = $row;
    }

    if (ob_get_length()) ob_clean();
    echo json_encode(['status' => 'success', 'reports' => $reports]);

} catch (Exception $e) {
    if (ob_get_length()) ob_clean();
    echo json_encode(['status' => 'error', 'message' => safeErrorMessage($e)]);
}
?>