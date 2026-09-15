<?php
// File Location: api/mao/issue_aid.php
session_start();
header('Content-Type: application/json');
require_once '../config/db_connection.php'; // Adjust path if necessary

// Ensure the user is logged in AND is actually staff. The role check was
// missing, so any logged-in user (e.g. a Farmer) could fabricate entries in
// the aid distribution ledger for any report.
$mao_id = $_SESSION['User_ID'] ?? null;
if (!$mao_id || !in_array($_SESSION['Role'] ?? '', ['Admin', 'MAO'])) {
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

    // Look up the Farmer_ID for this report — aid_distribution.Farmer_ID is a required
    // column, and pages like the Aid Ledger join on it directly, so it can't be left out.
    $getFarmer = $conn->prepare("SELECT Farmer_ID FROM damage_report WHERE Damage_Report_ID = ?");
    $getFarmer->bind_param("i", $report_id);
    $getFarmer->execute();
    $farmerRes = $getFarmer->get_result()->fetch_assoc();
    $getFarmer->close();

    if (!$farmerRes || !$farmerRes['Farmer_ID']) {
        echo json_encode(['status' => 'error', 'message' => 'Could not find the farmer linked to this report.']);
        exit;
    }
    $farmer_id = $farmerRes['Farmer_ID'];

    // Insert the permanent record into the database
    $stmt = $conn->prepare("INSERT INTO aid_distribution (Damage_Report_ID, Farmer_ID, Category, Quantity, Unit, Remarks, Distributed_By) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("iisdssi", $report_id, $farmer_id, $category, $quantity, $unit, $remarks, $mao_id);

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