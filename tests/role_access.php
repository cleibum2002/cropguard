<?php
// tests/role_access.php
// Role-based access testing: each of the four roles (Admin, MAO, Technician,
// Farmer) - plus a visitor who isn't logged in - tries actions that belong to
// other roles. Every attempt that isn't theirs must be refused, and nothing in
// the database may change.
//
// Run:  D:\Xampp\php\php.exe tests\role_access.php

require __DIR__ . '/support.php';

$adminId  = makeUser('Admin', 'rb.admin');
$maoId    = makeUser('MAO', 'rb.mao');
$techId   = makeUser('Technician', 'rb.tech');
$otherTech = makeUser('Technician', 'rb.tech2');
$farmerA  = makeUser('Farmer', 'rb.farmer.a');
makeUser('Farmer', 'rb.farmer.b');
$demoteId = makeUser('Admin', 'rb.demote');

$typeId = makeCropType('Access Rice', 40000);
$itemId = makeItem('Access Seeds', 50);
[$loc, $crop] = makeFarm($farmerA, $typeId, 4);
$validatedReport = makeReport($farmerA, $loc, $crop, 2, 'Moderate', 'Validated');
$othersReport    = makeReport($farmerA, $loc, $crop, 1, 'Light', 'Validated', ['technician' => $otherTech, 'visit_date' => date('Y-m-d')]);

$guest   = new Client();
$admin   = new Client(); loginAs($admin, emailFor('rb.admin'));
$mao     = new Client(); loginAs($mao, emailFor('rb.mao'));
$tech    = new Client(); loginAs($tech, emailFor('rb.tech'));
$farmerAc = new Client(); loginAs($farmerAc, emailFor('rb.farmer.a'));
$farmerBc = new Client(); loginAs($farmerBc, emailFor('rb.farmer.b'));

test('rb101 admin can list user accounts', function () use ($admin) {
    assertSame('success', $admin->get('api/masterfile/users/get_all.php')['status'], 'status');
});

test('rb102 MAO cannot list user accounts', function () use ($mao) {
    assertSame('Unauthorized.', $mao->get('api/masterfile/users/get_all.php')['message'] ?? '', 'refused');
});

test('rb103 technician cannot list user accounts', function () use ($tech) {
    assertSame('Unauthorized.', $tech->get('api/masterfile/users/get_all.php')['message'] ?? '', 'refused');
});

test('rb104 farmer cannot list user accounts', function () use ($farmerAc) {
    assertSame('Unauthorized.', $farmerAc->get('api/masterfile/users/get_all.php')['message'] ?? '', 'refused');
});

test('rb105 visitor who is not logged in is refused by staff pages', function () use ($guest) {
    foreach ([
        'api/masterfile/users/get_all.php',
        'api/masterfile/damage_report/get_all.php',
        'api/mao/get_aid_ledger.php',
        'api/dashboard/heatmap_data.php',
    ] as $endpoint) {
        assertSame('error', $guest->get($endpoint)['status'], $endpoint);
    }
});

test('rb106 farmer cannot approve reports or take relief stock', function () use ($farmerAc, $validatedReport, $itemId) {
    $res = $farmerAc->post('api/masterfile/damage_report/approve_with_aid.php', [
        'report_id' => $validatedReport, 'category' => 'Seeds', 'quantity' => 5, 'unit' => 'bags', 'item_id' => $itemId,
    ]);
    assertSame('error', $res['status'], 'refused');
    assertSame('Validated', reportRow($validatedReport)['Status'], 'report unchanged');
    assertSame(50.0, stockOf($itemId), 'stock unchanged');
});

test('rb107 technician cannot validate or reject reports', function () use ($tech, $validatedReport) {
    $res = $tech->post('api/masterfile/damage_report/update_status.php', ['id' => $validatedReport, 'status' => 'Rejected']);
    assertSame('Unauthorized.', $res['message'], 'refused');
    assertSame('Validated', reportRow($validatedReport)['Status'], 'report unchanged');
});

test("rb108 farmer cannot see another farmer's loss estimate", function () use ($farmerAc, $farmerBc, $validatedReport) {
    $own = $farmerAc->get("api/dashboard/calculate_cost.php?report_id=$validatedReport");
    assertSame('success', $own['status'], 'owner can see it');
    $other = $farmerBc->get("api/dashboard/calculate_cost.php?report_id=$validatedReport");
    assertSame('Unauthorized.', $other['message'], 'another farmer cannot');
});

test('rb109 MAO can view damage reports and the aid ledger', function () use ($mao) {
    assertSame('success', $mao->get('api/masterfile/damage_report/get_all.php')['status'], 'damage reports');
    assertSame('success', $mao->get('api/mao/get_aid_ledger.php')['status'], 'aid ledger');
});

test('rb110 admin cannot change their own role or deactivate themselves', function () use ($admin, $adminId) {
    $admin->post('api/masterfile/users/update.php', [
        'id' => $adminId, 'fullname' => 'Test Rb Admin', 'email' => emailFor('rb.admin'),
        'role' => 'Farmer', 'status' => 'Inactive',
    ]);
    $u = row('SELECT Role, STATUS FROM users WHERE User_ID = ?', 'i', $adminId);
    assertSame('Admin', $u['Role'], 'role kept');
    assertSame('Active', $u['STATUS'], 'status kept');
});

test('rb111 admin cannot delete their own account', function () use ($admin, $adminId) {
    $res = $admin->post('api/masterfile/users/soft_delete.php', ['id' => $adminId]);
    assertContains("can't deactivate your own account", $res['message'], 'refused');
    assertSame('Active', row('SELECT STATUS FROM users WHERE User_ID = ?', 'i', $adminId)['STATUS'], 'still active');
});

test('rb112 demoted user loses access on their next page load', function () use ($demoteId) {
    $c = new Client();
    loginAs($c, emailFor('rb.demote'));
    q("UPDATE users SET Role = 'MAO' WHERE User_ID = ?", 'i', $demoteId); // another admin demotes them
    $session = $c->get('api/auth/session_check.php');
    assertSame('inactive', $session['status'], 'session ended');
    assertSame('role_changed', $session['reason'], 'reason');
});

test("rb113 technician cannot inspect a report assigned to someone else", function () use ($tech, $othersReport) {
    $res = $tech->postForm('api/technician/submit_validation.php', [
        'id' => $othersReport, 'observations' => 'Trying to inspect a report that is not mine.', 'severity' => 'High', 'damaged_area' => '1',
    ]);
    assertSame('error', $res['status'], 'refused');
    assertSame('Validated', reportRow($othersReport)['Status'], 'report unchanged');
});

test('rb114 MAO cannot approve a report directly, skipping aid and stock', function () use ($mao, $validatedReport) {
    // The old api/dashboard/mao_update_report.php allowed exactly this; it was removed.
    $code = $mao->postStatus('api/dashboard/mao_update_report.php', ['id' => $validatedReport, 'status' => 'Approved']);
    assertSame(404, $code, 'old endpoint is gone');
    $res = $mao->post('api/masterfile/damage_report/update_status.php', ['id' => $validatedReport, 'status' => 'Approved']);
    assertSame('Invalid status value.', $res['message'], 'the current endpoint refuses Approved');
    assertSame('Validated', reportRow($validatedReport)['Status'], 'report unchanged');
});

TestRun::run('Tests\Feature\RoleBasedAccessTest');
