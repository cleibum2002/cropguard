<?php
session_start();
header('Content-Type: application/json');

// FIX: Match the session key used in your login/session_check
if (!isset($_SESSION['User_ID']) && !isset($_SESSION['Admin_ID'])) {
    echo json_encode(["status" => "unauthorized", "message" => "Unauthorized access."]);
    exit();
}

require_once '../config/db_connection.php';

$input = json_decode(file_get_contents("php://input"), true);

if (isset($input['id'])) {
    $id = intval($input['id']);

    // SOFT DELETE: Updates status to Inactive for Audit Trail compliance
    $stmt = $conn->prepare("UPDATE masterfile_items SET status = 'Inactive' WHERE id = ?");
    $stmt->bind_param("i", $id);

    if ($stmt->execute()) {
        echo json_encode(["status" => "success", "message" => "Commodity deactivated."]);
    } else {
        echo json_encode(["status" => "error", "message" => "Database Error: " . $conn->error]);
    }
    $stmt->close();
} else {
    echo json_encode(["status" => "error", "message" => "ID is required."]);
}
$conn->close();
?>