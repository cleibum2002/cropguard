<?php
session_start();
ini_set('display_errors', 0);
header('Content-Type: application/json');

if (!isset($_SESSION['User_ID']) || $_SESSION['Role'] !== 'Admin') {
    echo json_encode(["status" => "error", "message" => "Unauthorized."]);
    exit;
}

// Standard_Production_Cost is multiplied into the estimated loss of every
// damage report for this crop (api/dashboard/calculate_cost.php), so a bad
// value here silently rewrites real money figures system-wide. Zero is
// rejected specifically because it makes every estimate compute to P0.00,
// which reads on screen as a number rather than as an error. Duplicated in
// crop_type/update.php, matching how validatePasswordStrength() is duplicated
// across api/auth/register.php and api/masterfile/users/create.php.
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

// Growth Duration is free text ("110 Days", "3 months"), so it can't be a plain
// numeric check - but it must still start with a positive number, otherwise a
// value like "-50" is stored and shown on reports. Blank stays allowed.
// Duplicated in crop_type/update.php, same as validateProductionCost() above.
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

try {
    include_once '../../config/db_connection.php';

    $data = json_decode(file_get_contents("php://input"));
    if (!$data || empty($data->name)) {
        echo json_encode(["status" => "error", "message" => "Crop name is required."]);
        exit;
    }

    $cost_error = validateProductionCost($data->prod_cost ?? null);
    if ($cost_error) {
        echo json_encode(["status" => "error", "message" => $cost_error]);
        exit;
    }

    $period_error = validateGrowthDuration($data->period ?? null);
    if ($period_error) {
        echo json_encode(["status" => "error", "message" => $period_error]);
        exit;
    }

    $name        = trim($data->name);
    $type        = $data->type ?? null;
    $period      = $data->period ?? null;
    $prod_cost   = (float)$data->prod_cost;
    $description = $data->description ?? null;

    // Case-insensitive uniqueness, same shape as the check in items/create.php.
    // Deliberately an exact-name match and NOT a fuzzy one: real variety names
    // run in numbered series ("Tubigan 4" vs "Tubigan 14", "Sahod Ulan 1" vs
    // "Sahod Ulan 11"), so near-identical names are usually different crops
    // rather than typos.
    $dupe = $conn->prepare("SELECT Type_ID FROM crop_type WHERE LOWER(TRIM(Crop_Name)) = LOWER(?) LIMIT 1");
    $dupe->bind_param("s", $name);
    $dupe->execute();
    $dupe->store_result();
    $isDuplicate = $dupe->num_rows > 0;
    $dupe->close();

    if ($isDuplicate) {
        echo json_encode(["status" => "error", "message" => "\"$name\" is already in the Crops Masterlist. Use a different name, or edit the existing entry."]);
        exit;
    }

    $stmt = $conn->prepare("INSERT INTO crop_type (Crop_Name, Commodity_Type, Growth_Duration, Standard_Production_Cost, Description, Status) VALUES (?, ?, ?, ?, ?, 'Active')");
    $stmt->bind_param("sssds", $name, $type, $period, $prod_cost, $description);

    if ($stmt->execute()) {
        $newId = $conn->insert_id;
        require_once '../../audit/log_action.php';
        logAction($conn, $_SESSION['User_ID'], $_SESSION['Role'],
            'Add Crop',
            "Registered crop #{$newId} {$name} at P" . number_format($prod_cost, 2) . "/ha",
            'Crops Masterlist'
        );
        echo json_encode(["status" => "success", "message" => "Crop registered successfully.", "id" => $newId]);
    } else {
        echo json_encode(["status" => "error", "message" => safeErrorMessage(new Exception("Insert failed: " . $stmt->error))]);
    }
    $stmt->close();
} catch (Throwable $e) {
    echo json_encode(["status" => "error", "message" => safeErrorMessage($e)]);
}