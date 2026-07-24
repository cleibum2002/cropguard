<?php
session_start();
header('Content-Type: application/json');
// From api/masterfile/admin/
// ../ gets you to api/masterfile/
// ../ gets you to api/
// /config gets you to the config folder
require_once '../../config/db_connection.php';

// Check if Role is Admin
if (!isset($_SESSION['Role']) || $_SESSION['Role'] !== 'Admin') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access. Admin only.']);
    exit();
}

try {
    // Join with users table to get the Farmer's full name
    $query = "SELECT sr.Request_ID, sr.Farmer_ID, sr.Requested_Seed, sr.Description, sr.Status, 
                     DATE_FORMAT(sr.Request_Date, '%M %d, %Y') as DateStr, 
                     u.Fullname as Farmer_Name
              FROM seed_requests sr
              JOIN users u ON sr.Farmer_ID = u.User_ID
              ORDER BY FIELD(sr.Status, 'Pending', 'Approved by Admin', 'Ready for Pickup', 'Claimed', 'Rejected'), sr.Request_Date DESC";
              
    $stmt = $conn->prepare($query);
    
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    $requests = $result->fetch_all(MYSQLI_ASSOC);
    
    echo json_encode(['status' => 'success', 'data' => $requests]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
?>