<?php
// File: api/auth/register.php
header('Content-Type: application/json');
require_once '../config/db_connection.php'; // Adjust this path if your config is located elsewhere
require_once '../config/mailer.php';

function validatePasswordStrength($password) {
    if (strlen($password) < 8) {
        return 'Password must be at least 8 characters long.';
    }
    if (!preg_match('/[!@#$%^&*()_+\-=\[\]{};\'":\\\\|,.<>\/?~`]/', $password)) {
        return 'Password must include at least one special character (e.g. ! @ # $ %).';
    }
    return null;
}

function hashUserPassword($password) {
    // password_hash() with PASSWORD_DEFAULT (bcrypt) already returns a salted,
    // 60-character hash string - this just gives it the expected name.
    return password_hash($password, PASSWORD_DEFAULT);
}

function validateEmailFormat($email) {
    // Rejects structural errors specifically: missing @ symbol, or missing/incomplete domain extension.
    // filter_var's FILTER_VALIDATE_EMAIL already enforces both of these correctly (it's the
    // standard, RFC-822-aware way to do this in PHP) - this wrapper just gives it the name
    // your test plan checks for, and a clear message for each failure case.
    if (strpos($email, '@') === false) {
        return 'Email address must include an @ symbol.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'Email address format is invalid (check the domain, e.g. name@example.com).';
    }
    return null;
}

try {
    // 1. Get the JSON payload
    $data = json_decode(file_get_contents("php://input"));

    // 2. Validate inputs
    if (empty($data->full_name) || empty($data->contact_number) || empty($data->email) || empty($data->password)) {
        echo json_encode(['status' => 'error', 'message' => 'Please fill in all required fields.']);
        exit;
    }

    $password_error = validatePasswordStrength($data->password);
    if ($password_error) {
        echo json_encode(['status' => 'error', 'message' => $password_error]);
        exit;
    }

    $email_error = validateEmailFormat($data->email);
    if ($email_error) {
        echo json_encode(['status' => 'error', 'message' => $email_error]);
        exit;
    }

    // 3. Clean inputs to prevent SQL injection
    $name = $conn->real_escape_string($data->full_name);
    $contact = $conn->real_escape_string($data->contact_number);
    $email = $conn->real_escape_string($data->email);
    // Hash the password securely!
    $hashed_pass = hashUserPassword($data->password);
    $role = 'Farmer';

    // 4. Check if the email is already registered (users table, not Farmer)
    $check_stmt = $conn->prepare("SELECT User_ID FROM users WHERE Email = ?");
    $check_stmt->bind_param("s", $email);
    $check_stmt->execute();
    $result = $check_stmt->get_result();

    if ($result->num_rows > 0) {
        echo json_encode(['status' => 'error', 'message' => 'This email is already registered.']);
        $check_stmt->close();
        exit;
    }
    $check_stmt->close();

    // 5. Insert the new Farmer into the users table with Role = 'Farmer'
    // Email_Verified starts at 0 - they can't log in until they click the link sent below.
    $verification_token = bin2hex(random_bytes(32));
    $token_expires = date('Y-m-d H:i:s', strtotime('+24 hours'));

    $insert_stmt = $conn->prepare("INSERT INTO users (Role, Fullname, Contact_Number, Email, Password, STATUS, Email_Verified, Verification_Token, Verification_Token_Expires) VALUES (?, ?, ?, ?, ?, 'Active', 0, ?, ?)");
    $insert_stmt->bind_param("sssssss", $role, $name, $contact, $email, $hashed_pass, $verification_token, $token_expires);

    if ($insert_stmt->execute()) {
        $emailSent = sendVerificationEmail($email, $name, $verification_token);
        if ($emailSent) {
            echo json_encode(['status' => 'success', 'message' => 'Registration successful! Please check your email to verify your account before logging in.']);
        } else {
            echo json_encode(['status' => 'success', 'message' => 'Registration successful, but the verification email could not be sent. Please contact support.']);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Database error: Could not create account.']);
    }

    $insert_stmt->close();

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => safeErrorMessage($e)]);
}

$conn->close();
?>