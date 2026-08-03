<?php
// api/config/db_connection.php

$host = "localhost";
$username = "root";
$password = "";
$dbname = "crop_damage_system";

$conn = new mysqli($host, $username, $password, $dbname);

if ($conn->connect_error) {
    die(json_encode(["status" => "error", "message" => "Connection Failed: " . $conn->connect_error]));
}

$conn->set_charset("utf8mb4");

// Logs the real error details server-side (visible only in the PHP error log)
// and returns a safe, generic message to send back to the client.
function safeErrorMessage($e) {
    error_log('[' . date('Y-m-d H:i:s') . '] ' . $e->getMessage());
    return 'A server error occurred. Please try again or contact the administrator.';
}
?>