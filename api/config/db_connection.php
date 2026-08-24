<?php
// api/config/db_connection.php

// Auto-detects which environment this is running in (local XAMPP, InfinityFree,
// or Railway) so this SAME file works correctly everywhere without ever needing
// to be edited when you switch between them.

if (getenv('RAILWAY_ENVIRONMENT')) {
    // Live deployment on Railway - reads connection details directly from the
    // environment variables Railway injects automatically (via the Variable
    // Reference you linked between the MySQL service and this app service).
    $host     = getenv('MYSQLHOST');
    $username = getenv('MYSQLUSER');
    $password = getenv('MYSQLPASSWORD');
    $dbname   = getenv('MYSQLDATABASE');
    $port     = getenv('MYSQLPORT') ?: 3306;
    $conn = new mysqli($host, $username, $password, $dbname, $port);

} else {
    $host_name = $_SERVER['HTTP_HOST'] ?? '';

    if (strpos($host_name, 'infinityfreeapp.com') !== false) {
        // Live deployment on InfinityFree
        $host     = "sql107.infinityfree.com";
        $username = "if0_42694163";
        $password = "REMOVED_DB_PASSWORD";
        $dbname   = "if0_42694163_crop_damage";
    } else {
        // Local XAMPP development (unchanged from before)
        $host     = "localhost";
        $username = "root";
        $password = "";
        $dbname   = "crop_damage_system";
    }
    $conn = new mysqli($host, $username, $password, $dbname);
}

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