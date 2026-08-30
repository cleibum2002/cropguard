<?php
// File Location: api/profile/update_profile.php
session_start();
header('Content-Type: application/json');
require_once '../config/db_connection.php';

function hashUserPassword($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

if (!isset($_SESSION['User_ID'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized.']);
    exit;
}

$user_id = $_SESSION['User_ID'];
$input   = json_decode(file_get_contents('php://input'), true);

$fullname = trim($input['fullname'] ?? '');
$email    = trim($input['email']    ?? '');
$contact  = trim($input['contact']  ?? '');
$rsba     = trim($input['rsba']     ?? '');
$area     = trim($input['area']     ?? '');
$password = $input['password']      ?? '';

if (!$fullname || !$email) {
    echo json_encode(['status' => 'error', 'message' => 'Full name and email are required.']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid email format.']);
    exit;
}

if ($password && strlen($password) < 6) {
    echo json_encode(['status' => 'error', 'message' => 'Password must be at least 6 characters.']);
    exit;
}

require_once '../config/mailer.php';

try {
    // Check if email is taken by another user
    $checkStmt = $conn->prepare("SELECT User_ID, Email, Role FROM users WHERE User_ID = ?");
    $checkStmt->bind_param("i", $user_id);
    $checkStmt->execute();
    $currentUser = $checkStmt->get_result()->fetch_assoc();
    $checkStmt->close();

    $checkDupeStmt = $conn->prepare("SELECT User_ID FROM users WHERE Email = ? AND User_ID != ?");
    $checkDupeStmt->bind_param("si", $email, $user_id);
    $checkDupeStmt->execute();
    if ($checkDupeStmt->get_result()->fetch_assoc()) {
        throw new Exception("Email is already in use by another account.");
    }
    $checkDupeStmt->close();

    // If the email actually changed, the new one must be re-verified before this
    // account can log in again - otherwise "verified" would silently apply to an
    // address that was never actually confirmed. Only Farmers go through this at
    // all, matching login.php's verification gate.
    $emailChanged = $currentUser && strcasecmp($currentUser['Email'], $email) !== 0;
    $isFarmer = $currentUser && $currentUser['Role'] === 'Farmer';
    $new_code = null;

    if ($emailChanged && $isFarmer) {
        $new_code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $token_expires = date('Y-m-d H:i:s', strtotime('+30 minutes'));

        if ($password) {
            $hashed = hashUserPassword($password);
            $stmt   = $conn->prepare(
                "UPDATE users SET Fullname = ?, Email = ?, Contact_Number = ?, RSBA_No = ?, Assigned_Area = ?, Password = ?,
                 Email_Verified = 0, Verification_Token = ?, Verification_Token_Expires = ?
                 WHERE User_ID = ?"
            );
            $stmt->bind_param("ssssssssi", $fullname, $email, $contact, $rsba, $area, $hashed, $new_code, $token_expires, $user_id);
        } else {
            $stmt = $conn->prepare(
                "UPDATE users SET Fullname = ?, Email = ?, Contact_Number = ?, RSBA_No = ?, Assigned_Area = ?,
                 Email_Verified = 0, Verification_Token = ?, Verification_Token_Expires = ?
                 WHERE User_ID = ?"
            );
            $stmt->bind_param("sssssssi", $fullname, $email, $contact, $rsba, $area, $new_code, $token_expires, $user_id);
        }
    } else if ($password) {
        $hashed = hashUserPassword($password);
        $stmt   = $conn->prepare(
            "UPDATE users SET Fullname = ?, Email = ?, Contact_Number = ?, RSBA_No = ?, Assigned_Area = ?, Password = ? 
             WHERE User_ID = ?"
        );
        $stmt->bind_param("ssssssi", $fullname, $email, $contact, $rsba, $area, $hashed, $user_id);
    } else {
        $stmt = $conn->prepare(
            "UPDATE users SET Fullname = ?, Email = ?, Contact_Number = ?, RSBA_No = ?, Assigned_Area = ? 
             WHERE User_ID = ?"
        );
        $stmt->bind_param("sssssi", $fullname, $email, $contact, $rsba, $area, $user_id);
    }

    if (!$stmt->execute()) throw new Exception($stmt->error);
    $stmt->close();

    // Update session so sidebar name reflects immediately
    $_SESSION['Fullname'] = $fullname;

    if ($emailChanged && $isFarmer) {
        sendVerificationEmail($email, $fullname, $new_code); // best-effort
        echo json_encode([
            'status' => 'success',
            'message' => 'Profile updated. Your new email needs to be verified before you can log in again.',
            'email_changed' => true,
            'verification_code' => $new_code
        ]);
    } else {
        echo json_encode(['status' => 'success', 'message' => 'Profile updated successfully.']);
    }

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => safeErrorMessage($e)]);
}
?>