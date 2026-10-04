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

    // The chosen user must actually be a Technician - the dropdown only lists
    // them, but the request could name any User_ID.
    $techCheck = $conn->prepare("SELECT User_ID FROM users WHERE User_ID = ? AND Role = 'Technician' AND STATUS = 'Active'");
    $techCheck->bind_param("i", $techId);
    $techCheck->execute();
    if (!$techCheck->get_result()->fetch_assoc()) {
        $techCheck->close();
        echo json_encode(["status" => "error", "message" => "Please choose a valid, active technician."]);
        exit;
    }
    $techCheck->close();

    // Only a validated report can be assigned a technician. Checked with a
    // SELECT rather than an affected-rows count, so re-assigning the same
    // technician and date (which changes no columns) isn't mistaken for a
    // failure. Stops an already-inspected/approved/rejected/archived report
    // being dragged back to 'Validated'.
    $statusStmt = $conn->prepare("SELECT Status FROM damage_report WHERE Damage_Report_ID = ?");
    $statusStmt->bind_param("i", $reportId);
    $statusStmt->execute();
    $statusRow = $statusStmt->get_result()->fetch_assoc();
    $statusStmt->close();

    if (!$statusRow) {
        echo json_encode(["status" => "error", "message" => "Report not found."]);
        exit;
    }
    if ($statusRow['Status'] !== 'Validated') {
        echo json_encode(["status" => "error", "message" => "This report can't be assigned right now - it must be validated first, and not already inspected or finalised. Please refresh."]);
        exit;
    }

    $query = "UPDATE damage_report
              SET Technician_ID = ?,
                  Visit_Date    = ?,
                  Status        = 'Validated'
              WHERE Damage_Report_ID = ? AND Status = 'Validated'";

    $stmt = $conn->prepare($query);
    if (!$stmt) throw new Exception($conn->error);

    $stmt->bind_param("isi", $techId, $visitDate, $reportId);

    if ($stmt->execute()) {
        $techName = $conn->prepare("SELECT Fullname FROM users WHERE User_ID = ?");
        $techName->bind_param("i", $techId);
        $techName->execute();
        $tech = $techName->get_result()->fetch_assoc();
        $techName->close();
        require_once '../../audit/log_action.php';
        logAction($conn, $_SESSION['User_ID'], $_SESSION['Role'],
            'Assign Technician',
            "Report #{$reportId} assigned to " . ($tech['Fullname'] ?? "technician #{$techId}") . " for a field visit on {$visitDate}",
            'Damage Reports'
        );
        echo json_encode(["status" => "success", "message" => "Technician assigned successfully."]);
    } else {
        throw new Exception($stmt->error);
    }
    $stmt->close();

} catch (Throwable $e) {
    echo json_encode(["status" => "error", "message" => safeErrorMessage($e)]);
}
?>