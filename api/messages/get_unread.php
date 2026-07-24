<?php
session_start();
header('Content-Type: application/json');
require_once '../config/db_connection.php';

if (!isset($_SESSION['User_ID'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized.']);
    exit;
}

$current_user = $_SESSION['User_ID'];

try {
    $result = $conn->query("SELECT COUNT(*) as unread FROM messages WHERE Receiver_ID = $current_user AND Is_Read = 0");
    $row = $result->fetch_assoc();
    echo json_encode(['status' => 'success', 'unread' => $row['unread']]);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>