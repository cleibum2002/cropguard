<?php
// File Location: api/dashboard/mao_update_report.php
ob_start();
session_start();
header('Content-Type: application/json');

// Role check: only MAO can call this
if (!isset($_SESSION['User_ID']) || $_SESSION['Role'] !== 'MAO') {
    ob_clean();
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized.']);
    exit;
}

try {
    require_once '../config/db_connection.php';

    $input = file_get_contents("php://input");
    $data = json_decode($input);

    if (empty($data->id) || empty($data->status)) {
        throw new Exception("Missing required data.");
    }

    $report_id = (int)$data->id;
    $new_status = $data->status;

    // Whitelist allowed statuses to prevent arbitrary status injection
    $allowed_statuses = ['Validated', 'Approved', 'Rejected', 'Archived'];
    if (!in_array($new_status, $allowed_statuses)) {
        throw new Exception("Invalid status value.");
    }

    $conn->begin_transaction();

    // 1. Update the status in the damage_report table
    $sql_update = "UPDATE damage_report SET Status = ? WHERE Damage_Report_ID = ?";
    $stmt_update = $conn->prepare($sql_update);
    if (!$stmt_update) throw new Exception("Prepare failed: " . $conn->error);
    $stmt_update->bind_param("si", $new_status, $report_id);

    if (!$stmt_update->execute()) {
        throw new Exception("Failed to update status: " . $stmt_update->error);
    }

    if ($stmt_update->affected_rows === 0) {
        throw new Exception("Report #$report_id not found.");
    }
    $stmt_update->close();

    // 2. Fetch the Farmer_ID for notification
    $sql_farmer = "SELECT Farmer_ID FROM damage_report WHERE Damage_Report_ID = ?";
    $stmt_farmer = $conn->prepare($sql_farmer);
    if (!$stmt_farmer) throw new Exception("Prepare failed: " . $conn->error);
    $stmt_farmer->bind_param("i", $report_id);
    $stmt_farmer->execute();
    $result_farmer = $stmt_farmer->get_result();
    $stmt_farmer->close();

    if ($row = $result_farmer->fetch_assoc()) {
        $farmer_id = $row['Farmer_ID'];

        // 3. Build a meaningful notification message per status
        if ($new_status === 'Approved') {
            $message = "Great news! Your Damage Report #REP-{$report_id} has been Approved. Check your assistance ledger for details.";
        } elseif ($new_status === 'Rejected') {
            $message = "Notice: Your Damage Report #REP-{$report_id} was not approved. Please contact the MAO office for clarification.";
        } else {
            $message = "Your Damage Report #REP-{$report_id} status has been updated to: {$new_status}.";
        }

        // 4. Insert notification
        $sql_notif = "INSERT INTO notification (Farmer_ID, Message, Is_Read) VALUES (?, ?, 0)";
        $stmt_notif = $conn->prepare($sql_notif);
        if (!$stmt_notif) throw new Exception("Prepare failed: " . $conn->error);
        $stmt_notif->bind_param("is", $farmer_id, $message);

        if (!$stmt_notif->execute()) {
            throw new Exception("Status updated, but failed to send notification.");
        }
        $stmt_notif->close();
    }

    $conn->commit();

    if (ob_get_length()) ob_clean();
    echo json_encode(['status' => 'success', 'message' => "Report has been marked as $new_status and farmer notified."]);

} catch (Exception $e) {
    if (isset($conn) && !$conn->connect_error) $conn->rollback();
    if (ob_get_length()) ob_clean();
    echo json_encode(['status' => 'error', 'message' => safeErrorMessage($e)]);
}
?>