<?php
// File Location: api/auth/verify_email.php
// The link clicked from the verification email lands here directly (GET request,
// not an API call the frontend fetches) - it shows a simple result page.
require_once '../config/db_connection.php';

$token = $_GET['token'] ?? '';

function showResultPage($success, $message) {
    $color = $success ? '#11542e' : '#dc2626';
    $icon  = $success ? '&#10003;' : '&#10007;';
    echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Email Verification</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f0f2f0; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
        .card { background: white; padding: 40px; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.1); text-align: center; max-width: 400px; }
        .icon { font-size: 48px; color: $color; }
        h2 { color: #1e293b; }
        a { display: inline-block; margin-top: 20px; background: #11542e; color: white; padding: 12px 24px; border-radius: 8px; text-decoration: none; font-weight: bold; }
    </style></head><body>
        <div class='card'>
            <div class='icon'>$icon</div>
            <h2>" . ($success ? 'Email Verified!' : 'Verification Failed') . "</h2>
            <p>$message</p>
            <a href='../../'>Go to Login</a>
        </div>
    </body></html>";
    exit;
}

if (empty($token)) {
    showResultPage(false, 'No verification token provided.');
}

$stmt = $conn->prepare("SELECT User_ID, Verification_Token_Expires FROM users WHERE Verification_Token = ? AND Email_Verified = 0");
$stmt->bind_param("s", $token);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    showResultPage(false, 'This verification link is invalid or has already been used.');
}

if (strtotime($user['Verification_Token_Expires']) < time()) {
    showResultPage(false, 'This verification link has expired. Please register again or contact support for a new link.');
}

$update = $conn->prepare("UPDATE users SET Email_Verified = 1, Verification_Token = NULL, Verification_Token_Expires = NULL WHERE User_ID = ?");
$update->bind_param("i", $user['User_ID']);
$update->execute();
$update->close();

showResultPage(true, 'Your email has been verified. You can now log in to your CropGuard account.');
?>