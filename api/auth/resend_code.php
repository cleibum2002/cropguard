<?php
// File Location: api/auth/resend_code.php
// Resends the email verification code for an account that has not verified yet.
// Verification by phone/SMS was removed - see api/config/phone.php.
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

// Keyed on Email_Verified alone, deliberately NOT also on Phone_Verified = 0.
// Two callers: auth.html (login blocked, so both flags are 0) and the profile
// page's "Verify now" button, shown whenever Email_Verified = 0. That second
// caller is used precisely by farmers who registered while SMS verification
// was live - Phone_Verified = 1, email never confirmed. The old query also
// required Phone_Verified = 0, which meant that button always failed for the
// only people it existed for.
$stmt = $conn->prepare("SELECT User_ID, Fullname FROM users WHERE Email = ? AND Email_Verified = 0");
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
echo json_encode(['status' => 'success', 'message' => 'A new code has been sent to your email.']);
?>
