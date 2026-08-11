<?php
session_start();
header('Content-Type: application/json');
require_once '../config/db_connection.php';

$input = json_decode(file_get_contents("php://input"), true);

if (isset($input['id'], $input['item_name'])) {
    $stmt = $conn->prepare("UPDATE masterfile_items SET item_name=?, category=?, description=?, status=? WHERE id=?");
    $stmt->bind_param("ssssi", $input['item_name'], $input['category'], $input['description'], $input['status'], $input['id']);

    if ($stmt->execute()) echo json_encode(["status" => "success", "message" => "Updated!"]);
    else echo json_encode(["status" => "error", "message" => $conn->error]);
}
?>