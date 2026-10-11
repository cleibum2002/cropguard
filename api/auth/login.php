<?php
// api/auth/login.php
session_start();
header('Content-Type: application/json');
require_once '../config/db_connection.php';

// Same 8-character + special-symbol rule enforced at registration and by Admin/MAO's
// user management (api/auth/register.php, api/masterfile/users/create.php|update.php).
// This can ONLY be checked here, at login - passwords are stored as one-way hashes, so
// this is the one moment the real plaintext password is ever available to check against.
function isPasswordWeak($password) {
    if (strlen($password) < 8) return true;
    if (!preg_match('/[!@#$%^&*()_+\-=\[\]{};\'":\\\\|,.<>\/?~`]/', $password)) return true;
    return false;
}

$input = json_decode(file_get_contents("php://input"), true);

if (isset($input['email'], $input['password'])) {
    $email = trim($input['email']);
    $password = $input['password'];

    // Email only. Logging in by phone number was removed along with SMS
    // verification - Contact_Number has no unique key, so it was never a safe
    // login identifier anyway.
    $COLUMNS = "User_ID, Role, Fullname, Password, STATUS, Email_Verified, Phone_Verified, Failed_Login_Attempts, Lockout_Until";

    // BINARY kept: this is how the column has always been matched here.
    $stmt = $conn->prepare("SELECT $COLUMNS FROM users WHERE BINARY Email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $stmt = null;   // the isset() guard further down must not re-close it

    // Not an account yet: a farmer whose registration request is still waiting
    // (api/auth/register.php). Only answered when the password matches, so this
    // tells nobody else whether an email has applied.
    if (!$user) {
        $rq = $conn->prepare("SELECT Password, Email_Verified FROM registration_requests WHERE Email = ?");
        $rq->bind_param("s", $email);
        $rq->execute();
        $req = $rq->get_result()->fetch_assoc();
        $rq->close();
        if ($req && password_verify($password, $req['Password'])) {
            // auth.html opens the code screen when the message contains "verify",
            // so the approval message must not use that word.
            echo json_encode(['status' => 'error', 'message' => (int)$req['Email_Verified'] === 1
                ? 'Your registration is waiting for approval by the DA Admin. You will receive an email once it has been reviewed.'
                : 'Please verify your email before logging in. Check your inbox for the verification code.']);
            $conn->close();
            exit;
        }
    }

    if ($user) {
        $is_valid = false;

        if ($user['STATUS'] === 'Inactive') {
        echo json_encode(['status' => 'error', 'message' => 'Your account has been deactivated. Please contact the administrator.']);
        exit;
        }

        // Locked out after too many wrong passwords - checked before any
        // password comparison at all, so a lockout can't be probed around.
        if (!empty($user['Lockout_Until']) && strtotime($user['Lockout_Until']) > time()) {
            $minutesLeft = (int)ceil((strtotime($user['Lockout_Until']) - time()) / 60);
            echo json_encode(['status' => 'error', 'message' => "Too many failed attempts. Try again in $minutesLeft minute(s)."]);
            exit;
        }

        // 1. Standard Check: Is the password properly hashed?
        if (password_verify($password, $user['Password'])) {
            $is_valid = true;
        } 
        // 2. Migration Check: Is the password still plain text? 
        else if ($password === $user['Password']) {
            $is_valid = true;
            
            // Since it's plain text, let's hash it and update the database right now!
            $new_hash = hashUserPassword($password);
            $update_stmt = $conn->prepare("UPDATE users SET Password = ? WHERE User_ID = ?");
            $update_stmt->bind_param("si", $new_hash, $user['User_ID']);
            $update_stmt->execute();
            $update_stmt->close();
        }

        // If credentials are valid, log them in and redirect based on their Role
        if ($is_valid) {
            $role = $user['Role'];
            $redirect_url = '';

            // Map the roles to their specific dashboard pages
            switch ($role) {
                case 'Admin':
                    $redirect_url = 'views/masterfile/dashboard.html';
                    break;
                case 'Farmer':
                    $redirect_url = 'views/farmers/index.html';
                    break;
                case 'Technician':
                    $redirect_url = 'views/technician/dashboard.html';
                    break;
                case 'MAO':
                    $redirect_url = 'views/mao/index.html';
                    break;
                default:
                    $redirect_url = 'index.html'; // Fallback
            }

            // New accounts verify by email only. Phone_Verified still counts
            // here for one reason: farmers who registered while SMS
            // verification was live confirmed their phone and may never have
            // verified their email. Dropping this check would lock them out of
            // accounts they legitimately own. Nothing sets Phone_Verified any
            // more, so no new account can take this path.
            $emailOk = (int)($user['Email_Verified'] ?? 0) === 1;
            $phoneOk = (int)($user['Phone_Verified'] ?? 0) === 1;

            if (!$emailOk && !$phoneOk) {
                // auth.html decides whether to show the code screen by looking
                // for the word "verify" in this message - there is no status
                // code. Reword with care.
                echo json_encode(['status' => 'error', 'message' => 'Please verify your email before logging in. Check your inbox for the verification code.']);
                exit;
            }

            // Issue a fresh session ID at the moment privileges change. Without
            // this, the pre-login session ID carries over into the authenticated
            // session - so anyone who could plant a PHPSESSID value beforehand
            // (e.g. on a shared office/lab machine) would end up holding a
            // logged-in session. Must come before the session variables below.
            session_regenerate_id(true);

            // Clears any accumulated lockout state now that the real password
            // has been confirmed.
            $resetStmt = $conn->prepare("UPDATE users SET Failed_Login_Attempts = 0, Lockout_Until = NULL WHERE User_ID = ?");
            $resetStmt->bind_param("i", $user['User_ID']);
            $resetStmt->execute();
            $resetStmt->close();

            // Set secure session variables
            $_SESSION['User_ID'] = $user['User_ID'];
            $_SESSION['Fullname'] = $user['Fullname'];
            $_SESSION['Role'] = $role;
            // Re-evaluated fresh on every login using the plaintext just submitted above -
            // this naturally makes the weak-password alert reappear on every login until
            // the person actually changes to a password that passes the rule.
            $_SESSION['Weak_Password'] = isPasswordWeak($password);
            $_SESSION['Weak_Password_Alert_Dismissed'] = false;
            // Starts the server-side idle clock (see api/config/session_policy.php).
            $_SESSION['Last_Activity'] = time();

                        // Log the action
            require_once '../audit/log_action.php';
            logAction($conn, $user['User_ID'], $user['Role'],
                'Login',
                $user['Fullname'] . " logged in as " . $user['Role'],
                'Authentication'
            );
            
            echo json_encode([
                'status' => 'success',
                'message' => "Welcome $role!",
                'redirect' => $redirect_url
            ]);
            // The lookup statement is already closed above. Closing it twice
            // throws under PHP 8, and it would have thrown on every successful
            // login.
            $conn->close();
            exit();
        }
    }
    
    // Wrong password on a real account - count it, and lock the account out
    // after too many in a row. Unknown emails aren't counted (no row to
    // count against), which is fine: this defends the account itself, not
    // against probing which emails exist.
    if ($user) {
        $newCount = (int)($user['Failed_Login_Attempts'] ?? 0) + 1;
        if ($newCount >= 5) {
            $lockUntil = date('Y-m-d H:i:s', strtotime('+15 minutes'));
            $failStmt = $conn->prepare("UPDATE users SET Failed_Login_Attempts = ?, Lockout_Until = ? WHERE User_ID = ?");
            $failStmt->bind_param("isi", $newCount, $lockUntil, $user['User_ID']);
        } else {
            $failStmt = $conn->prepare("UPDATE users SET Failed_Login_Attempts = ? WHERE User_ID = ?");
            $failStmt->bind_param("ii", $newCount, $user['User_ID']);
        }
        $failStmt->execute();
        $failStmt->close();

        // Real accounts only, same as the counter above - logging guesses at
        // made-up emails would just let anyone flood the audit log.
        require_once '../audit/log_action.php';
        logAction($conn, $user['User_ID'], $user['Role'],
            'Failed Login',
            "Wrong password for {$user['Fullname']} (attempt {$newCount} of 5)" . ($newCount >= 5 ? ' - account locked for 15 minutes' : ''),
            'Authentication'
        );
    }

    // The attempt that triggers the lock says so right away, instead of the
    // user only finding out on their next try. (The next try already reveals
    // the lock, so this tells an attacker nothing new.)
    if ($user && $newCount >= 5) {
        echo json_encode(['status' => 'error', 'message' => 'Too many failed attempts. Try again in 15 minute(s).']);
        if(isset($stmt)) $stmt->close();
        $conn->close();
        exit;
    }

    // If user not found or password incorrect
    echo json_encode(['status' => 'error', 'message' => 'Invalid email or password']);
    if(isset($stmt)) $stmt->close();

} else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid input']);
}
$conn->close();
?>