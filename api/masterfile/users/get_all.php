<?php
// File Location: api/masterfile/users/get_all.php
session_start();
header('Content-Type: application/json');
require_once '../../config/db_connection.php';

try {
    // Selects all users. We rename the columns using 'AS' so it matches your JavaScript exactly.
    $query = "SELECT User_ID as id, Fullname as fullname, Email as email, Contact_Number as contact_number, Role as role, STATUS as status 
              FROM users 
              ORDER BY User_ID DESC";
              
    $result = $conn->query($query);
    $users = [];
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $users[] = $row;
        }
    }
    
    echo json_encode(['status' => 'success', 'data' => $users]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
?>