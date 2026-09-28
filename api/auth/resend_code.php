<?php
// File Location: api/auth/resend_code.php
// Resends the verification code for an account that has not confirmed itself
// through EITHER channel yet. Accepts a phone number or an email address,
// because auth.html calls this automatically whenever a blocked login is
// detected - and a farmer who registered by SMS must not be sent an email.
session_start();
header('Content-Type: application/json');
require_once '../config/db_connection.php';
require_once '../config/mailer.php';
require_once '../config/sms.php';

$input = json_decode(file_get_contents("php://input"), true);
// Key is still "email" on the wire; it now carries either.
$identifier = trim($input['email'] ?? '');

if (!$identifier) {
    echo json_encode(['status' => 'error', 'message' => 'Email or phone number is required.']);
    exit;
}

// Unverified means unverified by BOTH channels - the same test login.php now
// applies. An account that confirmed either one is fully usable and has no
// code to resend.
$COLUMNS  = "User_ID, Fullname, Email, Contact_Number, Email_Verified, Phone_Verified";
$UNVERIFIED = "Email_Verified = 0 AND Phone_Verified = 0";
$byPhone  = strpos($identifier, '@') === false;

if ($byPhone) {
    $variants = phMobileVariants($identifier);
    if (empty($variants)) {
        echo json_encode(['status' => 'error', 'message' => 'No unverified account found with that phone number.']);
        exit;
    }
    $stmt = $conn->prepare("SELECT $COLUMNS FROM users WHERE Contact_Number IN (?, ?, ?) AND $UNVERIFIED");
    $stmt->bind_param("sss", $variants[0], $variants[1], $variants[2]);
} else {
    $stmt = $conn->prepare("SELECT $COLUMNS FROM users WHERE Email = ? AND $UNVERIFIED");
    $stmt->bind_param("s", $identifier);
}

$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Same reasoning as login: Contact_Number is not unique, and resending to a
// number shared by several accounts would be ambiguous about whose code it is.
if (count($rows) > 1) {
    echo json_encode(['status' => 'error', 'message' => 'This phone number is linked to more than one account. Please use your email address instead.']);
    exit;
}

$user = $rows[0] ?? null;
if (!$user) {
    echo json_encode(['status' => 'error', 'message' => 'No unverified account found with those details.']);
    exit;
}

$new_code      = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$token_expires = date('Y-m-d H:i:s', strtotime('+5 minutes'));

$update = $conn->prepare("UPDATE users SET Verification_Token = ?, Verification_Token_Expires = ? WHERE User_ID = ?");
$update->bind_param("ssi", $new_code, $token_expires, $user['User_ID']);
$update->execute();
$update->close();

// Both channels are attempted, exactly as registration does, so whichever one
// the person actually has reaches them. Both are best-effort and neither
// result is checked - a failed send must not look like a failed resend.
$sms_sent = false;
if (!empty($user['Contact_Number'])) {
    $sms_sent = sendVerificationSms($user['Contact_Number'], $new_code);
}
if (!empty($user['Email'])) {
    sendVerificationEmail($user['Email'], $user['Fullname'], $new_code);
}

echo json_encode([
    'status'  => 'success',
    'channel' => $sms_sent ? 'sms' : 'email',
    'message' => $sms_sent
        ? 'A new code has been sent to your phone.'
        : 'A new code has been sent to your email.'
]);
?>
