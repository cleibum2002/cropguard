<?php
ob_start(); 
session_start();
ob_clean(); 

header('Content-Type: application/json');

if (isset($_SESSION['User_ID']) && isset($_SESSION['Role'])) {
    echo json_encode([
        'status' => 'active',
        'role' => $_SESSION['Role'],
        'weak_password' => ($_SESSION['Weak_Password'] ?? false) && !($_SESSION['Weak_Password_Alert_Dismissed'] ?? false),
        'phone_unverified' => ($_SESSION['Phone_Unverified'] ?? false) && !($_SESSION['Phone_Alert_Dismissed'] ?? false),
        'user' => [
            'fullname' => $_SESSION['Fullname'],
            'id' => $_SESSION['User_ID']
        ]
    ]);
} else {
    echo json_encode(['status' => 'inactive']);
}
exit; 
?>