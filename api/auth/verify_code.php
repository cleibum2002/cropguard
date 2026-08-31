<?php
// File Location: api/auth/verify_code.php
// Handles THREE distinct cases, distinguished purely by the account's current
// state (no separate "step" parameter needed from the frontend):
//   1. Pending_Email set (email-change on an already-active account) -> finish, done.
//   2. Email_Verified = 0 (brand-new account, first code) -> mark email verified,
//      immediately generate + SMS a phone code, tell frontend to show step 2.
//   3. Email_Verified = 1 but Phone_Verified = 0 (second code entry) -> mark phone
//      verified, fully done, can log in now.
session_start();
header('Content-Type: application/json');
require_once '../config/db_connection.php';
require_once '../config/sms.php';

$input = json_decode(file_get_contents("php://input"), true);
$email = trim($input['email'] ?? '');
$code  = trim($input['code'] ?? '');

if (!$email || !$code) {
    echo json_encode(['status' => 'error', 'message' => 'Email and code are required.']);
    exit;
}

$stmt = $conn->prepare("SELECT User_ID, Fullname, Email, Pending_Email, Contact_Number, Email_Verified, Phone_Verified, Verification_Token_Expires FROM users WHERE (Email = ? OR Pending_Email = ?) AND Verification_Token = ?");
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

// Case 2: first-time EMAIL step for a brand-new account.
if ((int)$user['Email_Verified'] === 0) {
    $phone_code    = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $token_expires = date('Y-m-d H:i:s', strtotime('+30 minutes'));

    $update = $conn->prepare("UPDATE users SET Email_Verified = 1, Verification_Token = ?, Verification_Token_Expires = ? WHERE User_ID = ?");
    $update->bind_param("ssi", $phone_code, $token_expires, $user['User_ID']);
    $update->execute();

    sendVerificationSMS($user['Contact_Number'], $phone_code); // best-effort

    echo json_encode([
        'status'  => 'success',
        'step'    => 'phone',
        'message' => "Email verified! We've sent a code to your phone - enter it below to finish."
    ]);
    exit;
}

// Case 3: second-step PHONE verification.
if ((int)$user['Phone_Verified'] === 0) {
    $update = $conn->prepare("UPDATE users SET Phone_Verified = 1, Verification_Token = NULL, Verification_Token_Expires = NULL WHERE User_ID = ?");
    $update->bind_param("i", $user['User_ID']);
    $update->execute();
    echo json_encode(['status' => 'success', 'step' => 'done', 'message' => 'Phone verified! You can now log in.']);
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'This account is already fully verified.']);
?>