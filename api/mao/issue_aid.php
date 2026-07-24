<?php
// File Location: api/mao/issue_aid.php
session_start();
header('Content-Type: application/json');
require_once '../config/db_connection.php'; // Adjust path if necessary

// Ensure the user is logged in
$mao_id = $_SESSION['User_ID'] ?? null;
if (!$mao_id) { 
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']); 
    exit; 
}

// Get the JSON payload from the frontend
$input = json_decode(file_get_contents("php://input"), true);

if (isset($input['report_id'], $input['category'], $input['quantity'], $input['unit'])) {
    $report_id = (int)$input['report_id'];
    $category = $input['category'];
    $quantity = (float)$input['quantity'];
    $unit = $input['unit'];
    $remarks = $input['remarks'] ?? '';

    // Insert the permanent record into the database
    $stmt = $conn->prepare("INSERT INTO aid_distribution (Damage_Report_ID, Category, Quantity, Unit, Remarks, Distributed_By) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("isdssi", $report_id, $category, $quantity, $unit, $remarks, $mao_id);

    if ($stmt->execute()) {
        echo json_encode(['status' => 'success', 'message' => 'Relief assistance recorded permanently.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Database update failed: ' . $stmt->error]);
    }
    
    $stmt->close();
} else {
    echo json_encode(['status' => 'error', 'message' => 'Missing required fields.']);
}
$conn->close();
?>