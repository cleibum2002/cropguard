<?php
// File Location: api/dashboard/mao_notifications.php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['User_ID']) || $_SESSION['Role'] !== 'MAO') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized.']);
    exit;
}

require_once '../config/db_connection.php';

$action = $_GET['action'] ?? 'fetch';

try {
    if ($action === 'fetch') {
        $query = "SELECT Notification_ID, Message, Is_Read, Related_Report_ID, Redirect_URL, DATE_FORMAT(Created_At, '%b %d, %h:%i %p') as DateStr 
                  FROM admin_notification 
                  WHERE Target_Role = 'MAO'
                  ORDER BY Created_At DESC LIMIT 10";
        $result = $conn->query($query);
        if (!$result) throw new Exception($conn->error);

        $notifications = [];
        $unread_count = 0;
        while ($row = $result->fetch_assoc()) {
            $notifications[] = $row;
            if ($row['Is_Read'] == 0) $unread_count++;
        }

        echo json_encode(['status' => 'success', 'data' => $notifications, 'unread' => $unread_count]);

    } else if ($action === 'read') {
        $update = $conn->prepare("UPDATE admin_notification SET Is_Read = 1 WHERE Target_Role = 'MAO' AND Is_Read = 0");
        $update->execute();
        echo json_encode(['status' => 'success']);
        $update->close();
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Unknown action.']);
    }
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'System Error: ' . $e->getMessage()]);
}