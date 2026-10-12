<?php
// File Location: api/masterfile/damage_report/restore.php
// Brings an Admin-archived damage report back to the status it had before it
// was archived. No schema change: the earlier status is read from the audit
// log line written when it was archived ("... (was Rejected)"), or, for
// reports archived before that note existed, worked out from the report's own
// data. Reports the farmer withdrew themselves are NOT restorable.
ini_set('display_errors', 0);
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['User_ID']) || ($_SESSION['Role'] ?? '') !== 'Admin') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized.']);
    exit;
}

try {
    include_once '../../config/db_connection.php';
    $data = json_decode(file_get_contents("php://input"));
    $id = (int)($data->id ?? 0);
    if ($id <= 0) {
        echo json_encode(["status" => "error", "message" => "Missing report ID."]);
        exit;
    }

    $conn->begin_transaction();

    $stmt = $conn->prepare("SELECT Farmer_ID, Status, Description, Technician_ID, Validated_By FROM damage_report WHERE Damage_Report_ID = ? FOR UPDATE");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $report = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$report) throw new Exception("Damage report not found.");
    if ($report['Status'] !== 'Archived') throw new Exception("This report is not archived. Please refresh.");

    // Who archived it last - the Admin, or the farmer deleting their own pending report?
    $archivedLike = "Report #{$id} marked as Archived%";
    $deletedLike  = "%damage report #{$id}";
    $stmt = $conn->prepare("SELECT Action, Description FROM system_logs
                            WHERE (Action = 'Archive Report' AND Description LIKE ?)
                               OR (Action = 'Delete Report' AND Description LIKE ?)
                            ORDER BY Log_ID DESC LIMIT 1");
    $stmt->bind_param("ss", $archivedLike, $deletedLike);
    $stmt->execute();
    $last = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$last || $last['Action'] === 'Delete Report') {
        throw new Exception("This report was withdrawn by the farmer, so it cannot be restored.");
    }

    $valid = ['Pending', 'Validated', 'Inspected', 'Approved', 'Rejected'];
    $previous = null;
    if (preg_match('/\(was (\w+)\)/', $last['Description'], $m) && in_array($m[1], $valid, true)) {
        $previous = $m[1];
    }

    if ($previous === null) {
        // Archived before the "(was ...)" note existed - work it out from the data.
        $stmt = $conn->prepare("SELECT COUNT(*) AS n FROM aid_distribution WHERE Damage_Report_ID = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $hasAid = (int)$stmt->get_result()->fetch_assoc()['n'] > 0;
        $stmt->close();

        // Prefix match: a rejection may carry ": <reason>" after this text.
        $rejLike1 = "Report #{$id} marked as Rejected%";
        $rejLike2 = "Report #{$id} status changed to Rejected%";
        $stmt = $conn->prepare("SELECT COUNT(*) AS n FROM system_logs WHERE Description LIKE ? OR Description LIKE ?");
        $stmt->bind_param("ss", $rejLike1, $rejLike2);
        $stmt->execute();
        $wasRejected = (int)$stmt->get_result()->fetch_assoc()['n'] > 0;
        $stmt->close();

        $desc = $report['Description'] ?? '';
        $techPos     = strrpos($desc, '--- TECH FINDINGS ---');
        $returnedPos = strrpos($desc, '--- RETURNED FOR REVISION ---');

        if ($hasAid)                                                        $previous = 'Approved';
        else if ($wasRejected)                                              $previous = 'Rejected';
        else if ($techPos !== false && ($returnedPos === false || $techPos > $returnedPos)) $previous = 'Inspected';
        else if (!empty($report['Technician_ID']) || !empty($report['Validated_By']))       $previous = 'Validated';
        else                                                                $previous = 'Pending';
    }

    $stmt = $conn->prepare("UPDATE damage_report SET Status = ? WHERE Damage_Report_ID = ? AND Status = 'Archived'");
    $stmt->bind_param("si", $previous, $id);
    $stmt->execute();
    $changed = $stmt->affected_rows;
    $stmt->close();
    if ($changed === 0) throw new Exception("This report is not archived. Please refresh.");

    // Same words the farmer sees in My Recent Reports (panel revision).
    $shownAs = ['Validated' => 'Approved', 'Approved' => 'Aid Allocated'][$previous] ?? $previous;
    $msg = "Update: Your Damage Report #REP-{$id} has been restored and is now marked as {$shownAs}.";
    $redirectUrl = 'index.html';
    $notif = $conn->prepare("INSERT INTO notification (Farmer_ID, Message, Redirect_URL, Is_Read) VALUES (?, ?, ?, 0)");
    $notif->bind_param("iss", $report['Farmer_ID'], $msg, $redirectUrl);
    $notif->execute();
    $notif->close();

    $conn->commit();

    require_once '../../audit/log_action.php';
    logAction($conn, $_SESSION['User_ID'], $_SESSION['Role'],
        'Restore Report',
        "Report #{$id} restored from the archive as {$previous}",
        'Damage Reports'
    );

    echo json_encode(["status" => "success", "message" => "Report restored.", "restored_status" => $previous]);

} catch (Throwable $e) {
    if (isset($conn)) $conn->rollback();
    // These reasons are written for the user and are safe to show;
    // anything else (a database error) still stays generic.
    $known = [
        'Damage report not found.',
        'This report is not archived.',
        'This report was withdrawn by the farmer, so it cannot be restored.',
    ];
    $isKnown = false;
    foreach ($known as $k) { if (strpos($e->getMessage(), $k) === 0) { $isKnown = true; break; } }
    echo json_encode(["status" => "error", "message" => $isKnown ? $e->getMessage() : safeErrorMessage($e)]);
}
?>
