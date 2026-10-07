<?php
session_start();
header('Content-Type: application/json');
require_once '../../config/db_connection.php';

if (!isset($_SESSION['Role']) || $_SESSION['Role'] !== 'Admin') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
    exit();
}

// Approving a seed request for an unlisted variety registers a new crop_type
// right here, which is a second way into the Crops Masterlist. Same guard as
// crop_type/create.php and crop_type/update.php - see the note in create.php
// for why a zero cost is rejected rather than quietly stored.
function validateProductionCost($value) {
    if (!is_numeric($value)) {
        return 'Standard production cost must be a number.';
    }
    $cost = (float)$value;
    if ($cost <= 0) {
        return 'Standard production cost must be greater than zero - it is multiplied into every damage report estimate for this crop.';
    }
    if ($cost > 1000000) {
        return 'Standard production cost looks too high (over 1,000,000 per hectare). Please check for a typo.';
    }
    return null;
}

// Same guard as crop_type/create.php - see the note there for why this is a
// text check rather than a numeric one. Without it this path would still
// accept "-50", which the Crops Masterlist now refuses.
function validateGrowthDuration($value) {
    $period = trim((string)($value ?? ''));
    if ($period === '') {
        return null;
    }
    if (!preg_match('/^\d+(\.\d+)?/', $period, $m) || (float)$m[0] <= 0) {
        return 'Growth duration must start with a positive number, for example "110 Days".';
    }
    if (floor((float)$m[0]) != (float)$m[0]) {
        return 'Growth duration must be a whole number - no decimals.';
    }
    return null;
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

    $stmt = $conn->prepare("SELECT Type_ID FROM crop_type WHERE LOWER(TRIM(Crop_Name)) = LOWER(?) LIMIT 1");
    if (!$stmt) {
        echo json_encode(['status' => 'error', 'message' => safeErrorMessage(new Exception($conn->error))]);
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

        $stmtCheck = $conn->prepare("SELECT Type_ID FROM crop_type WHERE LOWER(TRIM(Crop_Name)) = LOWER(?) LIMIT 1");
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

            // Returned directly rather than thrown: safeErrorMessage() in the catch
            // below replaces the real text with a generic "A server error occurred",
            // and the Admin needs to be told what is actually wrong with the value.
            $cost_error = validateProductionCost($data['prod_cost'] ?? null);
            if ($cost_error) {
                $conn->rollback();
                echo json_encode(['status' => 'error', 'message' => $cost_error]);
                exit();
            }

            $period_error = validateGrowthDuration($growth_period);
            if ($period_error) {
                $conn->rollback();
                echo json_encode(['status' => 'error', 'message' => $period_error]);
                exit();
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

    // 3. Notify the MAO that the seed request was approved for distribution.
    // Staff alerts live in admin_notification (the MAO bell reads
    // Target_Role = 'MAO' there). This used to go into the farmer-only
    // `notification` table, which no MAO page ever reads, so the alert was lost.
    // Same pattern as the MAO alert in damage_report/approve_with_aid.php.
    if ($normalized_status === 'Approved by Admin') {
        $maoMessage  = "Admin has approved Seed Request #$request_id. Please prepare for distribution.";
        $maoRedirect = 'index.html';
        $stmtNotifMao = $conn->prepare("INSERT INTO admin_notification (Target_Role, Message, Redirect_URL) VALUES ('MAO', ?, ?)");
        if (!$stmtNotifMao) throw new Exception("Prepare failed (mao notif): " . $conn->error);
        $stmtNotifMao->bind_param("ss", $maoMessage, $maoRedirect);
        if (!$stmtNotifMao->execute()) throw new Exception("Execute failed (mao notif): " . $stmtNotifMao->error);
        $stmtNotifMao->close();
    }

    // 4. Send notification to farmer
    if ($farmer_id > 0 && !empty($message)) {
        $redirectUrl = 'request_seeds.html';
        $stmtNotif = $conn->prepare("INSERT INTO notification (Farmer_ID, Message, Redirect_URL, Is_Read) VALUES (?, ?, ?, 0)");
        if (!$stmtNotif) throw new Exception("Prepare failed (notif): " . $conn->error);
        $stmtNotif->bind_param("iss", $farmer_id, $message, $redirectUrl);
        if (!$stmtNotif->execute()) throw new Exception("Execute failed (notif): " . $stmtNotif->error);
        $stmtNotif->close();
    }

    require_once '../../audit/log_action.php';
    logAction($conn, $_SESSION['User_ID'], $_SESSION['Role'],
        $normalized_status === 'Approved by Admin' ? 'Approve Seed Request' : 'Reject Seed Request',
        "Seed request #{$request_id} (\"{$requested_seed}\") marked as {$normalized_status}" . ($new_crop_id ? " - registered as a new crop" : ''),
        'Seed Requests'
    );

    $conn->commit();

    $response = ['status' => 'success', 'message' => 'Request processed successfully.'];
    if ($new_crop_id) {
        $response['crop_registered'] = true;
        $response['new_crop_id']     = $new_crop_id;
    }
    echo json_encode($response);

} catch (Exception $e) {
    $conn->rollback();
    // These reasons are written for the user and are safe to show;
    // anything else (a database error) still stays generic.
    $known = [
        'Commodity Type and Growth Duration are required to register a new crop.',
    ];
    $isKnown = false;
    foreach ($known as $k) { if (strpos($e->getMessage(), $k) === 0) { $isKnown = true; break; } }
    echo json_encode(['status' => 'error', 'message' => $isKnown ? $e->getMessage() : safeErrorMessage($e)]);
}
?>