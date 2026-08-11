<?php
// api/masterfile/delete.php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['Admin_ID'])) {
    echo json_encode(["status" => "unauthorized", "message" => "Unauthorized access."]);
    exit();
}

require_once '../config/db_connection.php';

$input = json_decode(file_get_contents("php://input"), true);

if (isset($input['id'])) {
    $id = intval($input['id']);

    $stmt = $conn->prepare("DELETE FROM masterfile_items WHERE id = ?");
    $stmt->bind_param("i", $id);

    if ($stmt->execute()) {
        echo json_encode(["status" => "success", "message" => "Record deleted successfully!"]);
    } else {
        echo json_encode(["status" => "error", "message" => "Deletion failed."]);
    }
    $stmt->close();
} else {
    echo json_encode(["status" => "error", "message" => "ID is required."]);
}
$conn->close();
?>