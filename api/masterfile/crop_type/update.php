<?php
session_start();
ini_set('display_errors', 0);
header('Content-Type: application/json');

if (!isset($_SESSION['User_ID']) || $_SESSION['Role'] !== 'Admin') {
    echo json_encode(["status" => "error", "message" => "Unauthorized."]);
    exit;
}

// Same guard as crop_type/create.php - see the note there for why zero and
// absurdly high values are both rejected rather than quietly stored.
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
// text check rather than a numeric one.
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
    if (!$data || empty($data->id) || empty($data->name)) {
        echo json_encode(["status" => "error", "message" => "Crop ID and name are required."]);
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

    $id          = (int)$data->id;
    $name        = trim($data->name);
    $type        = $data->type ?? null;
    $period      = $data->period ?? null;
    $prod_cost   = (float)$data->prod_cost;
    $description = $data->description ?? null;

    // Same rule as crop_type/create.php - see the note there. The Type_ID != ?
    // excludes this crop's own row, so saving it without renaming doesn't
    // collide with itself.
    $dupe = $conn->prepare("SELECT Type_ID FROM crop_type WHERE LOWER(TRIM(Crop_Name)) = LOWER(?) AND Type_ID != ? LIMIT 1");
    $dupe->bind_param("si", $name, $id);
    $dupe->execute();
    $dupe->store_result();
    $isDuplicate = $dupe->num_rows > 0;
    $dupe->close();

    if ($isDuplicate) {
        echo json_encode(["status" => "error", "message" => "Another crop named \"$name\" is already in the Crops Masterlist. Use a different name."]);
        exit;
    }

    // The old cost is read first: Standard_Production_Cost rewrites the peso
    // estimate on every damage report for this crop, so a change to it is the
    // one thing an auditor most needs to see.
    $old = $conn->prepare("SELECT Standard_Production_Cost FROM crop_type WHERE Type_ID = ?");
    $old->bind_param("i", $id);
    $old->execute();
    $oldCost = (float)($old->get_result()->fetch_assoc()['Standard_Production_Cost'] ?? 0);
    $old->close();

    $stmt = $conn->prepare("UPDATE crop_type SET Crop_Name = ?, Commodity_Type = ?, Growth_Duration = ?, Standard_Production_Cost = ?, Description = ? WHERE Type_ID = ?");
    $stmt->bind_param("sssdsi", $name, $type, $period, $prod_cost, $description, $id);

    if ($stmt->execute()) {
        $costNote = abs($oldCost - $prod_cost) > 0.001
            ? "cost P" . number_format($oldCost, 2) . " -> P" . number_format($prod_cost, 2) . "/ha"
            : "cost unchanged at P" . number_format($prod_cost, 2) . "/ha";
        require_once '../../audit/log_action.php';
        logAction($conn, $_SESSION['User_ID'], $_SESSION['Role'],
            'Update Crop',
            "Updated crop #{$id} {$name}: {$costNote}",
            'Crops Masterlist'
        );
        echo json_encode(["status" => "success", "message" => "Crop updated successfully."]);
    } else {
        echo json_encode(["status" => "error", "message" => safeErrorMessage(new Exception("Update failed: " . $stmt->error))]);
    }
    $stmt->close();
} catch (Throwable $e) {
    echo json_encode(["status" => "error", "message" => safeErrorMessage($e)]);
}