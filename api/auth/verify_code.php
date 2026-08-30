<?php
// File Location: api/auth/verify_code.php
// Confirms the 6-digit code the farmer typed in (shown on-screen after registering,
// and best-effort emailed too - see register.php / mailer.php).
session_start();
header('Content-Type: application/json');
require_once '../config/db_connection.php';

$input = json_decode(file_get_contents("php://input"), true);
$email = trim($input['email'] ?? '');
$code  = trim($input['code'] ?? '');

if (!$email || !$code) {
    echo json_encode(['status' => 'error', 'message' => 'Email and code are required.']);
    exit;
}

$stmt = $conn->prepare("SELECT User_ID, Verification_Token_Expires FROM users WHERE Email = ? AND Verification_Token = ? AND Email_Verified = 0");
$stmt->bind_param("ss", $email, $code);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    echo json_encode(['status' => 'error', 'message' => 'Incorrect code, or this account is already verified.']);
    exit;
}

if (strtotime($user['Verification_Token_Expires']) < time()) {
    echo json_encode(['status' => 'error', 'message' => 'This code has expired. Please register again to get a new one.']);
    exit;
}

$update = $conn->prepare("UPDATE users SET Email_Verified = 1, Verification_Token = NULL, Verification_Token_Expires = NULL WHERE User_ID = ?");
$update->bind_param("i", $user['User_ID']);
$update->execute();
$update->close();

echo json_encode(['status' => 'success', 'message' => 'Email verified! You can now log in.']);
?>