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

    // Re-check the account against the database on every session check, instead
    // of trusting the Role/STATUS copied into the session at login. Without
    // this, deactivating or demoting someone did nothing until they happened to
    // log out - they kept full privileges for their whole session. session_guard
    // calls this on every page load and heartbeat, so a revoked account now
    // loses access within one navigation.
    //
    // Fails OPEN on a DB hiccup (keeps the session) rather than logging everyone
    // out on a transient error - the idle check above has already run.
    try {
        require_once '../config/db_connection.php';
        $stmt = $conn->prepare("SELECT Role, STATUS FROM users WHERE User_ID = ?");
        $stmt->bind_param("i", $_SESSION['User_ID']);
        $stmt->execute();
        $acct = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$acct || $acct['STATUS'] !== 'Active') {
            // Deleted or deactivated since login.
            cg_destroy_session();
            echo json_encode(['status' => 'inactive', 'reason' => 'revoked']);
            exit;
        }
        if ($acct['Role'] !== $_SESSION['Role']) {
            // Role changed since login (e.g. demoted from Admin). Force a clean
            // re-login so they land on the dashboard their new role allows.
            cg_destroy_session();
            echo json_encode(['status' => 'inactive', 'reason' => 'role_changed']);
            exit;
        }
    } catch (Throwable $e) {
        // DB unreachable - fall through on the existing session values.
    }

    cg_touch_session();

    echo json_encode([
        'status' => 'active',
        'role' => $_SESSION['Role'],
        'weak_password' => ($_SESSION['Weak_Password'] ?? false) && !($_SESSION['Weak_Password_Alert_Dismissed'] ?? false),
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