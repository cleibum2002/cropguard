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

    if (!empty($data->id)) {
        $query = "UPDATE users SET STATUS = 'Inactive' WHERE User_ID = ?";
        $stmt  = $conn->prepare($query);
        if (!$stmt) throw new Exception($conn->error);
        $stmt->bind_param("i", $data->id);

        if ($stmt->execute()) {
            echo json_encode(["status" => "success", "message" => "User account deactivated successfully."]);
        } else {
            echo json_encode(["status" => "error", "message" => "Failed to deactivate user."]);
        }
    } else {
        echo json_encode(["status" => "error", "message" => "No ID provided."]);
    }
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => safeErrorMessage($e)]);
}
?>