<?php
session_start();
header('Content-Type: application/json');
require_once '../config/db_connection.php';

if (!isset($_SESSION['User_ID']) || $_SESSION['Role'] !== 'Encoder') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized.']);
    exit;
}

try {
    $result = $conn->query("SELECT User_ID as id, Fullname as name FROM users WHERE Role = 'Farmer' ORDER BY Fullname ASC");
    if (!$result) throw new Exception($conn->error);

    $data = [];
    while ($row = $result->fetch_assoc()) { $data[] = $row; }

    echo json_encode(['status' => 'success', 'data' => $data]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => safeErrorMessage($e)]);
}
?>