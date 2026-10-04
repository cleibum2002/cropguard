<?php
// api/masterfile/items/soft_delete.php
ob_start();
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['User_ID']) || $_SESSION['Role'] !== 'Admin') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized.']);
    exit;
}

require_once '../../config/db_connection.php';
$input = json_decode(file_get_contents("php://input"), true);

if (isset($input['id'])) {
    try {
        $id = intval($input['id']);
        
        // FIXED: Now correctly targets masterfile_items instead of users
        $stmt = $conn->prepare("UPDATE masterfile_items SET status = 'Inactive' WHERE id = ?");
        $stmt->bind_param("i", $id);

        if ($stmt->execute()) {
            $item = $conn->prepare("SELECT item_name, current_stock, unit FROM masterfile_items WHERE id = ?");
            $item->bind_param("i", $id);
            $item->execute();
            $row = $item->get_result()->fetch_assoc();
            $item->close();
            require_once '../../audit/log_action.php';
            logAction($conn, $_SESSION['User_ID'], $_SESSION['Role'],
                'Deactivate Item',
                "Deactivated relief item #{$id}" . ($row ? " {$row['item_name']} (stock left: " . (float)$row['current_stock'] . " {$row['unit']})" : ''),
                'Inventory'
            );
            if (ob_get_length()) ob_clean();
            echo json_encode(["status" => "success", "message" => "Item deactivated successfully."]);
        } else {
            throw new Exception($conn->error);
        }
    } catch (Exception $e) {
        if (ob_get_length()) ob_clean();
        echo json_encode(["status" => "error", "message" => safeErrorMessage($e)]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "No ID provided."]);
}
?>