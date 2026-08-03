<?php
session_start();
header('Content-Type: application/json');
require_once '../config/db_connection.php';

if (!isset($_SESSION['User_ID'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized.']);
    exit;
}

$current_user = $_SESSION['User_ID'];
$other_user   = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

if (!$other_user) {
    echo json_encode(['status' => 'error', 'message' => 'Missing user ID.']);
    exit;
}

try {
    $conn->query("UPDATE messages SET Is_Read = 1 
                  WHERE Sender_ID = $other_user 
                  AND Receiver_ID = $current_user 
                  AND Is_Read = 0");

    $query = "SELECT 
                m.Message_ID,
                m.Sender_ID,
                m.Message,
                m.Is_Read,
                m.Created_At,
                u.Fullname as sender_name
              FROM messages m
              JOIN users u ON m.Sender_ID = u.User_ID
              WHERE (m.Sender_ID = $current_user AND m.Receiver_ID = $other_user)
              OR    (m.Sender_ID = $other_user AND m.Receiver_ID = $current_user)
              ORDER BY m.Created_At ASC
              LIMIT 100";

    $result = $conn->query($query);
    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }

    echo json_encode(['status' => 'success', 'data' => $data, 'current_user' => $current_user]);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => safeErrorMessage($e)]);
}
?>