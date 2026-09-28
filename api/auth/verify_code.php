<?php
// File Location: api/auth/verify_code.php
// Handles THREE distinct cases, distinguished purely by the identifier given
// and the account's current state (no separate "step" parameter needed):
//   1. Pending_Email set (email-change on an already-active account) -> finish, done.
//   2. Phone identifier (a farmer who just registered) -> mark the PHONE verified.
//   3. Email_Verified = 0 (email confirmed from the profile page) -> mark email verified.
session_start();
header('Content-Type: application/json');
require_once '../config/db_connection.php';
require_once '../config/sms.php';   // phMobileVariants()

$input = json_decode(file_get_contents("php://input"), true);
// Still called "email" on the wire: auth.html and profile.html both post that
// key, and it now carries either an address or a mobile number.
$email = trim($input['email'] ?? '');
$code  = trim($input['code'] ?? '');

if (!$email || !$code) {
    echo json_encode(['status' => 'error', 'message' => 'Email or phone number, and the code, are required.']);
    exit;
}

$COLUMNS = "User_ID, Fullname, Email, Pending_Email, Contact_Number, Email_Verified, Phone_Verified, Verification_Token_Expires";
$byPhone = strpos($email, '@') === false;

if ($byPhone) {
    // Matched against all three stored spellings, same as login.
    $variants = phMobileVariants($email);
    if (empty($variants)) {
        echo json_encode(['status' => 'error', 'message' => 'Incorrect code, or this account is already verified.']);
        exit;
    }
    $stmt = $conn->prepare("SELECT $COLUMNS FROM users WHERE Contact_Number IN (?, ?, ?) AND Verification_Token = ?");
    $stmt->bind_param("ssss", $variants[0], $variants[1], $variants[2], $code);
} else {
    $stmt = $conn->prepare("SELECT $COLUMNS FROM users WHERE (Email = ? OR Pending_Email = ?) AND Verification_Token = ?");
    $stmt->bind_param("sss", $email, $email, $code);
}

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

// Case 2: a farmer confirming the phone they registered with. Email_Verified is
// deliberately left alone - they have not verified their email and the profile
// page must still show it as unverified until they do.
if ($byPhone) {
    if ((int)$user['Phone_Verified'] === 1) {
        echo json_encode(['status' => 'error', 'message' => 'This phone number is already verified.']);
        exit;
    }
    $update = $conn->prepare("UPDATE users SET Phone_Verified = 1, Verification_Token = NULL, Verification_Token_Expires = NULL WHERE User_ID = ?");
    $update->bind_param("i", $user['User_ID']);
    $update->execute();
    echo json_encode(['status' => 'success', 'step' => 'done', 'message' => 'Phone number verified! You can now log in.']);
    exit;
}

// Case 3: brand-new account's only verification step.
if ((int)$user['Email_Verified'] === 0) {
    $update = $conn->prepare("UPDATE users SET Email_Verified = 1, Verification_Token = NULL, Verification_Token_Expires = NULL WHERE User_ID = ?");
    $update->bind_param("i", $user['User_ID']);
    $update->execute();
    echo json_encode(['status' => 'success', 'step' => 'done', 'message' => 'Email verified! You can now log in.']);
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'This account is already verified.']);
?>