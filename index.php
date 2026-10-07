<?php
// The bare address (https://...railway.app/ or http://localhost/da_system/)
// shows the login page itself instead of redirecting to /auth.html, so the
// address bar stays clean. auth.html sits in this same folder, so all of its
// relative paths (assets, api, the dashboards after login) work unchanged.
//
// Railway also sends any address it has no file for to this script. Those
// still get redirected - to the clean address - rather than shown the login
// page in place, where a deeper path would break its relative links.
$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/') . '/';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ($path === $base || $path === $base . 'index.php') {
    header('Content-Type: text/html; charset=UTF-8');
    readfile(__DIR__ . '/auth.html');
    exit;
}

header('Location: ' . $base);
exit;
