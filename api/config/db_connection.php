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
?>