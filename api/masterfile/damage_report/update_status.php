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
        
        $conn->begin_transaction();

        // 1. Find the farmer
        $findFarmer = $conn->prepare("SELECT Farmer_ID FROM damage_report WHERE Damage_Report_ID = ?");
        $findFarmer->bind_param("i", $data->id);
        $findFarmer->execute();
        $findFarmer->bind_result($farmer_id);
        $findFarmer->fetch();
        $findFarmer->close();

        if (!$farmer_id) {
            throw new Exception("Farmer not found for this report.");
        }

        // 2. Update the report status. Also record who validated it, specifically
        // for the Validated transition - this endpoint is shared with Reject and
        // Archive too, which have no equivalent "by" column to fill in.
        if ($data->status === 'Validated') {
            $query = "UPDATE damage_report SET Status = ?, Validated_By = ? WHERE Damage_Report_ID = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("sii", $data->status, $_SESSION['User_ID'], $data->id);
        } else {
            $query = "UPDATE damage_report SET Status = ? WHERE Damage_Report_ID = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("si", $data->status, $data->id);
        }
        $stmt->execute();
        $stmt->close();

        // 3. SEND NOTIFICATION
        $msg = "Update: Your Damage Report #REP-{$data->id} is now marked as {$data->status}.";
        
        // Custom message for specific statuses
        if ($data->status === 'Approved') {
            $msg = "Great news! Your Damage Report #REP-{$data->id} has been Approved. Check your assistance ledger for details.";
        } elseif ($data->status === 'Rejected') {
            $msg = "Notice: Your Damage Report #REP-{$data->id} was not approved. Please contact the MAO office for clarification.";
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
        echo json_encode(["status" => "success", "message" => "Report status updated and farmer notified."]);
        
    } else {
        echo json_encode(["status" => "error", "message" => "Missing ID or Status."]);
    }
} catch (Throwable $e) {
    if(isset($conn)) $conn->rollback();
    echo json_encode(["status" => "error", "message" => safeErrorMessage($e)]);
}
?>