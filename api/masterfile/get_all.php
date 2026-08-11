<?php
// api/masterfile/get_all.php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['User_ID'])) {
    echo json_encode(["status" => "unauthorized", "message" => "Please login first."]);
    exit();
}

require_once '../config/db_connection.php';

// Select all columns including the new 'category'
$sql = "SELECT id, item_name, category, description, status, created_at FROM masterfile_items ORDER BY id DESC";
$result = $conn->query($sql);

$data = [];
if ($result && $result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
}

// Check if data is being sent correctly
echo json_encode(["status" => "success", "data" => $data]);
$conn->close();
?>