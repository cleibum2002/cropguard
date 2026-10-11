<?php
// api/config/session_policy.php
//
// Single source of truth for how long a session may sit idle. Included by
// session_check.php (which enforces it) and heartbeat.php (which refreshes it),
// so the limit can never drift between the two.
//
// The browser runs its own 30-minute timer as well, but that one only exists
// while the tab is open and was bypassable by refreshing instead of clicking
// OK on the expiry dialog. This server-side check is the one that actually
// ends the session.

if (!defined('CG_IDLE_LIMIT_SECONDS')) {
    define('CG_IDLE_LIMIT_SECONDS', 1800); // 30 minutes, matches the client timer
}

if (!function_exists('cg_session_is_idle_expired')) {
    // Sessions created before this policy existed carry no Last_Activity stamp.
    // Those are treated as still valid and get stamped on their next check,
    // rather than logging everyone out the moment this deploys.
    function cg_session_is_idle_expired() {
        if (!isset($_SESSION['Last_Activity'])) return false;
        return (time() - (int)$_SESSION['Last_Activity']) > CG_IDLE_LIMIT_SECONDS;
    }
}

if (!function_exists('cg_touch_session')) {
    function cg_touch_session() {
        $_SESSION['Last_Activity'] = time();
    }
}

if (!function_exists('cg_destroy_session')) {
    // Identical teardown to logout.php, so an expired session is destroyed
    // exactly as thoroughly as an explicit logout.
    function cg_destroy_session() {
        $_SESSION = array();
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }
}
?>
