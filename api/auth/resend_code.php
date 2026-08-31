<?php
// File Location: api/auth/resend_code.php
// Generates a fresh 6-digit code and re-sends it, for accounts that are still
// unverified. Used by the "Resend Code" link on the verify screen - covers both
// an expired original code (30-minute window) and an email that never arrived.
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

$stmt = $conn->prepare("SELECT User_ID, Fullname FROM users WHERE Email = ? AND Email_Verified = 0");
$stmt->bind_param("s", $email);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    // Deliberately vague - don't reveal whether this email exists or is already
    // verified, same reasoning as login's generic "invalid email or password".
    echo json_encode(['status' => 'error', 'message' => 'No unverified account found with that email.']);
    exit;
}

$new_code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$token_expires = date('Y-m-d H:i:s', strtotime('+30 minutes'));

$update = $conn->prepare("UPDATE users SET Verification_Token = ?, Verification_Token_Expires = ? WHERE User_ID = ?");
$update->bind_param("ssi", $new_code, $token_expires, $user['User_ID']);
$update->execute();
$update->close();

sendVerificationEmail($email, $user['Fullname'], $new_code); // best-effort

echo json_encode(['status' => 'success', 'message' => 'A new code has been sent to your email.']);
?>