<?php
// File Location: api/auth/verify_code.php
// Handles TWO distinct cases, distinguished purely by the account's current
// state (no separate "step" parameter needed):
//   1. Pending_Email set (email-change on an already-active account) -> finish, done.
//   2. Email_Verified = 0 (new account, or an email confirmed from the profile
//      page) -> mark email verified.
// Verification by phone/SMS was removed - see api/config/phone.php.
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

$COLUMNS = "User_ID, Fullname, Email, Pending_Email, Email_Verified, Verification_Token_Expires";
$stmt = $conn->prepare("SELECT $COLUMNS FROM users WHERE (Email = ? OR Pending_Email = ?) AND Verification_Token = ?");
$stmt->bind_param("sss", $email, $email, $code);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    echo json_encode(['status' => 'error', 'message' => 'Incorrect code, or this account is already verified.']);
    exit;
}

if (strtotime($user['Verification_Token_Expires']) < time()) {
    echo json_encode(['status' => 'error', 'message' => 'This code has expired. Please request a new one.']);
    exit;
}

// Case 1: email-change on an already-active account - unaffected by the phone step.
if ($user['Pending_Email'] !== null && $user['Pending_Email'] === $email) {
    $update = $conn->prepare("UPDATE users SET Email = ?, Pending_Email = NULL, Verification_Token = NULL, Verification_Token_Expires = NULL WHERE User_ID = ?");
    $update->bind_param("si", $email, $user['User_ID']);
    $update->execute();
    echo json_encode(['status' => 'success', 'step' => 'done', 'message' => 'Email verified! You can now log in.']);
    exit;
}

// Case 2: confirming the account's email.
if ((int)$user['Email_Verified'] === 0) {
    $update = $conn->prepare("UPDATE users SET Email_Verified = 1, Verification_Token = NULL, Verification_Token_Expires = NULL WHERE User_ID = ?");
    $update->bind_param("i", $user['User_ID']);
    $update->execute();
    echo json_encode(['status' => 'success', 'step' => 'done', 'message' => 'Email verified! You can now log in.']);
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'This account is already verified.']);
?>