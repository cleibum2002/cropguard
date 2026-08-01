<?php
// api/masterfile/items/get_all.php
ob_start();
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['User_ID']) || $_SESSION['Role'] !== 'Admin') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized.']);
    exit;
}

require_once '../../config/db_connection.php';
try {
    // Select all columns including stock and price
    $sql = "SELECT * FROM masterfile_items ORDER BY id DESC";
    $result = $conn->query($sql);

    $data = [];
    if ($result && $result->num_rows > 0) {
        while($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
    }

    if (ob_get_length()) ob_clean();
    echo json_encode(["status" => "success", "data" => $data]);
} catch (Exception $e) {
    if (ob_get_length()) ob_clean();
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>