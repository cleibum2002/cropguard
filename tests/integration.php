<?php
// tests/integration.php
// Integration tests: these call CropGuard's real endpoints over HTTP, the same
// way the browser does, so each test checks several parts working together -
// the endpoint, the session, the database and the role checks.
//
// Needs XAMPP running (Apache + MySQL). Runs against the LOCAL site only.
//
// Run:  D:\Xampp\php\php.exe tests\integration.php

require __DIR__ . '/support.php';

const FARMER_CODE = '482915';

$farmerEmail = emailFor('it.farmer');
$adminEmail  = emailFor('it.admin');
$newEmail    = emailFor('it.register');

$farmerId = makeUser('Farmer', 'it.farmer', ['verified' => 0, 'code' => FARMER_CODE]);
makeUser('Admin', 'it.admin');

$guest  = new Client();
$farmer = new Client();
$admin  = new Client();

// A complete, valid sign-up (2026-10-11 form); each test breaks one field.
$signup = fn(array $override) => array_merge([
    'last_name' => 'Dela Cruz', 'first_name' => 'Juan', 'middle_name' => '', 'suffix' => 'N/A',
    'birth_date' => '1980-05-20', 'gender' => 'Male', 'civil_status' => 'Married',
    'email' => $newEmail, 'contact_number' => '09171234567', 'rsbsa_no' => '00-00-00-999-000001', 'password' => 'Palay@2026',
], $override);

test('it101 registration rejects a blank name', function () use ($guest, $signup) {
    $res = $guest->post('api/auth/register.php', $signup(['last_name' => '   ']));
    assertSame('error', $res['status'], 'status');
    assertContains('fill in all required fields', $res['message'], 'message');
});

test('it102 registration rejects an invalid mobile number', function () use ($guest, $signup) {
    $res = $guest->post('api/auth/register.php', $signup(['contact_number' => '12345']));
    assertSame('error', $res['status'], 'status');
    assertContains('mobile number', $res['message'], 'message');
});

test('it103 registration rejects a weak password', function () use ($guest, $newEmail, $signup) {
    $res = $guest->post('api/auth/register.php', $signup(['password' => 'short']));
    assertSame('error', $res['status'], 'status');
    assertNull(row('SELECT User_ID FROM users WHERE Email = ?', 's', $newEmail), 'no account was created');
    assertNull(row('SELECT Request_ID FROM registration_requests WHERE Email = ?', 's', $newEmail), 'no request was filed');
});

test('it104 unverified account is blocked at login', function () use ($farmer, $farmerEmail) {
    $res = $farmer->post('api/auth/login.php', ['email' => $farmerEmail, 'password' => TEST_PASSWORD]);
    assertSame('error', $res['status'], 'status');
    assertContains('verify your email', $res['message'], 'message');
});

test('it105 wrong verification code is rejected', function () use ($guest, $farmerEmail) {
    $res = $guest->post('api/auth/verify_code.php', ['email' => $farmerEmail, 'code' => '000000']);
    assertSame('error', $res['status'], 'status');
    assertSame(0, (int)row('SELECT Email_Verified FROM users WHERE Email = ?', 's', $farmerEmail)['Email_Verified'], 'account still unverified');
});

test('it106 correct verification code activates the account', function () use ($guest, $farmerEmail) {
    $res = $guest->post('api/auth/verify_code.php', ['email' => $farmerEmail, 'code' => FARMER_CODE]);
    assertSame('success', $res['status'], 'status');
    assertSame(1, (int)row('SELECT Email_Verified FROM users WHERE Email = ?', 's', $farmerEmail)['Email_Verified'], 'account now verified');
});

test('it107 login returns a session and the farmer dashboard', function () use ($farmer, $farmerEmail, $farmerId) {
    $res = $farmer->post('api/auth/login.php', ['email' => $farmerEmail, 'password' => TEST_PASSWORD]);
    assertSame('success', $res['status'], 'status');
    assertSame('views/farmers/index.html', $res['redirect'], 'redirect');

    $session = $farmer->get('api/auth/session_check.php');
    assertSame('active', $session['status'], 'session status');
    assertSame('Farmer', $session['role'], 'session role');
    assertSame($farmerId, (int)$session['user']['id'], 'session user');
});

test('it108 wrong password is rejected and counted', function () use ($farmerEmail) {
    $res = (new Client())->post('api/auth/login.php', ['email' => $farmerEmail, 'password' => 'Wrong@Pass1']);
    assertSame('error', $res['status'], 'status');
    assertSame('Invalid email or password', $res['message'], 'message');
    assertSame(1, (int)row('SELECT Failed_Login_Attempts FROM users WHERE Email = ?', 's', $farmerEmail)['Failed_Login_Attempts'], 'failed attempt counted');
});

test('it109 guest cannot open an admin-only endpoint', function () use ($guest) {
    $res = $guest->get('api/masterfile/users/get_all.php');
    assertSame('error', $res['status'], 'status');
    assertSame('Unauthorized.', $res['message'], 'message');
});

test('it110 farmer session is refused by an admin-only endpoint', function () use ($farmer) {
    $res = $farmer->get('api/masterfile/users/get_all.php');
    assertSame('error', $res['status'], 'status');
    assertSame('Unauthorized.', $res['message'], 'message');
});

test('it111 admin session can query the user list endpoint', function () use ($admin, $adminEmail, $farmerEmail) {
    $login = loginAs($admin, $adminEmail);
    assertSame('views/masterfile/dashboard.html', $login['redirect'], 'admin redirect');

    $res = $admin->get('api/masterfile/users/get_all.php');
    assertSame('success', $res['status'], 'status');
    assertTrue(in_array($farmerEmail, array_column($res['data'], 'email'), true), 'list includes the new farmer');
});

test('it112 logout ends the session', function () use ($farmer) {
    $res = $farmer->get('api/auth/logout.php');
    assertSame('success', $res['status'], 'logout');
    assertSame('inactive', $farmer->get('api/auth/session_check.php')['status'], 'session after logout');
});

TestRun::run('Tests\Feature\CropGuardIntegrationTest');
