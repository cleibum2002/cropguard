<?php
// File Location: api/mao/get_distributed_aid.php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['User_ID']) || !in_array($_SESSION['Role'], ['Admin', 'MAO'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized.']);
    exit;
}

require_once '../config/db_connection.php'; // Fixed path

$query = "SELECT Damage_Report_ID FROM aid_distribution";
$result = $conn->query($query);

$distributed_ids = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $distributed_ids[$row['Damage_Report_ID']] = true; 
    }
}

if (ob_get_length()) ob_clean();
echo json_encode(['status' => 'success', 'data' => $distributed_ids]);
?>