<?php
session_start();
header('Content-Type: application/json');
require_once '../config/db_connection.php';

if (!isset($_SESSION['User_ID']) || $_SESSION['Role'] !== 'Encoder') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized.']);
    exit;
}

$encoder_id = $_SESSION['User_ID'];

try {
    $sql = "SELECT 
                dr.Damage_Report_ID  as id,
                u.Fullname           as farmer,
                c.Crop_Name          as crop_name,
                DATE(dr.Report_Date) as date,
                dr.Damage_Type       as damage_type,
                dr.Damage_Severity   as severity,
                dr.Status            as status
            FROM damage_report dr
            LEFT JOIN users u ON dr.Farmer_ID = u.User_ID
            LEFT JOIN crop  c ON dr.Crop_ID   = c.Crop_ID
            WHERE dr.Encoder_ID = ?
            ORDER BY dr.Damage_Report_ID DESC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $encoder_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $data = [];
    while ($row = $result->fetch_assoc()) { $data[] = $row; }
    $stmt->close();

    echo json_encode(['status' => 'success', 'data' => $data]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => safeErrorMessage($e)]);
}
?>