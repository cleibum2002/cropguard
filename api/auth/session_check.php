<?php
ob_start(); 
session_start();
ob_clean(); 

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('Vary: *');

require_once '../config/session_policy.php';

if (isset($_SESSION['User_ID']) && isset($_SESSION['Role'])) {
    // Server-side idle timeout. The client's own timer only runs while its tab
    // is open, and refreshing the page used to skip it entirely, so this is the
    // check that actually ends an expired session.
    if (cg_session_is_idle_expired()) {
        cg_destroy_session();
        echo json_encode(['status' => 'inactive', 'reason' => 'expired']);
        exit;
    }
    cg_touch_session();

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