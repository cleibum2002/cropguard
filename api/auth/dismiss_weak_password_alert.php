<?php
// Records that the current user dismissed the weak-password banner FOR THIS LOGIN ONLY.
// login.php resets this flag to false on every fresh login, so dismissing it here
// never suppresses it beyond the current session.
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['User_ID'])) {
    echo json_encode(['status' => 'error', 'message' => 'Not logged in.']);
    exit;
}

$_SESSION['Weak_Password_Alert_Dismissed'] = true;
echo json_encode(['status' => 'success']);
?>