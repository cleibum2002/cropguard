<?php
// File Location: api/profile/update_profile.php
session_start();
header('Content-Type: application/json');
require_once '../config/db_connection.php';

if (!isset($_SESSION['User_ID'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized.']);
    exit;
}

$user_id = $_SESSION['User_ID'];
$input   = json_decode(file_get_contents('php://input'), true);

$fullname = trim($input['fullname'] ?? '');
$email    = trim($input['email']    ?? '');
$contact  = trim($input['contact']  ?? '');
$rsba     = trim($input['rsba']     ?? '');
$area     = trim($input['area']     ?? '');
$password = $input['password']      ?? '';

if (!$fullname || !$email) {
    echo json_encode(['status' => 'error', 'message' => 'Full name and email are required.']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid email format.']);
    exit;
}

if ($password && strlen($password) < 6) {
    echo json_encode(['status' => 'error', 'message' => 'Password must be at least 6 characters.']);
    exit;
}

try {
    // Check if email is taken by another user
    $checkStmt = $conn->prepare("SELECT User_ID FROM users WHERE Email = ? AND User_ID != ?");
    $checkStmt->bind_param("si", $email, $user_id);
    $checkStmt->execute();
    if ($checkStmt->get_result()->fetch_assoc()) {
        throw new Exception("Email is already in use by another account.");
    }
    $checkStmt->close();

    if ($password) {
        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $stmt   = $conn->prepare(
            "UPDATE users SET Fullname = ?, Email = ?, Contact_Number = ?, RSBA_No = ?, Assigned_Area = ?, Password = ? 
             WHERE User_ID = ?"
        );
        $stmt->bind_param("ssssssi", $fullname, $email, $contact, $rsba, $area, $hashed, $user_id);
    } else {
        $stmt = $conn->prepare(
            "UPDATE users SET Fullname = ?, Email = ?, Contact_Number = ?, RSBA_No = ?, Assigned_Area = ? 
             WHERE User_ID = ?"
        );
        $stmt->bind_param("sssssi", $fullname, $email, $contact, $rsba, $area, $user_id);
    }

    if (!$stmt->execute()) throw new Exception($stmt->error);
    $stmt->close();

    // Update session so sidebar name reflects immediately
    $_SESSION['Fullname'] = $fullname;

    echo json_encode(['status' => 'success', 'message' => 'Profile updated successfully.']);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>