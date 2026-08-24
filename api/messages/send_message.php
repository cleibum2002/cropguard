<?php
session_start();
header('Content-Type: application/json');
require_once '../config/db_connection.php';

if (!isset($_SESSION['User_ID'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized.']);
    exit;
}

function sanitizeHtmlInput($input) {
    return strip_tags(trim($input));
}

$input       = json_decode(file_get_contents('php://input'), true);
$sender_id   = $_SESSION['User_ID'];
$receiver_id = isset($input['receiver_id']) ? (int)$input['receiver_id'] : 0;
$message     = sanitizeHtmlInput($input['message'] ?? '');

if (!$receiver_id || empty($message)) {
    echo json_encode(['status' => 'error', 'message' => 'Missing required fields.']);
    exit;
}

try {
    $stmt = $conn->prepare("INSERT INTO messages (Sender_ID, Receiver_ID, Message) VALUES (?, ?, ?)");
    $stmt->bind_param("iis", $sender_id, $receiver_id, $message);

    if ($stmt->execute()) {
        echo json_encode(['status' => 'success', 'message' => 'Message sent.']);
    } else {
        throw new Exception($stmt->error);
    }
    $stmt->close();

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => safeErrorMessage($e)]);
}
?>