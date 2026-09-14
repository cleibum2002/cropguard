<?php
// File Location: api/technician/get_dashboard.php
session_start();
header('Content-Type: application/json');
require_once '../config/db_connection.php';

$tech_id = $_SESSION['User_ID'] ?? null;
if (!$tech_id) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

try {
    // 1. Technician Info
    $stmt = $conn->prepare("SELECT Fullname, Assigned_Area FROM users WHERE User_ID = ? AND Role = 'Technician'");
    $stmt->bind_param("i", $tech_id);
    $stmt->execute();
    $tech = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // 2. Stats
    $qStats = "SELECT 
                SUM(CASE WHEN Status IN ('Pending', 'Validated') THEN 1 ELSE 0 END) as assigned,
                SUM(CASE WHEN Damage_Severity IN ('High', 'Total Loss') AND Status IN ('Pending', 'Validated') THEN 1 ELSE 0 END) as urgent,
                SUM(CASE WHEN Status IN ('Inspected', 'Approved', 'Rejected') THEN 1 ELSE 0 END) as completed
               FROM damage_report 
               WHERE Technician_ID = ?";

    $stmt = $conn->prepare($qStats);
    $stmt->bind_param("i", $tech_id);
    $stmt->execute();
    $stats = $stmt->get_result()->fetch_assoc();

    $stats['assigned']  = $stats['assigned']  ?? 0;
    $stats['urgent']    = $stats['urgent']     ?? 0;
    $stats['completed'] = $stats['completed']  ?? 0;
    $stmt->close();

    // 3. Assignments Queue — now includes Visit_Date
    // LEFT JOIN, not JOIN: a report with a broken/missing Location_ID or
    // Farmer_ID reference must still show up here (even if some fields come
    // back blank) rather than silently vanishing from the list while still
    // being counted in the stats above, which don't use any joins at all.
    $qAssign = "SELECT 
                    dr.Damage_Report_ID  AS id,
                    c.Crop_Name          AS crop_name,
                    dr.Damage_Type,
                    f.Fullname           AS farmer_name,
                    fl.Barangay          AS barangay,
                    dr.Visit_Date        AS visit_date
                FROM damage_report dr
                LEFT JOIN farm_location fl ON dr.Location_ID  = fl.Location_ID
                LEFT JOIN users         f  ON dr.Farmer_ID    = f.User_ID
                LEFT JOIN crop          c  ON dr.Crop_ID      = c.Crop_ID
                WHERE dr.Technician_ID = ?
                  AND dr.Status IN ('Pending', 'Validated')
                ORDER BY dr.Visit_Date ASC, dr.Report_Date ASC";

    $stmt = $conn->prepare($qAssign);
    $stmt->bind_param("i", $tech_id);
    $stmt->execute();
    $assignments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // 4. Completed
    // Was capped at LIMIT 10, which silently hid anything past a technician's
    // 10 most recent inspections - the Completed tab now paginates client-side
    // instead, so it needs every row, not just the first page's worth.
    $qComp = "SELECT
                dr.Damage_Report_ID AS id,
                DATE(dr.Report_Date) AS date,
                dr.Damage_Severity   AS severity,
                dr.Status            AS current_status,
                c.Crop_Name          AS crop_name,
                dr.Damage_Type       AS Damage_Type,
                f.Fullname           AS farmer_name,
                fl.Barangay          AS barangay
              FROM damage_report dr
              LEFT JOIN farm_location fl ON dr.Location_ID = fl.Location_ID
              LEFT JOIN users         f  ON dr.Farmer_ID   = f.User_ID
              LEFT JOIN crop          c  ON dr.Crop_ID     = c.Crop_ID
              WHERE dr.Technician_ID = ?
                AND dr.Status IN ('Inspected', 'Approved', 'Rejected')
              ORDER BY dr.Report_Date DESC";

    $stmt = $conn->prepare($qComp);
    $stmt->bind_param("i", $tech_id);
    $stmt->execute();
    $completed = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    echo json_encode([
        'status'         => 'success',
        'technician'     => $tech,
        'stats'          => $stats,
        'assignments'    => $assignments,
        'completed_list' => $completed
    ]);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => safeErrorMessage($e)]);
}
?>