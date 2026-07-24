<?php
// File Location: api/audit/log_action.php
// Call this function from any API to log an action
function logAction($conn, $user_id, $user_role, $action, $description, $module) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $stmt = $conn->prepare(
        "INSERT INTO system_logs (User_ID, User_Role, Action, Description, Module, IP_Address) 
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param("isssss", $user_id, $user_role, $action, $description, $module, $ip);
    $stmt->execute();
    $stmt->close();
}
?>