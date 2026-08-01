<?php
// File Location: api/masterfile/damage_report/assign_technician.php
ini_set('display_errors', 0);
session_start();
header("Content-Type: application/json");

if (!isset($_SESSION['User_ID']) || !in_array($_SESSION['Role'], ['Admin', 'MAO'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized.']);
    exit;
}

try {
    include_once '../../config/db_connection.php';

    $json = file_get_contents("php://input");
    $data = json_decode($json, true);

    if (!$data || !isset($data['report_id']) || !isset($data['technician_id']) || !isset($data['visit_date'])) {
        echo json_encode(["status" => "error", "message" => "Required data missing (report_id, technician_id, visit_date)."]);
        exit;
    }

    $reportId  = $data['report_id'];
    $techId    = $data['technician_id'];
    $visitDate = $data['visit_date'];

    // Basic date validation — must not be in the past
    if (strtotime($visitDate) < strtotime('today')) {
        echo json_encode(["status" => "error", "message" => "Visit date must be today or a future date."]);
        exit;
    }

    $query = "UPDATE damage_report 
              SET Technician_ID = ?, 
                  Visit_Date    = ?,
                  Status        = 'Pending' 
              WHERE Damage_Report_ID = ?";

    $stmt = $conn->prepare($query);
    if (!$stmt) throw new Exception($conn->error);

    $stmt->bind_param("isi", $techId, $visitDate, $reportId);

    if ($stmt->execute()) {
        echo json_encode(["status" => "success", "message" => "Technician assigned successfully."]);
    } else {
        throw new Exception($stmt->error);
    }

} catch (Throwable $e) {
    echo json_encode(["status" => "error", "message" => "System Error: " . $e->getMessage()]);
}
?>