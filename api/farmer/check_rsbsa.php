<?php
session_start();
header('Content-Type: application/json');
require_once '../config/db_connection.php';

if (!isset($_SESSION['User_ID']) || $_SESSION['Role'] !== 'Farmer') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized.']);
    exit;
}

$user_id = $_SESSION['User_ID'];

try {
    $stmt = $conn->prepare("SELECT RSBA_No FROM users WHERE User_ID = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $rsba = $user['RSBA_No'] ?? '';

    if (empty($rsba)) {
        echo json_encode([
            'status'  => 'no_rsbsa',
            'message' => 'You need to set your RSBSA number in your profile before submitting a damage report.'
        ]);
        exit;
    }

    if (!preg_match('/^\d{2}-\d{2}-\d{2}-\d{3}-\d{6}$/', $rsba)) {
        echo json_encode([
            'status'  => 'invalid_format',
            'message' => 'Your RSBSA number format is invalid. Please update it in your profile. Format: ##-##-##-###-###### (e.g. 10-43-04-019-000123)'
        ]);
        exit;
    }

    echo json_encode(['status' => 'valid', 'rsbsa' => $rsba, 'message' => 'RSBSA verified.']);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => safeErrorMessage($e)]);
}
?>