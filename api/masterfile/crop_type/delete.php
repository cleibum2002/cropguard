<?php
session_start();
ini_set('display_errors', 0);
header('Content-Type: application/json');

if (!isset($_SESSION['User_ID']) || $_SESSION['Role'] !== 'Admin') {
    echo json_encode(["status" => "error", "message" => "Unauthorized."]);
    exit;
}

try {
    include_once '../../config/db_connection.php';

    $data = json_decode(file_get_contents("php://input"));
    if (!$data || empty($data->id)) {
        echo json_encode(["status" => "error", "message" => "Crop ID is required."]);
        exit;
    }

    $id = (int)$data->id;

    // Soft delete - matches the Status column crop_type already has,
    // and matches get_all.php's WHERE Status = 'Active' filter.
    $stmt = $conn->prepare("UPDATE crop_type SET Status = 'Inactive' WHERE Type_ID = ?");
    $stmt->bind_param("i", $id);

    if ($stmt->execute()) {
        echo json_encode(["status" => "success", "message" => "Crop removed from masterlist."]);
    } else {
        echo json_encode(["status" => "error", "message" => "Delete failed: " . $stmt->error]);
    }
    $stmt->close();
} catch (Throwable $e) {
    echo json_encode(["status" => "error", "message" => safeErrorMessage($e)]);
}