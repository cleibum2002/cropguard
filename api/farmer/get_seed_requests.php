<?php
session_start();
header('Content-Type: application/json');

require_once '../config/db_connection.php';

// Check if Role is Farmer
if (!isset($_SESSION['Role']) || $_SESSION['Role'] !== 'Farmer') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
    exit();
}

// Get the User_ID
$farmer_id = $_SESSION['User_ID'] ?? null;

if (!$farmer_id) {
    echo json_encode(['status' => 'error', 'message' => 'Session ID missing. Please log in again.']);
    exit();
}

try {
    // MySQLi uses ? instead of named parameters
    $query = "SELECT Request_ID, Requested_Seed, Description, Status, DATE_FORMAT(Request_Date, '%Y-%m-%d') as DateStr 
              FROM seed_requests 
              WHERE Farmer_ID = ? 
              ORDER BY Request_Date DESC";
              
    $stmt = $conn->prepare($query);
    
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    
    // 'i' means integer
    $stmt->bind_param("i", $farmer_id);
    $stmt->execute();
    
    $result = $stmt->get_result();
    $requests = $result->fetch_all(MYSQLI_ASSOC);
    
    echo json_encode(['status' => 'success', 'data' => $requests]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
?>