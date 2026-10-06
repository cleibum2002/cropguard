<?php
// api/config/db_connection.php

$host_name = $_SERVER['HTTP_HOST'] ?? '';

if (getenv('RAILWAY_ENVIRONMENT')) {
    // Live deployment on Railway - hardcoded because FrankenPHP/Caddy doesn't
    // reliably expose container env vars to PHP's getenv()/$_ENV/$_SERVER in
    // this setup (confirmed known issue, not specific to this project).
    $host     = "mysql.railway.internal";
    $username = "root";
    $password = "REMOVED_DB_PASSWORD";
    $dbname   = "railway";
    $port     = 3306;
    $conn = new mysqli($host, $username, $password, $dbname, $port);

} elseif (strpos($host_name, 'infinityfreeapp.com') !== false) {
    $host     = "sql107.infinityfree.com";
    $username = "if0_42694163";
    $password = "REMOVED_DB_PASSWORD";
    $dbname   = "if0_42694163_crop_damage";
    $conn = new mysqli($host, $username, $password, $dbname);

} else {
    $host     = "localhost";
    $username = "root";
    $password = "";
    $dbname   = "crop_damage_system";
    $conn = new mysqli($host, $username, $password, $dbname);
}

if ($conn->connect_error) {
    die(json_encode(["status" => "error", "message" => "Connection Failed: " . $conn->connect_error]));
}

$conn->set_charset("utf8mb4");

// Philippine time for both PHP and MySQL. Railway runs on UTC by default, so
// every new report, log and message was stamped 8 hours behind. A fixed
// +08:00 offset is used for MySQL because Railway's MySQL has no named
// timezone tables loaded (the Philippines has no daylight saving time).
date_default_timezone_set('Asia/Manila');
$conn->query("SET time_zone = '+08:00'");

function safeErrorMessage($e) {
    error_log('[' . date('Y-m-d H:i:s') . '] ' . $e->getMessage());
    return 'A server error occurred. Please try again or contact the administrator.';
}

// Reusable check for any expiring token/timestamp stored in the database
// (e.g. Verification_Token_Expiry). Returns true if the given timestamp
// is already in the past relative to the current server time.
function isTokenExpired($expiryTimestamp) {
    if (empty($expiryTimestamp)) {
        return true; // no expiry value at all counts as expired/invalid
    }
    return strtotime($expiryTimestamp) < time();
}
?>