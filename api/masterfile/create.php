<?php
session_start();
header('Content-Type: application/json');
require_once '../config/db_connection.php';

$input = json_decode(file_get_contents("php://input"), true);

if (isset($input['item_name'])) {
    $name = $input['item_name'];
    $cat  = $input['category'] ?? 'General'; // Now saving category
    $desc = $input['description'] ?? '';
    $stat = $input['status'] ?? 'Active';

    $stmt = $conn->prepare("INSERT INTO masterfile_items (item_name, category, description, status) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssss", $name, $cat, $desc, $stat);

    if ($stmt->execute()) echo json_encode(["status" => "success", "message" => "Added!"]);
    else echo json_encode(["status" => "error", "message" => $conn->error]);
}
?>