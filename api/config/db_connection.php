<?php
// api/config/db_connection.php

// Database settings come from environment variables, never from this file
// (the repository is meant to be public). On Railway the web service gets the
// MySQL service's MYSQLHOST / MYSQLPORT / MYSQLUSER / MYSQLPASSWORD /
// MYSQLDATABASE as variable references. Locally (XAMPP) none are set, so the
// XAMPP defaults below are used - they hold no secret.
function cg_env($name) {
    $v = getenv($name);
    if ($v === false || $v === '') $v = $_ENV[$name] ?? $_SERVER[$name] ?? '';
    return (string)$v;
}

if (cg_env('MYSQLHOST') !== '') {
    $conn = new mysqli(
        cg_env('MYSQLHOST'),
        cg_env('MYSQLUSER'),
        cg_env('MYSQLPASSWORD'),
        cg_env('MYSQLDATABASE'),
        (int)(cg_env('MYSQLPORT') ?: 3306)
    );
} elseif (getenv('RAILWAY_ENVIRONMENT')) {
    // On Railway without the variables: stop with a plain message instead of
    // quietly trying a local database that does not exist there.
    error_log('[' . date('Y-m-d H:i:s') . '] MYSQLHOST and the other MYSQL* variables are not set on this Railway service.');
    die(json_encode(["status" => "error", "message" => "The server's database settings are missing. Please contact the administrator."]));
} else {
    $conn = new mysqli("localhost", "root", "", "crop_damage_system");
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