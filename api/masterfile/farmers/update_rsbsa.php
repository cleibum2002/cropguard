<?php
session_start();
header('Content-Type: application/json');
require_once '../../config/db_connection.php';

if (!isset($_SESSION['User_ID']) || $_SESSION['Role'] !== 'Admin') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized.']);
    exit;
}

$input   = json_decode(file_get_contents('php://input'), true);
$user_id = isset($input['id'])      ? (int)$input['id'] : 0;
$rsbsa   = trim($input['rsbsa_no']  ?? '');

if (!$user_id) {
    echo json_encode(['status' => 'error', 'message' => 'Missing user ID.']);
    exit;
}

if (!empty($rsbsa) && !preg_match('/^\d{3}-\d{4}-\d{5}$/', $rsbsa)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid RSBSA format. Use: ###-####-##### (e.g. 202-5985-77856)']);
    exit;
}

try {
    if (!empty($rsbsa)) {
        $check = $conn->prepare("SELECT User_ID FROM users WHERE RSBA_No = ? AND User_ID != ?");
        $check->bind_param("si", $rsbsa, $user_id);
        $check->execute();
        if ($check->get_result()->fetch_assoc()) {
            echo json_encode(['status' => 'error', 'message' => 'This RSBSA number is already assigned to another farmer.']);
            exit;
        }
        $check->close();
    }

    $rsbsa_val = !empty($rsbsa) ? $rsbsa : null;
    $stmt = $conn->prepare("UPDATE users SET RSBA_No = ? WHERE User_ID = ? AND Role = 'Farmer'");
    $stmt->bind_param("si", $rsbsa_val, $user_id);

    if ($stmt->execute()) {
        echo json_encode(['status' => 'success', 'message' => 'RSBSA updated successfully.']);
    } else {
        throw new Exception($stmt->error);
    }
    $stmt->close();

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>