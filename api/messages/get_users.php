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
    $query = "SELECT 
                u.User_ID as id,
                u.Fullname as name,
                u.Role as role,
                (SELECT COUNT(*) FROM messages m 
                 WHERE m.Sender_ID = u.User_ID 
                 AND m.Receiver_ID = $current_user 
                 AND m.Is_Read = 0) as unread
              FROM users u
              WHERE u.User_ID != $current_user
              AND u.STATUS = 'Active'
              ORDER BY u.Role, u.Fullname ASC";

    $result = $conn->query($query);
    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }

    echo json_encode(['status' => 'success', 'data' => $data]);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>