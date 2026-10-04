<?php
// File Location: api/mao/mark_as_claimed.php
session_start();
header('Content-Type: application/json');
require_once '../config/db_connection.php';

// Role check
if (!isset($_SESSION['User_ID']) || !in_array($_SESSION['Role'], ['MAO', 'Admin'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized.']);
    exit;
}

$input   = json_decode(file_get_contents('php://input'), true);
$dist_id = $input['dist_id'] ?? $input['id'] ?? null;
$mao_id  = $_SESSION['User_ID'];

if (!$dist_id) {
    echo json_encode(['status' => 'error', 'message' => 'Missing distribution ID.']);
    exit;
}

try {
    $conn->begin_transaction();

    // 1. Mark as claimed — guard prevents double-claiming
    $stmt = $conn->prepare(
        "UPDATE aid_distribution SET Status = 'Claimed', Distributed_By = ?, Date_Distributed = NOW() 
         WHERE Distribution_ID = ? AND Status = 'Pending Distribution'"
    );
    $stmt->bind_param("ii", $mao_id, $dist_id);
    $stmt->execute();

    if ($stmt->affected_rows === 0) {
        throw new Exception("Record not found or already claimed.");
    }
    $stmt->close();

    // 2. Get farmer ID and aid details for notification
    $info = $conn->prepare(
        "SELECT ad.Farmer_ID, ad.Category, ad.Quantity, ad.Unit 
         FROM aid_distribution ad 
         WHERE ad.Distribution_ID = ?"
    );
    $info->bind_param("i", $dist_id);
    $info->execute();
    $row = $info->get_result()->fetch_assoc();
    $info->close();

    // 3. Send farmer notification
    if ($row && $row['Farmer_ID']) {
        $msg = "You have successfully claimed {$row['Quantity']} {$row['Unit']} of {$row['Category']} (Aid #AID-{$dist_id}). Thank you!";
        $redirectUrl = 'index.html';
        $notif = $conn->prepare("INSERT INTO notification (Farmer_ID, Message, Redirect_URL, Is_Read) VALUES (?, ?, ?, 0)");
        $notif->bind_param("iss", $row['Farmer_ID'], $msg, $redirectUrl);
        $notif->execute();
        $notif->close();
    }

    $conn->commit();

    // Log the action
    require_once '../audit/log_action.php';
    $farmer_id = $row['Farmer_ID'] ?? 0;
    // Real role from the session - both MAO and Admin may mark aid as claimed,
    // and this used to record every claim as 'MAO'.
    logAction($conn, $mao_id, $_SESSION['Role'],
        'Mark as Claimed',
        "Aid #AID-$dist_id marked as claimed for farmer #$farmer_id",
        'Aid Distribution'
    );
    
    echo json_encode(['status' => 'success', 'message' => 'Aid marked as Claimed and farmer notified!']);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['status' => 'error', 'message' => safeErrorMessage($e)]);
}
?>