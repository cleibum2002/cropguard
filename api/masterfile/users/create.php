<?php
// File Location: api/masterfile/users/create.php
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

function hashUserPassword($password) {
    return password_hash($password, PASSWORD_DEFAULT);
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

if (!empty($data->fullname) && !empty($data->email) && !empty($data->password)) {
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
    try {
        // Hash the password for security
        $hashed_password = hashUserPassword($data->password);
        $contact = $data->contact_number ?? null;
        
        // Email_Verified = 1 immediately - Admin is creating and vouching for this
        // account directly, unlike self-registration which requires the farmer to
        // click a link proving they own the email address.
        $query = "INSERT INTO users (Fullname, Email, Contact_Number, Password, Role, STATUS, Email_Verified) VALUES (?, ?, ?, ?, ?, ?, 1)";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("ssssss", $data->fullname, $data->email, $contact, $hashed_password, $data->role, $data->status);
        
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'New user added successfully.']);
        } else {
            // Check if email already exists
            if ($conn->errno === 1062) {
                echo json_encode(['status' => 'error', 'message' => 'Email address is already in use.']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Failed to add user.']);
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