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

if (isset($input['id'], $input['item_name'])) {
    try {
        $id    = intval($input['id']);
        $name  = $input['item_name'];
        $cat   = $input['category'] ?? 'General';
        $unit  = $input['unit'] ?? 'Unit';
        $price = floatval($input['price'] ?? 0);
        $stock = floatval($input['current_stock'] ?? 0);
        $desc  = $input['description'] ?? '';
        $stat  = $input['status'] ?? 'Active';

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