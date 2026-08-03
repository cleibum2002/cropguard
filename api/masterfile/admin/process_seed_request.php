<?php
session_start();
header('Content-Type: application/json');
require_once '../../config/db_connection.php';

if (!isset($_SESSION['Role']) || $_SESSION['Role'] !== 'Admin') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
    exit();
}

$raw  = file_get_contents("php://input");
$data = json_decode($raw, true);

if (!$data) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON payload.']);
    exit();
}

$action         = trim($data['action']         ?? '');
$request_id     = intval($data['request_id']   ?? 0);
$farmer_id      = intval($data['farmer_id']    ?? 0);
$status         = trim($data['status']         ?? '');
$message        = trim($data['message']        ?? '');
$requested_seed = trim($data['requested_seed'] ?? '');

// ── PRE-FLIGHT: just check if crop exists, no DB writes ──────────────────────
if ($action === 'check_crop') {
    if (empty($requested_seed)) {
        echo json_encode(['status' => 'error', 'message' => 'No seed name provided for check.']);
        exit();
    }

    $stmt = $conn->prepare("SELECT Type_ID FROM crop_type WHERE LOWER(Crop_Name) = LOWER(?) LIMIT 1");
    if (!$stmt) {
        echo json_encode(['status' => 'error', 'message' => 'DB prepare failed: ' . $conn->error]);
        exit();
    }
    $stmt->bind_param("s", $requested_seed);
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();

    echo json_encode(['status' => 'success', 'exists' => $exists]);
    exit();
}

// ── MAIN SUBMIT: validate required fields ────────────────────────────────────
if (!$request_id || !$status || !$message || !$requested_seed) {
    echo json_encode(['status' => 'error', 'message' => 'Missing required fields.']);
    exit();
}

if (!in_array($status, ['Approved by Admin', 'Approved', 'Rejected'])) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid status value.']);
    exit();
}

$normalized_status = ($status === 'Approved' ? 'Approved by Admin' : $status);

$crop_type     = trim($data['crop_type']     ?? '');
$growth_period = trim($data['growth_period'] ?? '');
$description   = trim($data['description']   ?? '');
$prod_cost     = floatval($data['prod_cost'] ?? 0);

try {
    $conn->begin_transaction();

    // 1. Update seed_requests status
    $stmt = $conn->prepare("UPDATE seed_requests SET Status = ? WHERE Request_ID = ?");
    if (!$stmt) throw new Exception("Prepare failed (update): " . $conn->error);
    $stmt->bind_param("si", $normalized_status, $request_id);
    if (!$stmt->execute()) throw new Exception("Execute failed (update): " . $stmt->error);
    $stmt->close();

    $new_crop_id = null;

    // 2. If Approved → maybe insert into crop_type
    if ($normalized_status === 'Approved by Admin') {

        $stmtCheck = $conn->prepare("SELECT Type_ID FROM crop_type WHERE LOWER(Crop_Name) = LOWER(?) LIMIT 1");
        if (!$stmtCheck) throw new Exception("Prepare failed (check): " . $conn->error);
        $stmtCheck->bind_param("s", $requested_seed);
        $stmtCheck->execute();
        $stmtCheck->store_result();
        $alreadyExists = $stmtCheck->num_rows > 0;
        $stmtCheck->close();

        if (!$alreadyExists) {
            if (empty($crop_type) || empty($growth_period)) {
                throw new Exception("Commodity Type and Growth Duration are required to register a new crop.");
            }

            $stmtInsert = $conn->prepare(
                "INSERT INTO crop_type (Crop_Name, Commodity_Type, Growth_Duration, Standard_Production_Cost, Description, Status)
                 VALUES (?, ?, ?, ?, ?, 'Active')"
            );
            if (!$stmtInsert) throw new Exception("Prepare failed (insert): " . $conn->error);
            $stmtInsert->bind_param("sssds", $requested_seed, $crop_type, $growth_period, $prod_cost, $description);
            if (!$stmtInsert->execute()) throw new Exception("Execute failed (insert): " . $stmtInsert->error);
            $new_crop_id = $conn->insert_id;
            $stmtInsert->close();
        }
    }

    // 3. Notify the MAO that the seed request was approved for distribution
    if ($normalized_status === 'Approved by Admin') {
        $stmtMao = $conn->prepare("SELECT User_ID FROM users WHERE Role = 'MAO'");
        if (!$stmtMao) throw new Exception("Prepare failed (mao users): " . $conn->error);
        $stmtMao->execute();
        $resultMao = $stmtMao->get_result();

        $maoMessage = "Admin has approved Seed Request #$request_id. Please prepare for distribution.";
        while ($mao = $resultMao->fetch_assoc()) {
            $stmtNotifMao = $conn->prepare("INSERT INTO notification (Farmer_ID, Message, Is_Read) VALUES (?, ?, 0)");
            if (!$stmtNotifMao) throw new Exception("Prepare failed (mao notif): " . $conn->error);
            $stmtNotifMao->bind_param("is", $mao['User_ID'], $maoMessage);
            if (!$stmtNotifMao->execute()) throw new Exception("Execute failed (mao notif): " . $stmtNotifMao->error);
            $stmtNotifMao->close();
        }
        $stmtMao->close();
    }

    // 4. Send notification to farmer
    if ($farmer_id > 0 && !empty($message)) {
        $stmtNotif = $conn->prepare("INSERT INTO notification (Farmer_ID, Message, Is_Read) VALUES (?, ?, 0)");
        if (!$stmtNotif) throw new Exception("Prepare failed (notif): " . $conn->error);
        $stmtNotif->bind_param("is", $farmer_id, $message);
        if (!$stmtNotif->execute()) throw new Exception("Execute failed (notif): " . $stmtNotif->error);
        $stmtNotif->close();
    }

    $conn->commit();

    $response = ['status' => 'success', 'message' => 'Request processed successfully.'];
    if ($new_crop_id) {
        $response['crop_registered'] = true;
        $response['new_crop_id']     = $new_crop_id;
    }
    echo json_encode($response);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['status' => 'error', 'message' => safeErrorMessage($e)]);
}
?>