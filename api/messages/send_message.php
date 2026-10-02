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

if (!$receiver_id || $message === '') {
    echo json_encode(['status' => 'error', 'message' => 'Missing required fields.']);
    exit;
}

// You can't message yourself.
if ($receiver_id === (int)$sender_id) {
    echo json_encode(['status' => 'error', 'message' => "You can't send a message to yourself."]);
    exit;
}

// Keep it to a sane length. Message is a TEXT column, but there's no reason a
// chat line should be longer than this, and it stops an abusive payload.
if (mb_strlen($message) > 2000) {
    echo json_encode(['status' => 'error', 'message' => 'Message is too long (maximum 2000 characters).']);
    exit;
}

// The recipient must exist, be active, and be someone this sender is allowed to
// message - the same rules get_users.php uses to build the contact list. The
// contact list hides everyone else, but only on screen; a crafted request could
// name any id.
$recvStmt = $conn->prepare("SELECT Role FROM users WHERE User_ID = ? AND STATUS = 'Active'");
$recvStmt->bind_param("i", $receiver_id);
$recvStmt->execute();
$recv = $recvStmt->get_result()->fetch_assoc();
$recvStmt->close();

if (!$recv) {
    echo json_encode(['status' => 'error', 'message' => 'That user is not available to message.']);
    exit;
}
// Farmers may only message DA staff, never other farmers.
if (($_SESSION['Role'] ?? '') === 'Farmer' && $recv['Role'] === 'Farmer') {
    echo json_encode(['status' => 'error', 'message' => 'Farmers can only message DA staff.']);
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