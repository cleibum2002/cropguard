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

if (!empty($data->id) && !empty($data->fullname) && !empty($data->email)) {
    try {
        $contact = $data->contact_number ?? null;

        // If the admin typed a new password, update it. Otherwise, ignore the password column.
        if (!empty($data->password)) {
            $password_error = validatePasswordStrength($data->password);
            if ($password_error) {
                echo json_encode(['status' => 'error', 'message' => $password_error]);
                exit;
            }
            $hashed_password = password_hash($data->password, PASSWORD_DEFAULT);
            $query = "UPDATE users SET Fullname=?, Email=?, Contact_Number=?, Password=?, Role=?, STATUS=? WHERE User_ID=?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("ssssssi", $data->fullname, $data->email, $contact, $hashed_password, $data->role, $data->status, $data->id);
        } else {
            $query = "UPDATE users SET Fullname=?, Email=?, Contact_Number=?, Role=?, STATUS=? WHERE User_ID=?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("sssssi", $data->fullname, $data->email, $contact, $data->role, $data->status, $data->id);
        }
        
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'User updated successfully.']);
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