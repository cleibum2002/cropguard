<?php
// api/dashboard/validate.php
session_start();
// This file handles the validation of damage reports by MAO/Admin. It updates the status of a report based on the provided ID and new status, ensuring that only authorized users can perform this action.
header('Content-Type: application/json');
require_once '../config/db_connection.php';

$input = json_decode(file_get_contents("php://input"), true);

// We now check for 'id' AND 'status'
if (isset($input['id']) && isset($input['status'])) {
    $report_id = $input['id'];
    $new_status = $input['status'];

    // Update the record with the specific status sent from the dashboard
    $stmt = $conn->prepare("UPDATE damage_report SET Status = ? WHERE Damage_Report_ID = ?");
    $stmt->bind_param("si", $new_status, $report_id);

    if ($stmt->execute()) {
        // Log the action
        require_once '../audit/log_action.php';
        $user_id   = $_SESSION['User_ID'] ?? 0;
        $user_role = $_SESSION['Role'] ?? 'Admin';
        logAction($conn, $user_id, $user_role,
            'Validate Report',
            "Report #$report_id status changed to $new_status",
            'Damage Reports'
        );
        echo json_encode(['status' => 'success', 'message' => 'Status updated to ' . $new_status]);
    } else {
        echo json_encode(['status' => 'error', 'message' => $conn->error]);
    }
    $stmt->close();
} else {
    echo json_encode(['status' => 'error', 'message' => 'Missing ID or Status']);
}

$conn->close();
?>