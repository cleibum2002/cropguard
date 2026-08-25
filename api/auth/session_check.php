<?php
ob_start(); 
session_start();
ob_clean(); 

header('Content-Type: application/json');

echo json_encode([
    'session_id' => session_id(),
    'session_save_path' => session_save_path(),
    'session_data' => $_SESSION,
    'cookie_received' => $_COOKIE['PHPSESSID'] ?? 'NO COOKIE RECEIVED',
]);
exit;
?>