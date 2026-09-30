<?php
// File Location: api/masterfile/users/update.php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['User_ID']) || $_SESSION['Role'] !== 'Admin') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized.']);
    exit;
}

require_once '../../config/db_connection.php';

$data = json_decode(file_get_contents("php://input"));

function validatePasswordStrength($password) {
    if (strlen($password) < 8) {
        return 'Password must be at least 8 characters long.';
    }
    if (!preg_match('/[!@#$%^&*()_+\-=\[\]{};\'":\\\\|,.<>\/?~`]/', $password)) {
        return 'Password must include at least one special character (e.g. ! @ # $ %).';
    }
    return null;
}

function validateEmailFormat($email) {
    if (strpos($email, '@') === false) {
        return 'Email address must include an @ symbol.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'Email address format is invalid (check the domain, e.g. name@example.com).';
    }
    return null;
}

if (!empty($data->id) && !empty($data->fullname) && !empty($data->email)) {
    $email_error = validateEmailFormat($data->email);
    if ($email_error) {
        echo json_encode(['status' => 'error', 'message' => $email_error]);
        exit;
    }
    try {
        require_once '../../config/mailer.php';
        $contact = $data->contact_number ?? null;

        // Need the CURRENT email on file to know whether Admin is actually
        // changing it - only then does verification apply. Every other edit
        // (name, role, status, contact, password) never needs it.
        $checkStmt = $conn->prepare("SELECT Email, Email_Verified, Role, STATUS FROM users WHERE User_ID = ?");
        $checkStmt->bind_param("i", $data->id);
        $checkStmt->execute();
        $currentUser = $checkStmt->get_result()->fetch_assoc();
        $checkStmt->close();

        if (!$currentUser) {
            echo json_encode(['status' => 'error', 'message' => 'User not found.']);
            exit;
        }

        // An Admin editing their OWN account may change their name, email,
        // contact and password, but never their own Role or Status - that is
        // the self-lockout path (demote yourself to MAO, or deactivate
        // yourself, and you can't get back in). The value is forced back to
        // what is stored rather than erroring, so a legitimate self-edit of the
        // other fields still goes through. The frontend also disables these two
        // controls, but this is the guard that actually holds against a crafted
        // request.
        if ((int)$data->id === (int)$_SESSION['User_ID']) {
            $data->role   = $currentUser['Role'];
            $data->status = $currentUser['STATUS'];
        }

        // If the account was never actually verified in the first place (e.g. a
        // placeholder/wrong email that was never real), there's no working login
        // to protect - going through Pending_Email would just trap the account
        // between two unusable addresses (old: unverified and unreachable, new:
        // invisible to login.php, which only ever checks the real Email column).
        // Treat this like fixing a brand-new account's email instead: set it
        // directly and send a fresh code.
        $wasAlreadyVerified = (int)($currentUser['Email_Verified'] ?? 0) === 1;

        // A different account already owning this email is always an error,
        // whether it's already their real Email or someone else's unconfirmed
        // Pending_Email.
        $checkDupeStmt = $conn->prepare("SELECT User_ID FROM users WHERE (Email = ? OR Pending_Email = ?) AND User_ID != ?");
        $checkDupeStmt->bind_param("ssi", $data->email, $data->email, $data->id);
        $checkDupeStmt->execute();
        if ($checkDupeStmt->get_result()->fetch_assoc()) {
            echo json_encode(['status' => 'error', 'message' => 'Email address is already in use by another account.']);
            exit;
        }
        $checkDupeStmt->close();

        $emailChanged = strcasecmp($currentUser['Email'], $data->email) !== 0;

        $hashed_password = null;
        if (!empty($data->password)) {
            $password_error = validatePasswordStrength($data->password);
            if ($password_error) {
                echo json_encode(['status' => 'error', 'message' => $password_error]);
                exit;
            }
            $hashed_password = password_hash($data->password, PASSWORD_DEFAULT);
        }

        if ($emailChanged && $wasAlreadyVerified) {
            // Same pattern already proven on the Profile page's self-service email
            // change: the real Email column stays untouched (this account keeps
            // logging in with its current, already-verified address) until the new
            // address is actually confirmed via the code sent to it. Admin typing
            // a new email here only proves Admin typed it correctly - not that the
            // intended recipient actually owns that inbox.
            $new_code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $token_expires = date('Y-m-d H:i:s', strtotime('+5 minutes'));

            if ($hashed_password) {
                $query = "UPDATE users SET Fullname=?, Contact_Number=?, Password=?, Role=?, STATUS=?, Pending_Email=?, Verification_Token=?, Verification_Token_Expires=? WHERE User_ID=?";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("ssssssssi", $data->fullname, $contact, $hashed_password, $data->role, $data->status, $data->email, $new_code, $token_expires, $data->id);
            } else {
                $query = "UPDATE users SET Fullname=?, Contact_Number=?, Role=?, STATUS=?, Pending_Email=?, Verification_Token=?, Verification_Token_Expires=? WHERE User_ID=?";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("sssssssi", $data->fullname, $contact, $data->role, $data->status, $data->email, $new_code, $token_expires, $data->id);
            }
        } else if ($emailChanged && !$wasAlreadyVerified) {
            // Account was never verified to begin with - just fix the email
            // directly and send a fresh code, same as a brand-new account.
            $new_code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $token_expires = date('Y-m-d H:i:s', strtotime('+5 minutes'));

            if ($hashed_password) {
                $query = "UPDATE users SET Fullname=?, Email=?, Contact_Number=?, Password=?, Role=?, STATUS=?, Email_Verified=0, Verification_Token=?, Verification_Token_Expires=? WHERE User_ID=?";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("ssssssssi", $data->fullname, $data->email, $contact, $hashed_password, $data->role, $data->status, $new_code, $token_expires, $data->id);
            } else {
                $query = "UPDATE users SET Fullname=?, Email=?, Contact_Number=?, Role=?, STATUS=?, Email_Verified=0, Verification_Token=?, Verification_Token_Expires=? WHERE User_ID=?";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("sssssssi", $data->fullname, $data->email, $contact, $data->role, $data->status, $new_code, $token_expires, $data->id);
            }
        } else {
            if ($hashed_password) {
                $query = "UPDATE users SET Fullname=?, Email=?, Contact_Number=?, Password=?, Role=?, STATUS=? WHERE User_ID=?";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("ssssssi", $data->fullname, $data->email, $contact, $hashed_password, $data->role, $data->status, $data->id);
            } else {
                $query = "UPDATE users SET Fullname=?, Email=?, Contact_Number=?, Role=?, STATUS=? WHERE User_ID=?";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("sssssi", $data->fullname, $data->email, $contact, $data->role, $data->status, $data->id);
            }
        }
        
        if ($stmt->execute()) {
            if ($emailChanged && $wasAlreadyVerified) {
                sendVerificationEmail($data->email, $data->fullname, $new_code); // best-effort
                echo json_encode(['status' => 'success', 'message' => "User updated. Their new email must be verified before they can log in with it."]);
            } else if ($emailChanged && !$wasAlreadyVerified) {
                sendVerificationEmail($data->email, $data->fullname, $new_code); // best-effort
                echo json_encode(['status' => 'success', 'message' => "Email corrected. This account was never verified, so it now needs to verify the new email before logging in for the first time."]);
            } else {
                echo json_encode(['status' => 'success', 'message' => 'User updated successfully.']);
            }
        } else {
            if ($conn->errno === 1062) {
                echo json_encode(['status' => 'error', 'message' => 'Email address is already in use by another account.']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Failed to update user.']);
            }
        }
        $stmt->close();
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => safeErrorMessage($e)]);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Missing required fields.']);
}
?>