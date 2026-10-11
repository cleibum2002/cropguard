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
    // Case 3: a new farmer's registration request (not an account yet - see
    // api/auth/register.php). Verifying the email puts it in the Admin's
    // approval queue.
    $rq = $conn->prepare("SELECT Request_ID, First_Name, Last_Name, Suffix, Verification_Token_Expires FROM registration_requests WHERE Email = ? AND Verification_Token = ? AND Email_Verified = 0");
    $rq->bind_param("ss", $email, $code);
    $rq->execute();
    $req = $rq->get_result()->fetch_assoc();
    $rq->close();

    if (!$req) {
        echo json_encode(['status' => 'error', 'message' => 'Incorrect code, or this account is already verified.']);
        exit;
    }
    if (strtotime($req['Verification_Token_Expires']) < time()) {
        echo json_encode(['status' => 'error', 'message' => 'This code has expired. Please request a new one.']);
        exit;
    }

    $up = $conn->prepare("UPDATE registration_requests SET Email_Verified = 1, Verification_Token = NULL, Verification_Token_Expires = NULL, Verified_At = NOW() WHERE Request_ID = ?");
    $up->bind_param("i", $req['Request_ID']);
    $up->execute();
    $up->close();

    $name = trim($req['First_Name'] . ' ' . $req['Last_Name'] . ($req['Suffix'] ? ' ' . $req['Suffix'] : ''));
    $msg  = "New farmer registration request from {$name} - waiting for your approval.";
    $url  = 'farmer_management.html#requests';
    $n = $conn->prepare("INSERT INTO admin_notification (Target_Role, Message, Redirect_URL) VALUES ('Admin', ?, ?)");
    $n->bind_param("ss", $msg, $url);
    $n->execute();
    $n->close();

    echo json_encode([
        'status'  => 'success',
        'step'    => 'pending_approval',
        'message' => 'Email verified! Your registration is now waiting for approval by the DA Admin. You will receive an email once it has been reviewed.'
    ]);
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