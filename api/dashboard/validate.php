<?php
// api/dashboard/validate.php
session_start();
// This file handles the validation of damage reports by MAO/Admin. It updates the
// status of a report based on the provided ID and new status.
header('Content-Type: application/json');
require_once '../config/db_connection.php';

// This endpoint previously had NO auth check at all - it called session_start()
// and then went straight to the UPDATE, so anyone on the internet could change
// any report's status without logging in. Worse, the audit log below used to
// fall back to "Admin" when no session existed, recording an anonymous
// attacker's action as an Admin action. Same guard the sibling endpoints in
// api/masterfile/damage_report/ already use.
if (!isset($_SESSION['User_ID']) || !in_array($_SESSION['Role'], ['Admin', 'MAO'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized.']);
    exit;
}

$input = json_decode(file_get_contents("php://input"), true);

// We now check for 'id' AND 'status'
if (isset($input['id']) && isset($input['status'])) {
    $report_id = $input['id'];
    $new_status = $input['status'];

    // This endpoint only validates or rejects. 'Approved' deliberately is NOT
    // allowed here - approving must go through approve_with_aid.php so stock is
    // actually deducted; accepting it here would mark a report Approved with no
    // aid and no deduction. Archiving has its own endpoint.
    $allowed_statuses = ['Validated', 'Rejected'];
    if (!in_array($new_status, $allowed_statuses, true)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid status value.']);
        exit;
    }

    // Only a report that isn't already finalised can be validated or rejected.
    // The status condition stops a finished report being dragged back to
    // Validated (which would strand its already-allocated aid) or re-rejected.
    $stmt = $conn->prepare("UPDATE damage_report SET Status = ? WHERE Damage_Report_ID = ? AND Status NOT IN ('Approved','Rejected','Archived')");
    $stmt->bind_param("si", $new_status, $report_id);

    if (!$stmt->execute()) {
        echo json_encode(['status' => 'error', 'message' => safeErrorMessage(new Exception($conn->error))]);
        $stmt->close();
        $conn->close();
        exit;
    }

    if ($stmt->affected_rows === 0) {
        echo json_encode(['status' => 'error', 'message' => 'This report can no longer be changed - it may already be approved, rejected or archived. Please refresh.']);
        $stmt->close();
        $conn->close();
        exit;
    }

    // Log the action
    require_once '../audit/log_action.php';
    // Guaranteed set by the auth check above - no "?? 'Admin'" fallback,
    // which used to attribute unauthenticated changes to an Admin.
    $user_id   = $_SESSION['User_ID'];
    $user_role = $_SESSION['Role'];
    logAction($conn, $user_id, $user_role,
        'Validate Report',
        "Report #$report_id status changed to $new_status",
        'Damage Reports'
    );
    echo json_encode(['status' => 'success', 'message' => 'Status updated to ' . $new_status]);
    $stmt->close();
} else {
    echo json_encode(['status' => 'error', 'message' => 'Missing ID or Status']);
}

$conn->close();
?>