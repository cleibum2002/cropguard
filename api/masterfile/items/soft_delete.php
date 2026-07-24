<?php
// api/masterfile/items/soft_delete.php
ob_start();
session_start();
header('Content-Type: application/json');
require_once '../../config/db_connection.php';
$input = json_decode(file_get_contents("php://input"), true);

if (isset($input['id'])) {
    try {
        $id = intval($input['id']);
        
        // FIXED: Now correctly targets masterfile_items instead of users
        $stmt = $conn->prepare("UPDATE masterfile_items SET status = 'Inactive' WHERE id = ?");
        $stmt->bind_param("i", $id);

        if ($stmt->execute()) {
            if (ob_get_length()) ob_clean();
            echo json_encode(["status" => "success", "message" => "Item deactivated successfully."]);
        } else {
            throw new Exception($conn->error);
        }
    } catch (Exception $e) {
        if (ob_get_length()) ob_clean();
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "No ID provided."]);
}
?>