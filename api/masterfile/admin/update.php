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

    if (!empty($data->id) && !empty($data->fullname) && !empty($data->email)) {

        $status = $data->status ?? 'Active';

        if (!empty($data->password)) {
            $hashed = password_hash($data->password, PASSWORD_DEFAULT);
            $stmt   = $conn->prepare(
                "UPDATE users SET Fullname = ?, Email = ?, Password = ?, STATUS = ? WHERE User_ID = ?"
            );
            $stmt->bind_param("ssssi", $data->fullname, $data->email, $hashed, $status, $data->id);
        } else {
            $stmt = $conn->prepare(
                "UPDATE users SET Fullname = ?, Email = ?, STATUS = ? WHERE User_ID = ?"
            );
            $stmt->bind_param("sssi", $data->fullname, $data->email, $status, $data->id);
        }

        if ($stmt->execute()) {
            echo json_encode(["status" => "success", "message" => "User updated successfully."]);
        } else {
            echo json_encode(["status" => "error", "message" => "Failed to update user."]);
        }
        $stmt->close();

    } else {
        echo json_encode(["status" => "error", "message" => "ID, Fullname and Email are required."]);
    }
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => safeErrorMessage($e)]);
}
?>