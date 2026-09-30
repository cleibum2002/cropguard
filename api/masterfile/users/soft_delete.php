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

// An Admin cannot deactivate the account they are logged in with - that locks
// them straight out. Enforced here, not just by hiding the button. Ask another
// Admin to do it if it's genuinely needed.
if (!empty($data->id) && (int)$data->id === (int)$_SESSION['User_ID']) {
    echo json_encode(['status' => 'error', 'message' => "You can't deactivate your own account."]);
    exit;
}

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
        echo json_encode(['status' => 'error', 'message' => safeErrorMessage($e)]);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'User ID is missing.']);
}
?>