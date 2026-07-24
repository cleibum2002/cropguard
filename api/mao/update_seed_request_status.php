<?php
session_start();
header('Content-Type: application/json');
require_once '../../api/config/db_connection.php';

if (!isset($_SESSION['Role']) || $_SESSION['Role'] !== 'MAO') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access. MAO only.']);
    exit();
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON payload.']);
    exit();
}

$request_id = intval($data['request_id'] ?? 0);
$farmer_id  = intval($data['farmer_id'] ?? 0);
$action     = trim($data['action'] ?? '');

if (!$request_id || !$farmer_id || !in_array($action, ['notify_farmer', 'mark_claimed'])) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request.']);
    exit();
}

try {
    $conn->begin_transaction();

    if ($action === 'notify_farmer') {
        $stmt = $conn->prepare("SELECT Status FROM seed_requests WHERE Request_ID = ? AND Farmer_ID = ?");
        if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);
        $stmt->bind_param("ii", $request_id, $farmer_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        if (!$row) {
            throw new Exception('Seed request not found.');
        }

        if ($row['Status'] !== 'Approved by Admin') {
            throw new Exception('Only requests approved by the admin can be marked ready for pickup.');
        }

        $update = $conn->prepare("UPDATE seed_requests SET Status = 'Ready for Pickup' WHERE Request_ID = ? AND Farmer_ID = ?");
        if (!$update) throw new Exception("Prepare failed: " . $conn->error);
        $update->bind_param("ii", $request_id, $farmer_id);
        if (!$update->execute()) throw new Exception("Execute failed: " . $update->error);
        $update->close();

        $message = 'Your requested seeds are ready! You can now claim them at the MAO office.';
        $notif = $conn->prepare("INSERT INTO notification (Farmer_ID, Message, Is_Read) VALUES (?, ?, 0)");
        if (!$notif) throw new Exception("Prepare failed (notif): " . $conn->error);
        $notif->bind_param("is", $farmer_id, $message);
        if (!$notif->execute()) throw new Exception("Execute failed (notif): " . $notif->error);
        $notif->close();

        $conn->commit();
        echo json_encode(['status' => 'success', 'message' => 'Farmer has been notified and the request is ready for pickup.']);
        exit();
    }

    $stmt = $conn->prepare("SELECT Status FROM seed_requests WHERE Request_ID = ? AND Farmer_ID = ?");
    if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);
    $stmt->bind_param("ii", $request_id, $farmer_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    if (!$row) {
        throw new Exception('Seed request not found.');
    }

    if ($row['Status'] !== 'Ready for Pickup') {
        throw new Exception('Only requests marked ready for pickup can be claimed.');
    }

    $update = $conn->prepare("UPDATE seed_requests SET Status = 'Claimed' WHERE Request_ID = ? AND Farmer_ID = ?");
    if (!$update) throw new Exception("Prepare failed: " . $conn->error);
    $update->bind_param("ii", $request_id, $farmer_id);
    if (!$update->execute()) throw new Exception("Execute failed: " . $update->error);
    $update->close();

    $conn->commit();
    echo json_encode(['status' => 'success', 'message' => 'The seed request has been marked as claimed.']);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
