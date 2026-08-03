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
    if (!$data || empty($data->id) || empty($data->name)) {
        echo json_encode(["status" => "error", "message" => "Crop ID and name are required."]);
        exit;
    }

    $id          = (int)$data->id;
    $name        = $data->name;
    $type        = $data->type ?? null;
    $period      = $data->period ?? null;
    $prod_cost   = isset($data->prod_cost) ? (float)$data->prod_cost : 0;
    $description = $data->description ?? null;

    $stmt = $conn->prepare("UPDATE crop_type SET Crop_Name = ?, Commodity_Type = ?, Growth_Duration = ?, Standard_Production_Cost = ?, Description = ? WHERE Type_ID = ?");
    $stmt->bind_param("sssdsi", $name, $type, $period, $prod_cost, $description, $id);

    if ($stmt->execute()) {
        echo json_encode(["status" => "success", "message" => "Crop updated successfully."]);
    } else {
        echo json_encode(["status" => "error", "message" => "Update failed: " . $stmt->error]);
    }
    $stmt->close();
} catch (Throwable $e) {
    echo json_encode(["status" => "error", "message" => safeErrorMessage($e)]);
}