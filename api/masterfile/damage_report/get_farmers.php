<?php
session_start();
ini_set('display_errors', 0);
header('Content-Type: application/json');

if (!isset($_SESSION['User_ID']) || !in_array($_SESSION['Role'], ['MAO', 'Admin'])) {
    echo json_encode(["status" => "error", "message" => "Unauthorized."]);
    exit;
}

try {
    include_once '../../config/db_connection.php'; 

    // Active farmers only - a deactivated account must not be selectable for a
    // new walk-in report.
    $query = "SELECT User_ID as id, Fullname as name FROM users WHERE Role = 'Farmer' AND STATUS = 'Active' ORDER BY Fullname ASC";
    $result = $conn->query($query);

    if (!$result) throw new Exception($conn->error);

    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }

    echo json_encode(["status" => "success", "data" => $data]);
} catch (Throwable $e) {
    echo json_encode(["status" => "error", "message" => safeErrorMessage($e)]);
}
?>