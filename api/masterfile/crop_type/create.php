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

    $name        = $data->name;
    $type        = $data->type ?? null;
    $period      = $data->period ?? null;
    $prod_cost   = (float)$data->prod_cost;
    $description = $data->description ?? null;

    $stmt = $conn->prepare("INSERT INTO crop_type (Crop_Name, Commodity_Type, Growth_Duration, Standard_Production_Cost, Description, Status) VALUES (?, ?, ?, ?, ?, 'Active')");
    $stmt->bind_param("sssds", $name, $type, $period, $prod_cost, $description);

    if ($stmt->execute()) {
        echo json_encode(["status" => "success", "message" => "Crop registered successfully.", "id" => $conn->insert_id]);
    } else {
        echo json_encode(["status" => "error", "message" => "Insert failed: " . $stmt->error]);
    }
    $stmt->close();
} catch (Throwable $e) {
    echo json_encode(["status" => "error", "message" => safeErrorMessage($e)]);
}