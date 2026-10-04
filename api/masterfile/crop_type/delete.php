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
        $crop = $conn->prepare("SELECT Crop_Name FROM crop_type WHERE Type_ID = ?");
        $crop->bind_param("i", $id);
        $crop->execute();
        $cropName = $crop->get_result()->fetch_assoc()['Crop_Name'] ?? '';
        $crop->close();
        require_once '../../audit/log_action.php';
        logAction($conn, $_SESSION['User_ID'], $_SESSION['Role'],
            'Remove Crop',
            "Removed crop #{$id} {$cropName} from the masterlist",
            'Crops Masterlist'
        );
        echo json_encode(["status" => "success", "message" => "Crop removed from masterlist."]);
    } else {
        echo json_encode(["status" => "error", "message" => "Delete failed: " . $stmt->error]);
    }
    $stmt->close();
} catch (Throwable $e) {
    echo json_encode(["status" => "error", "message" => safeErrorMessage($e)]);
}