<?php
// File Location: api/auth/resend_code.php
// Resends the email verification code for an account that hasn't verified yet.
session_start();
header('Content-Type: application/json');
require_once '../config/db_connection.php';
require_once '../config/mailer.php';

$input = json_decode(file_get_contents("php://input"), true);
$email = trim($input['email'] ?? '');

if (!$email) {
    echo json_encode(['status' => 'error', 'message' => 'Email is required.']);
    exit;
}

$stmt = $conn->prepare("SELECT User_ID, Fullname, Contact_Number, Email_Verified FROM users WHERE Email = ? AND Email_Verified = 0");
$stmt->bind_param("s", $email);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    echo json_encode(['status' => 'error', 'message' => 'No unverified account found with that email.']);
    exit;
}

$new_code      = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$token_expires = date('Y-m-d H:i:s', strtotime('+5 minutes'));

$update = $conn->prepare("UPDATE users SET Verification_Token = ?, Verification_Token_Expires = ? WHERE User_ID = ?");
$update->bind_param("ssi", $new_code, $token_expires, $user['User_ID']);
$update->execute();
$update->close();

sendVerificationEmail($email, $user['Fullname'], $new_code); // best-effort
echo json_encode(['status' => 'success', 'channel' => 'email', 'message' => 'A new code has been sent to your email.']);
?>