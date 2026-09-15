<?php
// File Location: api/dashboard/farmer_notifications.php
session_start();
header('Content-Type: application/json');

// FIX: Go up two levels to reach the config folder
if (file_exists('../config/db_connection.php')) {
    require_once '../config/db_connection.php';
} else {
    echo json_encode(['status' => 'error', 'message' => 'Database connection file not found.']);
    exit;
}

$receiver_id = $_SESSION['User_ID'] ?? $_SESSION['UserID'] ?? $_SESSION['id'] ?? null;

if (!$receiver_id) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? 'fetch';

try {
    if ($action === 'fetch') {
        // Fetch the latest 10 notifications for the current logged-in user
        $query = "SELECT Notification_ID, Message, Redirect_URL, Is_Read, DATE_FORMAT(Created_At, '%b %d, %h:%i %p') as DateStr
                  FROM notification
                  WHERE Farmer_ID = ?
                  ORDER BY Created_At DESC LIMIT 10";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $receiver_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $notifications = [];
        $unread_count = 0;
        while ($row = $result->fetch_assoc()) {
            $notifications[] = $row;
            if ($row['Is_Read'] == 0) $unread_count++;
        }
        
        // Clean any accidental output (like PHP warnings) that might break JSON
        if (ob_get_length()) ob_clean();
        
        echo json_encode([
            'status' => 'success', 
            'data' => $notifications, 
            'unread' => $unread_count
        ]);
        $stmt->close();

    } else if ($action === 'read') {
        // Mark all notifications as read for the current logged-in user
        $update = $conn->prepare("UPDATE notification SET Is_Read = 1 WHERE Farmer_ID = ? AND Is_Read = 0");
        $update->bind_param("i", $receiver_id);
        
        if ($update->execute()) {
            if (ob_get_length()) ob_clean();
            echo json_encode(['status' => 'success']);
        }
        $update->close();
    }
} catch (Exception $e) {
    if (ob_get_length()) ob_clean();
    echo json_encode(['status' => 'error', 'message' => safeErrorMessage($e)]);
}