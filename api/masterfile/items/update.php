<?php
// api/masterfile/items/update.php
ob_start();
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['User_ID']) || $_SESSION['Role'] !== 'Admin') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized.']);
    exit;
}

require_once '../../config/db_connection.php';
$input = json_decode(file_get_contents("php://input"), true);

// Same guards as items/create.php - see the notes there.
function validateStock($value) {
    if (!is_numeric($value))            return 'Stock must be a number.';
    $stock = (float)$value;
    if ($stock < 0)                     return 'Stock cannot be a negative number.';
    if (floor($stock) != $stock)        return 'Stock must be a whole number - no decimals.';
    if ($stock > 500000)                return "Stock can't be higher than 500,000. Please check the amount.";
    return null;
}

if (isset($input['id'], $input['item_name'])) {
    try {
        $id    = intval($input['id']);
        $name  = trim($input['item_name']);
        $cat   = $input['category'] ?? 'General';
        $unit  = $input['unit'] ?? 'Unit';
        $price = floatval($input['price'] ?? 0);
        $stock = floatval($input['current_stock'] ?? 0);
        $desc  = $input['description'] ?? '';
        $stat  = $input['status'] ?? 'Active';

        $stock_error = validateStock($input['current_stock'] ?? null);
        if ($stock_error) {
            if (ob_get_length()) ob_clean();
            echo json_encode(["status" => "error", "message" => $stock_error]);
            exit;
        }

        // Case-insensitive uniqueness, excluding this row so a commodity can be
        // saved without renaming it.
        $dupe = $conn->prepare("SELECT id FROM masterfile_items WHERE LOWER(TRIM(item_name)) = LOWER(?) AND id != ? LIMIT 1");
        $dupe->bind_param("si", $name, $id);
        $dupe->execute();
        if ($dupe->get_result()->fetch_assoc()) {
            $dupe->close();
            if (ob_get_length()) ob_clean();
            echo json_encode(["status" => "error", "message" => "A commodity with this name already exists. Names must be unique, regardless of capitalization."]);
            exit;
        }
        $dupe->close();

        $stmt = $conn->prepare("UPDATE masterfile_items SET item_name=?, category=?, unit=?, price=?, current_stock=?, description=?, status=? WHERE id=?");
        $stmt->bind_param("sssddssi", $name, $cat, $unit, $price, $stock, $desc, $stat, $id);

        if ($stmt->execute()) {
            if (ob_get_length()) ob_clean();
            echo json_encode(["status" => "success", "message" => "Item updated successfully!"]);
        } else {
            throw new Exception($conn->error);
        }
    } catch (Exception $e) {
        if (ob_get_length()) ob_clean();
        echo json_encode(["status" => "error", "message" => safeErrorMessage($e)]);
    }
}
?>