<?php
// api/dashboard/stats.php (ADMIN VERSION)
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['User_ID']) || $_SESSION['Role'] !== 'Admin') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized.']);
    exit;
}

require_once '../config/db_connection.php';

try {
    // 1. Total damage reports
    $res['total_items'] = $conn->query(
        "SELECT COUNT(*) FROM damage_report WHERE Status != 'Archived'"
    )->fetch_row()[0] ?? 0;

    // 2. Pending validation count
    $res['total_pending'] = $conn->query(
        "SELECT COUNT(*) FROM damage_report WHERE Status = 'Pending'"
    )->fetch_row()[0] ?? 0;

    // 3. Active technicians from users table
    $res['total_techs'] = $conn->query(
        "SELECT COUNT(*) FROM users WHERE Role = 'Technician' AND STATUS = 'Active'"
    )->fetch_row()[0] ?? 0;

    // 3b. Total registered technicians regardless of status — the dashboard
    // shows this as "X/Y" (active out of total). Previously Y was a hardcoded
    // "12" in the frontend with no connection to real data at all.
    $res['total_techs_all'] = $conn->query(
        "SELECT COUNT(*) FROM users WHERE Role = 'Technician'"
    )->fetch_row()[0] ?? 0;

    // 4. Avg response time in days
    $avgResult = $conn->query(
        "SELECT ROUND(AVG(DATEDIFF(NOW(), Report_Date)), 1) as avg_days 
         FROM damage_report 
         WHERE Status IN ('Inspected','Approved')"
    )->fetch_row()[0];
    $res['avg_response'] = $avgResult ?? null;

    // 5. Validation queue — joined with users table
    $queueSort = (isset($_GET['queue_sort']) && strtoupper($_GET['queue_sort']) === 'DESC') ? 'DESC' : 'ASC';
    $queueQuery = "SELECT 
                        dr.Damage_Report_ID, 
                        dr.Damage_Type,
                        u.Fullname as Farmer_Name,
                        fl.Barangay as barangay,
                        DATE(dr.Report_Date) as date
                   FROM damage_report dr
                   JOIN users u ON dr.Farmer_ID = u.User_ID
                   LEFT JOIN farm_location fl ON dr.Location_ID = fl.Location_ID
                   WHERE dr.Status = 'Pending'
                   ORDER BY dr.Report_Date $queueSort
                   LIMIT 5";
    $queue = $conn->query($queueQuery);
    $res['queue'] = $queue ? $queue->fetch_all(MYSQLI_ASSOC) : [];

    // 6. Recent certifications
    $certQuery = "SELECT 
                        dr.Damage_Report_ID as report_id,
                        u.Fullname as farmer_name,
                        c.Crop_Name as crop,
                        DATE(dr.Report_Date) as date
                  FROM damage_report dr
                  JOIN users u ON dr.Farmer_ID = u.User_ID
                  LEFT JOIN crop c ON dr.Crop_ID = c.Crop_ID
                  WHERE dr.Status = 'Approved'
                  ORDER BY dr.Report_Date DESC
                  LIMIT 5";
    $cert = $conn->query($certQuery);
    $res['certified'] = $cert ? $cert->fetch_all(MYSQLI_ASSOC) : [];

    // 7. Technician list
$techQuery = "SELECT 
                u.User_ID,
                u.Fullname,
                u.Assigned_Area,
                COUNT(dr.Damage_Report_ID) as active_reports
              FROM users u
              LEFT JOIN damage_report dr ON dr.Technician_ID = u.User_ID 
                AND dr.Status IN ('Validated','Inspected')
              WHERE u.Role = 'Technician' AND u.STATUS = 'Active'
              GROUP BY u.User_ID
              ORDER BY u.Fullname ASC";
$techResult = $conn->query($techQuery);
$res['technicians'] = $techResult ? $techResult->fetch_all(MYSQLI_ASSOC) : [];

echo json_encode($res);


} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => safeErrorMessage($e)]);
}
$conn->close();
?>