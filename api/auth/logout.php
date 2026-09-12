<?php
// api/auth/logout.php
session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// 1. Unset all session values
$_SESSION = array();

// 2. Destroy the session cookie (Critical for security)
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 3. Destroy the actual session
session_destroy();

// 4. Return success
echo json_encode(['status' => 'success']);
?>