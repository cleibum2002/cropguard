<?php
// File Location: api/dashboard/farmer_dashboard_data.php
session_start();
header('Content-Type: application/json');
require_once '../config/db_connection.php';

// Check for the User_ID set during our new login process
$farmer_id = $_SESSION['User_ID'] ?? null;

if (!$farmer_id) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized. Please log in.']);
    exit;
}

try {
    $response = ['status' => 'success', 'farmer_name' => 'Farmer', 'stats' => [], 'reports' => []];

    // --- FIX 1: Fetch Name from 'users' table instead of 'Farmer' ---
    $nameQuery = "SELECT Fullname FROM users WHERE User_ID = ? AND Role = 'Farmer'";
    $stmtName = $conn->prepare($nameQuery);
    $stmtName->bind_param("i", $farmer_id);
    $stmtName->execute();
    $nameResult = $stmtName->get_result()->fetch_assoc();
    
    if ($nameResult) {
        $response['farmer_name'] = $nameResult['Fullname'];
    }
    $stmtName->close();

    // --- FIX 2: Stats Query ---
    $statQuery = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN Status = 'Pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN Status IN ('Validated', 'Approved', 'Completed', 'Inspected') THEN 1 ELSE 0 END) as resolved
                  FROM damage_report 
                  WHERE Farmer_ID = ? AND Status != 'Archived'";
                  
    $stmt = $conn->prepare($statQuery);
    $stmt->bind_param("i", $farmer_id);
    $stmt->execute();
    $statResult = $stmt->get_result()->fetch_assoc();
    
    $response['stats'] = [
        'total' => $statResult['total'] ?? 0,
        'pending' => $statResult['pending'] ?? 0,
        'resolved' => $statResult['resolved'] ?? 0
    ];
    $stmt->close();

    // --- FIX 3: Report History Query ---
    $histQuery = "SELECT 
                    dr.Damage_Report_ID as id,
                    c.Crop_Name as crop_name,
                    c.Area_Hectares as area,
                    DATE(dr.Report_Date) as date,
                    dr.Damage_Type as damage_type,
                    dr.Damage_Severity as severity,
                    dr.Status as status
                  FROM damage_report dr
                  LEFT JOIN crop c ON dr.Crop_ID = c.Crop_ID
                  WHERE dr.Farmer_ID = ? AND dr.Status != 'Archived'
                  ORDER BY dr.Damage_Report_ID DESC";
                  
    $stmt2 = $conn->prepare($histQuery);
    $stmt2->bind_param("i", $farmer_id);
    $stmt2->execute();
    
    $reports = [];
    $result = $stmt2->get_result();
    while ($row = $result->fetch_assoc()) {
        $reports[] = $row;
    }
    $response['reports'] = $reports;
    $stmt2->close();

    echo json_encode($response);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => safeErrorMessage($e)]);
}
?>