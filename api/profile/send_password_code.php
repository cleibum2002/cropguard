<?php
// File Location: api/profile/send_password_code.php
//
// Panel revision: a new password needs email verification, for every role.
// Step 1 of changing a password on My Profile - after the current password is
// confirmed, a 6-digit code is emailed to the account's own address. Step 2 is
// update_profile.php, which only saves the new password with that code.
//
// The code lives in the server-side session (never sent to the browser), tied
// to this logged-in session, valid 5 minutes, at most 5 wrong tries. No
// database change: users.Verification_Token is left alone because it already
// carries the pending-email code.
session_start();
header('Content-Type: application/json');
require_once '../config/db_connection.php';

if (!isset($_SESSION['User_ID'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$current_password = $input['current_password'] ?? '';

try {
    $st = $conn->prepare("SELECT Fullname, Email, Password FROM users WHERE User_ID = ?");
    $st->bind_param("i", $_SESSION['User_ID']);
    $st->execute();
    $user = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$user) {
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized.']);
        exit;
    }

    // Same re-authentication update_profile.php asks for: a borrowed phone
    // shouldn't be able to trigger codes to the owner's inbox.
    if ($current_password === '' || !password_verify($current_password, $user['Password'])) {
        echo json_encode(['status' => 'error', 'code' => 'reauth_failed', 'message' => 'Your current password is incorrect. Please re-enter it to save this change.']);
        exit;
    }

    // One code a minute is plenty; stops the button being used to spam the inbox.
    $prev = $_SESSION['pw_change_code'] ?? null;
    if ($prev && time() - $prev['sent_at'] < 60) {
        echo json_encode(['status' => 'wait', 'message' => 'A code was sent less than a minute ago. Please check your email.', 'masked_email' => maskEmail($user['Email'])]);
        exit;
    }

    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    require_once '../config/mailer.php';
    // Local XAMPP has no Brevo key, so nothing can be emailed there; the code
    // is still required (it just stays in the session). On Railway the key is
    // set, so a failed send is a real failure and is reported.
    if (!sendPasswordCodeEmail($user['Email'], $user['Fullname'], $code) && BREVO_API_KEY !== '') {
        echo json_encode(['status' => 'error', 'message' => 'We could not send the code to your email right now. Please try again in a moment.']);
        exit;
    }
    $_SESSION['pw_change_code'] = ['code' => $code, 'expires' => time() + 300, 'tries' => 0, 'sent_at' => time()];

    echo json_encode(['status' => 'success', 'masked_email' => maskEmail($user['Email'])]);
} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'message' => safeErrorMessage($e)]);
}

// a******g@gmail.com - enough to recognise your own address, not to harvest it.
function maskEmail($email) {
    [$local, $domain] = array_pad(explode('@', (string)$email, 2), 2, '');
    $n = mb_strlen($local);
    if ($n <= 2) return mb_substr($local, 0, 1) . '*@' . $domain;
    return mb_substr($local, 0, 1) . str_repeat('*', min($n - 2, 8)) . mb_substr($local, -1) . '@' . $domain;
}
?>
