<?php
// File Location: api/profile/get_profile.php
session_start();
header('Content-Type: application/json');
require_once '../config/db_connection.php';

if (!isset($_SESSION['User_ID'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized.']);
    exit;
}

$user_id = $_SESSION['User_ID'];

try {
    $stmt = $conn->prepare(
        "SELECT User_ID, Role, Fullname, Email, Contact_Number, RSBA_No, Assigned_Area, STATUS 
         FROM users WHERE User_ID = ?"
    );
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user) {
        echo json_encode(['status' => 'error', 'message' => 'User not found.']);
        exit;
    }

    echo json_encode(['status' => 'success', 'user' => $user]);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>