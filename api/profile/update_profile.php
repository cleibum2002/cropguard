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

// strip_tags: the name is rendered with innerHTML in staff dashboards and chat,
// and never legitimately contains markup. See api/auth/register.php.
$fullname = strip_tags(trim($input['fullname'] ?? ''));
$email    = trim($input['email']    ?? '');
$contact  = trim($input['contact']  ?? '');
// RSBA_No is deliberately NOT read from the request any more. This endpoint
// accepted it and wrote it with no role check and no format validation, so a
// farmer could POST their own RSBSA and walk straight past the Admin
// verification that gates damage reporting - the disabled input on the profile
// page was the only thing stopping them, and a disabled input stops nobody.
// It is assigned by an Admin through api/masterfile/farmers/update_rsbsa.php,
// which does check the role, the format and uniqueness.
$area     = trim($input['area']     ?? '');
$password = $input['password']      ?? '';
// Re-authentication for the changes that could cost someone their account.
// Holding a logged-in session is not the same as being the account owner - a
// phone left unlocked, or borrowed for a minute, is enough. GitHub, Google and
// Facebook all ask for the password again at this point for the same reason.
$current_password = $input['current_password'] ?? '';

if (!$fullname || !$email) {
    echo json_encode(['status' => 'error', 'message' => 'Full name and email are required.']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid email format.']);
    exit;
}

// The farmer's contact number - MAO and Admin rely on it to reach them. Stored
// in the same normalized 09XXXXXXXXX form registration uses, so the two paths
// don't write two different shapes into one column.
require_once '../config/phone.php';
if ($contact !== '') {
    $normalized_contact = normalizePhMobile($contact);
    if ($normalized_contact === null) {
        echo json_encode(['status' => 'error', 'message' => 'Please enter a valid Philippine mobile number, for example 09171234567.']);
        exit;
    }
    $contact = $normalized_contact;
}

// Same 8-character + special-symbol rule enforced at registration and by
// Admin/MAO's user management (api/auth/register.php, api/masterfile/users/create.php).
if ($password && strlen($password) < 8) {
    echo json_encode(['status' => 'error', 'message' => 'Password must be at least 8 characters long.']);
    exit;
}
if ($password && !preg_match('/[!@#$%^&*()_+\-=\[\]{};\'":\\\\|,.<>\/?~`]/', $password)) {
    echo json_encode(['status' => 'error', 'message' => 'Password must include at least one special character (e.g. ! @ # $ %).']);
    exit;
}

require_once '../config/mailer.php';

try {
    // Check if email is taken by another user
    // Password and Contact_Number are selected so the sensitive-change check
    // below can verify the one and detect a change in the other.
    $checkStmt = $conn->prepare("SELECT User_ID, Email, Role, Password, Contact_Number FROM users WHERE User_ID = ?");
    $checkStmt->bind_param("i", $user_id);
    $checkStmt->execute();
    $currentUser = $checkStmt->get_result()->fetch_assoc();
    $checkStmt->close();

    // Gate the two changes that matter. A new password is an outright account
    // takeover if someone else makes it - there is no self-service reset, so
    // the real owner would have to go to an Admin to get back in. The phone
    // number is gated with it because it is the contact of record and the one
    // the farmer would be reached on.
    //
    // Name, email and assigned area are deliberately NOT gated: a typo fix
    // should not need a password, and a changed email already has to be
    // confirmed by a code sent to the new address.
    $changingPassword = $password !== '';
    // Both sides normalized before comparing. Rows written before the profile
    // page started normalizing still hold +639XXXXXXXXX, so comparing that
    // against the 09XXXXXXXXX form would read as a change every time and
    // demand a password from people who had not touched the field.
    $storedContact   = normalizePhMobile($currentUser['Contact_Number'] ?? '');
    $changingContact = $contact !== '' && $contact !== $storedContact;

    if ($changingPassword || $changingContact) {
        if ($current_password === '' || !password_verify($current_password, $currentUser['Password'])) {
            echo json_encode([
                'status'  => 'error',
                'code'    => 'reauth_failed',
                'message' => 'Your current password is incorrect. Please re-enter it to save this change.'
            ]);
            exit;
        }
    }

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
    $rolesRequiringVerification = ['Farmer', 'MAO', 'Technician', 'Admin'];
    $isFarmer = $currentUser && in_array($currentUser['Role'], $rolesRequiringVerification);
    $new_code = null;

    if ($emailChanged && $isFarmer) {
        // Do NOT touch the real Email or Email_Verified columns yet - the account
        // keeps working normally with its current, already-verified email the whole
        // time. The new address only becomes real once the code is confirmed
        // (see verify_code.php). Closing the prompt without verifying means nothing
        // actually changed - no more silent "bypass" via reload or a second Save.
        $new_code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $token_expires = date('Y-m-d H:i:s', strtotime('+5 minutes'));

        if ($password) {
            $hashed = hashUserPassword($password);
            $stmt   = $conn->prepare(
                "UPDATE users SET Fullname = ?, Contact_Number = ?, Assigned_Area = ?, Password = ?,
                 Pending_Email = ?, Verification_Token = ?, Verification_Token_Expires = ?
                 WHERE User_ID = ?"
            );
            $stmt->bind_param("sssssssi", $fullname, $contact, $area, $hashed, $email, $new_code, $token_expires, $user_id);
        } else {
            $stmt = $conn->prepare(
                "UPDATE users SET Fullname = ?, Contact_Number = ?, Assigned_Area = ?,
                 Pending_Email = ?, Verification_Token = ?, Verification_Token_Expires = ?
                 WHERE User_ID = ?"
            );
            $stmt->bind_param("ssssssi", $fullname, $contact, $area, $email, $new_code, $token_expires, $user_id);
        }
    } else if ($password) {
        $hashed = hashUserPassword($password);
        $stmt   = $conn->prepare(
            "UPDATE users SET Fullname = ?, Email = ?, Contact_Number = ?, Assigned_Area = ?, Password = ? 
             WHERE User_ID = ?"
        );
        $stmt->bind_param("sssssi", $fullname, $email, $contact, $area, $hashed, $user_id);
    } else {
        $stmt = $conn->prepare(
            "UPDATE users SET Fullname = ?, Email = ?, Contact_Number = ?, Assigned_Area = ? 
             WHERE User_ID = ?"
        );
        $stmt->bind_param("ssssi", $fullname, $email, $contact, $area, $user_id);
    }

    if (!$stmt->execute()) throw new Exception($stmt->error);
    $stmt->close();

    // Update session so sidebar name reflects immediately
    $_SESSION['Fullname'] = $fullname;

    if ($emailChanged && $isFarmer) {
        sendVerificationEmail($email, $fullname, $new_code); // best-effort
        // Code intentionally not returned - see the note in api/auth/register.php.
        // Returning it would let someone move their account onto an address they
        // don't control and self-verify it.
        echo json_encode([
            'status' => 'success',
            'message' => 'Profile updated. Your new email needs to be verified before you can log in again.',
            'email_changed' => true
        ]);
    } else {
        echo json_encode(['status' => 'success', 'message' => 'Profile updated successfully.']);
    }

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => safeErrorMessage($e)]);
}
?>