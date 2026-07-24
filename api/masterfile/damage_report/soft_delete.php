<?php
// File Location: api/dashboard/delete_report.php
ini_set('display_errors', 0);
header('Content-Type: application/json');

try {
    require_once '../../config/db_connection.php';
    session_start();

    // GET THE RAW JSON INPUT
    $json = file_get_contents('php://input');
    $data = json_decode($json, true); // true converts to associative array
    
    // Check if ID exists in the JSON
    $id = $data['id'] ?? null;

    if (!$id) {
        // Log what we received to debug if needed
        echo json_encode([
            "status" => "error", 
            "message" => "No Report ID provided.",
            "received_input" => $json // This helps you see what the server actually got
        ]);
        exit;
    }

    // 2. Security Check: Ensure only the owner can archive their report
    $farmer_id = $_SESSION['user_id'] ?? $_SESSION['User_ID'] ?? $_SESSION['id'] ?? null;
    
    // We use a prepared statement with the Farmer_ID check to prevent unauthorized deletion
    $query = "UPDATE damage_report SET Status = 'Archived' WHERE Damage_Report_ID = ? AND Farmer_ID = ?";
    $stmt = $conn->prepare($query);
    
    if (!$stmt) throw new Exception($conn->error);
    
    $stmt->bind_param("ii", $id, $farmer_id);

    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            echo json_encode(["status" => "success", "message" => "Report has been archived successfully."]);
        } else {
            echo json_encode(["status" => "error", "message" => "Report not found or unauthorized."]);
        }
    } else {
        echo json_encode(["status" => "error", "message" => "Failed to archive report."]);
    }
} catch (Throwable $e) {
    echo json_encode(["status" => "error", "message" => "System Error: " . $e->getMessage()]);
}
?>