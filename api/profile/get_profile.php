<?php
// File Location: api/profile/get_profile.php
session_start();
header('Content-Type: application/json');
require_once '../config/db_connection.php';

if (!isset($_SESSION['User_ID'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized.']);
    exit;
}

$user_id = $_SESSION['User_ID'];

try {
    $stmt = $conn->prepare(
        // Email_Verified and Phone_Verified are selected so the page can show
        // which of the two an account has actually confirmed. A farmer now
        // registers by verifying their PHONE, so they arrive here with an
        // unverified email and need somewhere to finish that off.
        "SELECT User_ID, Role, Fullname, Email, Pending_Email, Contact_Number, RSBA_No, Assigned_Area, STATUS,
                Email_Verified, Phone_Verified
         FROM users WHERE User_ID = ?"
    );
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user) {
        echo json_encode(['status' => 'error', 'message' => 'User not found.']);
        exit;
    }

    echo json_encode(['status' => 'success', 'user' => $user]);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => safeErrorMessage($e)]);
}
?>