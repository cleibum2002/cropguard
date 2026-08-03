<?php
session_start();
header('Content-Type: application/json');
require_once '../config/db_connection.php';

if (!isset($_SESSION['User_ID']) || $_SESSION['Role'] !== 'Encoder') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized.']);
    exit;
}

$encoder_id  = $_SESSION['User_ID'];
$input       = json_decode(file_get_contents('php://input'), true);

$farmer_id   = isset($input['farmer_id'])   ? (int)$input['farmer_id']   : 0;
$location_id = isset($input['location_id']) ? (int)$input['location_id'] : 0;
$crop_id     = isset($input['crop_id'])     ? (int)$input['crop_id']     : 0;
$damage_type = trim($input['damage_type']   ?? '');
$severity    = trim($input['severity']      ?? '');
$description = trim($input['description']   ?? '');

if (!$farmer_id || !$location_id || !$crop_id || !$damage_type || !$severity) {
    echo json_encode(['status' => 'error', 'message' => 'Missing required fields.']);
    exit;
}

$allowed_severity = ['Minimal', 'Light', 'Moderate', 'Severe', 'Total Loss'];
if (!in_array($severity, $allowed_severity)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid severity value.']);
    exit;
}

try {
    $conn->begin_transaction();

    $sql  = "INSERT INTO damage_report 
                (Farmer_ID, Location_ID, Crop_ID, Damage_Type, Damage_Severity, Description, Status, Encoder_ID)
             VALUES (?, ?, ?, ?, ?, ?, 'Pending', ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iiisssi", $farmer_id, $location_id, $crop_id, $damage_type, $severity, $description, $encoder_id);
    if (!$stmt->execute()) throw new Exception($stmt->error);
    $new_report_id = $conn->insert_id;
    $stmt->close();

    $msg   = "A damage report (#REP-{$new_report_id}) has been submitted on your behalf by the DA Encoder. Please check your dashboard.";
    $notif = $conn->prepare("INSERT INTO notification (Farmer_ID, Message, Is_Read) VALUES (?, ?, 0)");
    $notif->bind_param("is", $farmer_id, $msg);
    $notif->execute();
    $notif->close();

    // Notify Admin staff of the new (encoder-submitted / walk-in) report
    $getFarmerName = $conn->prepare("SELECT Fullname FROM users WHERE User_ID = ?");
    $getFarmerName->bind_param("i", $farmer_id);
    $getFarmerName->execute();
    $farmerRes   = $getFarmerName->get_result()->fetch_assoc();
    $farmer_name = $farmerRes['Fullname'] ?? 'a farmer';
    $getFarmerName->close();

    $encoder_name = $_SESSION['Fullname'] ?? 'the DA Encoder';
    $admin_msg    = "New damage report (#REP-{$new_report_id}) encoded by {$encoder_name} on behalf of {$farmer_name} — awaiting review.";
    $stmt_admin_notif = $conn->prepare("INSERT INTO admin_notification (Target_Role, Message, Related_Report_ID) VALUES ('Admin', ?, ?)");
    $stmt_admin_notif->bind_param("si", $admin_msg, $new_report_id);
    $stmt_admin_notif->execute();
    $stmt_admin_notif->close();

    $conn->commit();
    echo json_encode(['status' => 'success', 'message' => 'Report encoded and farmer notified.']);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['status' => 'error', 'message' => safeErrorMessage($e)]);
}
?>