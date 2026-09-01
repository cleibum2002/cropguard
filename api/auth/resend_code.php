<?php
// File Location: api/auth/resend_code.php
// Works for whichever step the account is currently stuck on - resends by email
// if Email isn't verified yet, or by SMS if email's done but phone isn't.
session_start();
header('Content-Type: application/json');
require_once '../config/db_connection.php';
require_once '../config/mailer.php';
require_once '../config/sms.php';

$input = json_decode(file_get_contents("php://input"), true);
$email = trim($input['email'] ?? '');

if (!$email) {
    echo json_encode(['status' => 'error', 'message' => 'Email is required.']);
    exit;
}

$stmt = $conn->prepare("SELECT User_ID, Fullname, Contact_Number, Email_Verified, Phone_Verified FROM users WHERE Email = ? AND (Email_Verified = 0 OR Phone_Verified = 0)");
$stmt->bind_param("s", $email);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    echo json_encode(['status' => 'error', 'message' => 'No unverified account found with that email.']);
    exit;
}

$new_code      = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$token_expires = date('Y-m-d H:i:s', strtotime('+30 minutes'));

$update = $conn->prepare("UPDATE users SET Verification_Token = ?, Verification_Token_Expires = ? WHERE User_ID = ?");
$update->bind_param("ssi", $new_code, $token_expires, $user['User_ID']);
$update->execute();
$update->close();

if ((int)$user['Email_Verified'] === 0) {
    sendVerificationEmail($email, $user['Fullname'], $new_code); // best-effort
    echo json_encode(['status' => 'success', 'channel' => 'email', 'message' => 'A new code has been sent to your email.']);
} else {
    sendVerificationSMS($user['Contact_Number'], $new_code); // best-effort
    echo json_encode(['status' => 'success', 'channel' => 'phone', 'message' => 'A new code has been sent to your phone.']);
}
?>