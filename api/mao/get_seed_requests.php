<?php
session_start();
header('Content-Type: application/json');
require_once '../../api/config/db_connection.php';

if (!isset($_SESSION['Role']) || $_SESSION['Role'] !== 'MAO') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access. MAO only.']);
    exit();
}

try {
    $query = "SELECT sr.Request_ID, sr.Farmer_ID, sr.Requested_Seed, sr.Description, sr.Status,
                     u.Fullname as Farmer_Name
              FROM seed_requests sr
              JOIN users u ON sr.Farmer_ID = u.User_ID
              WHERE sr.Status IN ('Approved by Admin', 'Ready for Pickup')
              ORDER BY FIELD(sr.Status, 'Approved by Admin', 'Ready for Pickup'), sr.Request_ID DESC";

    $stmt = $conn->prepare($query);
    if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);
    $stmt->execute();
    $result = $stmt->get_result();
    $requests = $result->fetch_all(MYSQLI_ASSOC);

    echo json_encode(['status' => 'success', 'data' => $requests]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => safeErrorMessage($e)]);
}
?>
