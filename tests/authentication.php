<?php
// tests/authentication.php
// Authentication testing: logging in and out, wrong or missing credentials,
// account lockout, unverified/deactivated accounts, email verification codes,
// and security checks (SQL injection, password hashes never sent back, session
// ID renewed at login).
//
// Run:  D:\Xampp\php\php.exe tests\authentication.php

require __DIR__ . '/support.php';

makeUser('Admin', 'auth.admin');
makeUser('Farmer', 'auth.farmer');
makeUser('Farmer', 'auth.lock');
makeUser('Farmer', 'auth.inactive', ['status' => 'Inactive']);
makeUser('Farmer', 'auth.unverified', ['verified' => 0, 'code' => '246810']);
makeUser('Farmer', 'auth.expired', ['verified' => 0, 'code' => '135790', 'expires' => date('Y-m-d H:i:s', strtotime('-1 minute'))]);
$revokeId = makeUser('Farmer', 'auth.revoke');

function tryLogin(string $email, string $password, ?Client $c = null): array
{
    return ($c ?? new Client())->post('api/auth/login.php', ['email' => $email, 'password' => $password]);
}

test('at101 valid login returns a session and the right dashboard', function () {
    $c = new Client();
    $res = tryLogin(emailFor('auth.admin'), TEST_PASSWORD, $c);
    assertSame('success', $res['status'], 'status');
    assertSame('views/masterfile/dashboard.html', $res['redirect'], 'admin dashboard');
    $session = $c->get('api/auth/session_check.php');
    assertSame('active', $session['status'], 'session active');
    assertSame('Admin', $session['role'], 'session role');
});

test('at102 wrong password is rejected', function () {
    $res = tryLogin(emailFor('auth.farmer'), 'Wrong@Pass1');
    assertSame('error', $res['status'], 'status');
    assertSame('Invalid email or password', $res['message'], 'message');
});

test('at103 empty or missing fields are rejected', function () {
    $missing = (new Client())->post('api/auth/login.php', []);
    assertSame('error', $missing['status'], 'missing fields');
    $empty = tryLogin('', '');
    assertSame('error', $empty['status'], 'empty fields');
});

test('at104 unknown email gives the same message as a wrong password', function () {
    $res = tryLogin('nobody.here@example.test', 'Any@Pass123');
    assertSame('error', $res['status'], 'status');
    assertSame('Invalid email or password', $res['message'], 'no hint whether the email exists');
});

test('at105 account locks for 15 minutes after 5 wrong passwords', function () {
    for ($i = 1; $i <= 5; $i++) {
        tryLogin(emailFor('auth.lock'), "Wrong@Pass$i");
    }
    $u = row('SELECT Failed_Login_Attempts, Lockout_Until FROM users WHERE Email = ?', 's', emailFor('auth.lock'));
    assertSame(5, (int)$u['Failed_Login_Attempts'], 'five failures counted');
    assertTrue(strtotime($u['Lockout_Until']) > time(), 'lockout set');
    $res = tryLogin(emailFor('auth.lock'), TEST_PASSWORD);
    assertContains('Too many failed attempts', $res['message'], 'even the right password is refused while locked');
});

test('at106 deactivated account cannot log in', function () {
    $res = tryLogin(emailFor('auth.inactive'), TEST_PASSWORD);
    assertSame('error', $res['status'], 'status');
    assertContains('deactivated', $res['message'], 'message');
});

test('at107 unverified email cannot log in', function () {
    $res = tryLogin(emailFor('auth.unverified'), TEST_PASSWORD);
    assertSame('error', $res['status'], 'status');
    assertContains('verify your email', $res['message'], 'message');
});

test('at108 expired verification code is rejected', function () {
    $res = (new Client())->post('api/auth/verify_code.php', ['email' => emailFor('auth.expired'), 'code' => '135790']);
    assertSame('error', $res['status'], 'status');
    assertContains('expired', $res['message'], 'message');
});

test('at109 sql injection in the login form is blocked', function () {
    foreach (["' OR '1'='1' -- ", "admin@example.test' OR 1=1 #", '" OR ""="'] as $attack) {
        $c = new Client();
        $res = tryLogin($attack, $attack, $c);
        assertSame('error', $res['status'], "attack: $attack");
        assertSame('inactive', $c->get('api/auth/session_check.php')['status'], 'no session given');
    }
});

test('at110 password hashes are never sent back to the browser', function () {
    $c = new Client();
    $bodies = json_encode(tryLogin(emailFor('auth.admin'), TEST_PASSWORD, $c))
            . json_encode($c->get('api/auth/session_check.php'))
            . json_encode($c->get('api/masterfile/users/get_all.php'));
    assertTrue(strpos($bodies, '$2y$') === false, 'no bcrypt hash in any response');
    assertTrue(stripos($bodies, '"password"') === false, 'no password field in any response');
});

test('at111 session id is renewed at login', function () {
    $c = new Client();
    $c->get('api/auth/session_check.php'); // gets a guest session id first
    $before = $c->cookie('PHPSESSID');
    tryLogin(emailFor('auth.farmer'), TEST_PASSWORD, $c);
    $after = $c->cookie('PHPSESSID');
    assertTrue($before !== null && $after !== null, 'session cookies present');
    assertTrue($before !== $after, 'a planted session id cannot be reused after login');
});

test('at112 logout ends the session', function () {
    $c = new Client();
    loginAs($c, emailFor('auth.farmer'));
    assertSame('success', $c->get('api/auth/logout.php')['status'], 'logout');
    assertSame('inactive', $c->get('api/auth/session_check.php')['status'], 'session after logout');
});

test('at113 deactivated user is signed out on their next page load', function () use ($revokeId) {
    $c = new Client();
    loginAs($c, emailFor('auth.revoke'));
    q("UPDATE users SET STATUS = 'Inactive' WHERE User_ID = ?", 'i', $revokeId); // admin deactivates them
    $session = $c->get('api/auth/session_check.php');
    assertSame('inactive', $session['status'], 'session ended');
    assertSame('revoked', $session['reason'], 'reason');
});

TestRun::run('Tests\Feature\AuthenticationTest');
