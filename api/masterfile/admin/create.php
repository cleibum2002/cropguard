<?php
ini_set('display_errors', 0);
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['User_ID']) || $_SESSION['Role'] !== 'Admin') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized.']);
    exit;
}

try {
    include_once '../../config/db_connection.php';

    $data = json_decode(file_get_contents("php://input"));
    if (!$data) {
        echo json_encode(["status" => "error", "message" => "No data received."]);
        exit;
    }

    if (!empty($data->fullname) && !empty($data->email) && !empty($data->password) && !empty($data->role)) {

        // Check email not already taken
        $check = $conn->prepare("SELECT User_ID FROM users WHERE Email = ?");
        $check->bind_param("s", $data->email);
        $check->execute();
        if ($check->get_result()->fetch_assoc()) {
            echo json_encode(["status" => "error", "message" => "Email is already in use."]);
            exit;
        }
        $check->close();

        $hashed = password_hash($data->password, PASSWORD_DEFAULT);
        $status = $data->status ?? 'Active';
        $role   = $data->role;

        $stmt = $conn->prepare(
            "INSERT INTO users (Fullname, Email, Password, Role, STATUS) VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->bind_param("sssss", $data->fullname, $data->email, $hashed, $role, $status);

        if ($stmt->execute()) {
            echo json_encode(["status" => "success", "message" => "User created successfully."]);
        } else {
            echo json_encode(["status" => "error", "message" => "Failed to create user."]);
        }
        $stmt->close();

    } else {
        echo json_encode(["status" => "error", "message" => "Fullname, Email, Password and Role are required."]);
    }
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => "Database Error: " . $e->getMessage()]);
}
?>