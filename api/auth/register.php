<?php
// File: api/auth/register.php
header('Content-Type: application/json');
require_once '../config/db_connection.php'; // Adjust this path if your config is located elsewhere

function validatePasswordStrength($password) {
    if (strlen($password) < 8) {
        return 'Password must be at least 8 characters long.';
    }
    if (!preg_match('/[!@#$%^&*()_+\-=\[\]{};\'":\\\\|,.<>\/?~`]/', $password)) {
        return 'Password must include at least one special character (e.g. ! @ # $ %).';
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

    // 3. Clean inputs to prevent SQL injection
    $name = $conn->real_escape_string($data->full_name);
    $contact = $conn->real_escape_string($data->contact_number);
    $email = $conn->real_escape_string($data->email);
    // Hash the password securely!
    $hashed_pass = password_hash($data->password, PASSWORD_DEFAULT);
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
    $insert_stmt = $conn->prepare("INSERT INTO users (Role, Fullname, Contact_Number, Email, Password, STATUS) VALUES (?, ?, ?, ?, ?, 'Active')");
    $insert_stmt->bind_param("sssss", $role, $name, $contact, $email, $hashed_pass);

    if ($insert_stmt->execute()) {
        echo json_encode(['status' => 'success', 'message' => 'Registration successful']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Database error: Could not create account.']);
    }

    $insert_stmt->close();

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => safeErrorMessage($e)]);
}

$conn->close();
?>