<?php
// api/auth/heartbeat.php
//
// Refreshes the session's Last_Activity stamp. The browser pings this at most
// once a minute while the user is actually interacting with the page, so the
// server-side idle timeout tracks real activity instead of only page loads.
//
// Without it, someone working on a single long-lived page (filling in a damage
// report, reading the map) would be logged out on their next navigation even
// though they were never idle.

ob_start();
session_start();
ob_clean();

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require_once '../config/session_policy.php';

if (!isset($_SESSION['User_ID'])) {
    echo json_encode(['status' => 'inactive']);
    exit;
}

// An already-expired session must never be revived by a heartbeat, or a
// forgotten background tab could keep a session alive indefinitely.
if (cg_session_is_idle_expired()) {
    cg_destroy_session();
    echo json_encode(['status' => 'inactive', 'reason' => 'expired']);
    exit;
}

cg_touch_session();
echo json_encode(['status' => 'active']);
exit;
?>
