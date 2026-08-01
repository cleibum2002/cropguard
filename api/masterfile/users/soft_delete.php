<?php
// File Location: api/masterfile/users/soft_delete.php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['User_ID']) || $_SESSION['Role'] !== 'Admin') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized.']);
    exit;
}

require_once '../../config/db_connection.php';

$data = json_decode(file_get_contents("php://input"));

if (!empty($data->id)) {
    try {
        // We do a 'soft delete' by changing the status to Inactive, preventing them from logging in.
        $query = "UPDATE users SET STATUS = 'Inactive' WHERE User_ID = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $data->id);
        
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'User access revoked.']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to deactivate user.']);
        }
        $stmt->close();
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'User ID is missing.']);
}
?>