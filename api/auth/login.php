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

    // --- ONLY ONE QUERY NEEDED NOW ---
    $stmt = $conn->prepare("SELECT User_ID, Role, Fullname, Password, STATUS, Email_Verified FROM users WHERE BINARY Email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $res = $stmt->get_result();
    $user = $res->fetch_assoc();

    if ($user) {
        $is_valid = false;

        if ($user['STATUS'] === 'Inactive') {
        echo json_encode(['status' => 'error', 'message' => 'Your account has been deactivated. Please contact the administrator.']);
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
                case 'Encoder':
                    $redirect_url = 'views/encoder/dashboard.html';
                    break;
                case 'MAO':
                    $redirect_url = 'views/mao/index.html';
                    break;
                default:
                    $redirect_url = 'index.html'; // Fallback
            }

            // Farmers must verify their email before logging in (Admin-created staff
            // accounts are auto-verified at creation - see users/create.php).
            if ($role === 'Farmer' && isset($user['Email_Verified']) && (int)$user['Email_Verified'] !== 1) {
                echo json_encode(['status' => 'error', 'message' => 'Please verify your email before logging in. Check your inbox for the verification link.']);
                exit;
            }

            // Set secure session variables
            $_SESSION['User_ID'] = $user['User_ID'];
            $_SESSION['Fullname'] = $user['Fullname'];
            $_SESSION['Role'] = $role;
            // Re-evaluated fresh on every login using the plaintext just submitted above -
            // this naturally makes the weak-password alert reappear on every login until
            // the person actually changes to a password that passes the rule.
            $_SESSION['Weak_Password'] = isPasswordWeak($password);
            $_SESSION['Weak_Password_Alert_Dismissed'] = false;

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
            $stmt->close();
            $conn->close();
            exit();
        }
    }
    
    // If user not found or password incorrect
    echo json_encode(['status' => 'error', 'message' => 'Invalid email or password']);
    if(isset($stmt)) $stmt->close();

} else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid input']);
}
$conn->close();
?>