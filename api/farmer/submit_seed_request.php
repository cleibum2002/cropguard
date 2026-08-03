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

$data = json_decode(file_get_contents("php://input"), true);

$requested_seed = $data['requested_seed'] ?? '';
$description = $data['description'] ?? '';

if (empty($requested_seed) || empty($description)) {
    echo json_encode(['status' => 'error', 'message' => 'Please fill in all required fields.']);
    exit();
}

try {
    // MySQLi uses ? instead of named parameters
    $query = "INSERT INTO seed_requests (Farmer_ID, Requested_Seed, Description, Status, Request_Date) 
              VALUES (?, ?, ?, 'Pending', NOW())";
              
    $stmt = $conn->prepare($query);
    
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    
    // 'iss' means integer, string, string
    $stmt->bind_param("iss", $farmer_id, $requested_seed, $description);
    
    if ($stmt->execute()) {
        // Notify Admin staff of the new seed request
        $new_request_id = $conn->insert_id;
        $farmer_name = $_SESSION['Fullname'] ?? 'A farmer';
        $admin_msg = "New seed request from {$farmer_name} for \"{$requested_seed}\".";
        $redirect_url = 'admin_seed_requests.html';
        $stmt_admin_notif = $conn->prepare("INSERT INTO admin_notification (Target_Role, Message, Redirect_URL) VALUES ('Admin', ?, ?)");
        $stmt_admin_notif->bind_param("ss", $admin_msg, $redirect_url);
        $stmt_admin_notif->execute();
        $stmt_admin_notif->close();

        echo json_encode(['status' => 'success', 'message' => 'Your request has been submitted to the DA.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Failed to submit request.']);
    }
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => safeErrorMessage($e)]);
}
?>