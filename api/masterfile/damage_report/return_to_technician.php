<?php
// File Location: api/masterfile/damage_report/return_to_technician.php
ini_set('display_errors', 0);
session_start();
header('Content-Type: application/json');

// Admin only: sending a report back for re-inspection is the Admin's decision.
if (!isset($_SESSION['User_ID']) || ($_SESSION['Role'] ?? '') !== 'Admin') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized.']);
    exit;
}

require_once '../../config/db_connection.php';

$data = json_decode(file_get_contents("php://input"));

if (empty($data->id)) {
    echo json_encode(['status' => 'error', 'message' => 'Missing report ID.']);
    exit;
}

$report_id = (int)$data->id;
$reason = trim($data->reason ?? '');
$reasonNote = $reason !== '' ? $reason : 'Please review and re-submit your field findings.';

try {
    // Reset status back to 'Validated' so it reappears in the technician's Pending
    // Inspection queue, and append the reason to Description so the technician can
    // see why it was returned. Only a report that is currently 'Inspected' can be
    // returned - not one that was already approved, rejected or archived.
    $stmt = $conn->prepare("UPDATE damage_report SET Status = 'Validated', Description = CONCAT(IFNULL(Description, ''), '\n\n--- RETURNED FOR REVISION ---\n', ?) WHERE Damage_Report_ID = ? AND Status = 'Inspected'");
    $stmt->bind_param("si", $reasonNote, $report_id);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected === 0) {
        echo json_encode(['status' => 'error', 'message' => 'This report can only be returned while it is awaiting review after inspection. Please refresh.']);
        exit;
    }

    require_once '../../audit/log_action.php';
    logAction($conn, $_SESSION['User_ID'], $_SESSION['Role'],
        'Return to Technician',
        "Report #{$report_id} returned to the technician for re-inspection: {$reasonNote}",
        'Damage Reports'
    );

    echo json_encode(['status' => 'success', 'message' => 'Report returned to technician for re-inspection.']);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => safeErrorMessage($e)]);
}
?>