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

if (!empty($data->fullname) && !empty($data->email) && !empty($data->password)) {
    try {
        // Hash the password for security
        $hashed_password = password_hash($data->password, PASSWORD_DEFAULT);
        $contact = $data->contact_number ?? null;
        
        $query = "INSERT INTO users (Fullname, Email, Contact_Number, Password, Role, STATUS) VALUES (?, ?, ?, ?, ?, ?)";
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
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Missing required fields.']);
}
?>