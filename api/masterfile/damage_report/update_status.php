<?php
// File Location: api/masterfile/damage_report/update_status.php
ini_set('display_errors', 0);
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['User_ID']) || !in_array($_SESSION['Role'], ['Admin', 'MAO'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized.']);
    exit;
}

try {
    include_once '../../config/db_connection.php'; 
    $data = json_decode(file_get_contents("php://input"));

    if (!empty($data->id) && !empty($data->status)) {

        // This endpoint is only ever used to Validate, Reject or Archive. It had
        // NO value check at all, so it would happily write 'Approved' straight
        // into the column - marking a report approved with no aid and no stock
        // deduction. Restrict it to the three it's actually for.
        $allowed = ['Validated', 'Rejected', 'Archived'];
        if (!in_array($data->status, $allowed, true)) {
            echo json_encode(["status" => "error", "message" => "Invalid status value."]);
            exit;
        }

        // The MAO validates and rejects; archiving is Admin only.
        if ($data->status === 'Archived' && ($_SESSION['Role'] ?? '') !== 'Admin') {
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized.']);
            exit;
        }

        // A rejection must say why - it is shown to the farmer in My Recent
        // Reports (Rejection_Reason) and sent in their notification.
        $reason = '';
        if ($data->status === 'Rejected') {
            $reason = trim(preg_replace('/\s+/', ' ', strip_tags((string)($data->reason ?? ''))));
            if (mb_strlen($reason) > 300) $reason = mb_substr($reason, 0, 300);
            if ($reason === '') {
                echo json_encode(["status" => "error", "message" => "Please give a reason for rejecting this report. The farmer will see it."]);
                exit;
            }
        }

        $conn->begin_transaction();

        // 1. Find the farmer
        //    (and the current status, so an archive can record what it was -
        //    restore.php reads that back to undo the archive)
        $findFarmer = $conn->prepare("SELECT Farmer_ID, Status FROM damage_report WHERE Damage_Report_ID = ? FOR UPDATE");
        $findFarmer->bind_param("i", $data->id);
        $findFarmer->execute();
        $findFarmer->bind_result($farmer_id, $old_status);
        $findFarmer->fetch();
        $findFarmer->close();

        if (!$farmer_id) {
            throw new Exception("Farmer not found for this report.");
        }

        // 2. Update the report status, guarded by the current status so a
        //    finalised report can't be dragged backwards.
        //    - Archive: allowed from any state except already-Archived.
        //    - Validate/Reject: only while not yet finalised (Approved/Rejected/
        //      Archived), so approved aid is never stranded.
        //    Validated also records who validated it.
        if ($data->status === 'Archived') {
            $query = "UPDATE damage_report SET Status = ? WHERE Damage_Report_ID = ? AND Status <> 'Archived'";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("si", $data->status, $data->id);
        } else if ($data->status === 'Validated') {
            $query = "UPDATE damage_report SET Status = ?, Validated_By = ? WHERE Damage_Report_ID = ? AND Status NOT IN ('Approved','Rejected','Archived')";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("sii", $data->status, $_SESSION['User_ID'], $data->id);
        } else { // Rejected
            $query = "UPDATE damage_report SET Status = ?, Rejection_Reason = ? WHERE Damage_Report_ID = ? AND Status NOT IN ('Approved','Rejected','Archived')";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("ssi", $data->status, $reason, $data->id);
        }
        $stmt->execute();
        $changed = $stmt->affected_rows;
        $stmt->close();

        if ($changed === 0) {
            throw new Exception("This report can no longer be changed to {$data->status} - its status has already moved on. Please refresh.");
        }

        // 3. SEND NOTIFICATION
        $msg = "Update: Your Damage Report #REP-{$data->id} is now marked as {$data->status}.";

        // Custom message for specific statuses. The MAO's step is shown to
        // farmers as "Approved" (panel revision); the Admin's final step is
        // "Aid Allocated" (approve_with_aid.php).
        if ($data->status === 'Validated') {
            $msg = "Good news! Your Damage Report #REP-{$data->id} has been approved by the MAO. A technician will be scheduled to inspect your farm.";
        } elseif ($data->status === 'Approved') {
            $msg = "Great news! Your Damage Report #REP-{$data->id} has been Approved. Check your assistance ledger for details.";
        } elseif ($data->status === 'Rejected') {
            $msg = $reason !== ''
                ? "Notice: Your Damage Report #REP-{$data->id} was not approved. Reason: {$reason}"
                : "Notice: Your Damage Report #REP-{$data->id} was not approved. Please contact the MAO office for clarification.";
        }

        // Redirect_URL points to the farmer dashboard, where "My Recent Reports"
        // lives - clicking this notification lands them on the page that shows
        // this report, not just a generic "you have a notification".
        $redirectUrl = 'index.html';
        $notif = $conn->prepare("INSERT INTO notification (Farmer_ID, Message, Redirect_URL, Is_Read) VALUES (?, ?, ?, 0)");
        $notif->bind_param("iss", $farmer_id, $msg, $redirectUrl);
        $notif->execute();
        $notif->close();

        $conn->commit();

        $actionNames = ['Validated' => 'Validate Report', 'Rejected' => 'Reject Report', 'Archived' => 'Archive Report'];
        require_once '../../audit/log_action.php';
        logAction($conn, $_SESSION['User_ID'], $_SESSION['Role'],
            $actionNames[$data->status],
            "Report #{$data->id} marked as {$data->status}"
                . ($data->status === 'Archived' && $old_status ? " (was {$old_status})" : '')
                . ($reason !== '' ? ": {$reason}" : ''),
            'Damage Reports'
        );

        echo json_encode(["status" => "success", "message" => "Report status updated and farmer notified."]);
        
    } else {
        echo json_encode(["status" => "error", "message" => "Missing ID or Status."]);
    }
} catch (Throwable $e) {
    if(isset($conn)) $conn->rollback();
    // These reasons are written for the user and are safe to show;
    // anything else (a database error) still stays generic.
    $known = [
        'Farmer not found for this report.',
        'This report can no longer be changed to',
    ];
    $isKnown = false;
    foreach ($known as $k) { if (strpos($e->getMessage(), $k) === 0) { $isKnown = true; break; } }
    echo json_encode(["status" => "error", "message" => $isKnown ? $e->getMessage() : safeErrorMessage($e)]);
}
?>