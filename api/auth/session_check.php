<?php
// Start buffering to "catch" any accidental echoes from other files
ob_start(); 

session_start();

// Clear any text/errors that might have been printed by other files up to this point
ob_clean(); 

header('Content-Type: application/json');

if (isset($_SESSION['User_ID']) && isset($_SESSION['Role'])) {
    echo json_encode([
        'status' => 'active',
        'role' => $_SESSION['Role'],
        'user' => [
            'fullname' => $_SESSION['Fullname'],
            'id' => $_SESSION['User_ID']
        ]
    ]);
} else {
    echo json_encode(['status' => 'inactive']);
}

// Ensure nothing else is appended after the JSON
exit; 
?>