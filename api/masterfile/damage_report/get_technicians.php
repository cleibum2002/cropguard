<?php
// File Location: api/masterfile/damage_report/get_technicians.php
// UPDATED: Now fetches technicians from the unified 'users' table
session_start();
require_once '../../config/db_connection.php';
header('Content-Type: application/json');

if (!isset($_SESSION['User_ID']) || !in_array($_SESSION['Role'], ['Admin', 'MAO'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized.']);
    exit;
}

// We filter by Role = 'Technician' to get the correct list for the Admin dropdown
$query = "SELECT User_ID as id, Fullname as name, Assigned_Area as area 
          FROM users 
          WHERE Role = 'Technician' 
          ORDER BY Fullname ASC";

$result = $conn->query($query);

$data = [];
if ($result) {
    while($row = $result->fetch_assoc()) { 
        $data[] = $row; 
    }
    echo json_encode(['status' => 'success', 'data' => $data]);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Query failed: ' . $conn->error]);
}
?>