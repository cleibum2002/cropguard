<?php
// api/auth/logout.php
session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Who is leaving - captured before the session is wiped, for the audit log.
$loggedOutId   = $_SESSION['User_ID'] ?? null;
$loggedOutRole = $_SESSION['Role'] ?? null;
$loggedOutName = $_SESSION['Fullname'] ?? '';

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

// 4. Audit log - written only AFTER the session is gone, so a database
// problem can never stop someone from logging out (the page leaves
// regardless of this response; see CropGuardSession.logout()).
if ($loggedOutId) {
    try {
        require_once '../config/db_connection.php';
        require_once '../audit/log_action.php';
        logAction($conn, $loggedOutId, $loggedOutRole, 'Logout', "{$loggedOutName} logged out", 'Authentication');
    } catch (Throwable $e) {
        // Logging is best-effort here; the logout itself already succeeded.
    }
}

// 5. Return success
echo json_encode(['status' => 'success']);
?>