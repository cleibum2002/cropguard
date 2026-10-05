<?php
// tests/audit_log.php
// Audit logging testing (FR-07): each important action, done through the real
// endpoints, must leave an audit-log entry recording who did it, their real
// role, what changed, when, and from which IP - and only Admins may read it.
//
// Run:  D:\Xampp\php\php.exe tests\audit_log.php

require __DIR__ . '/support.php';

$adminId  = makeUser('Admin', 'al.admin');
$maoId    = makeUser('MAO', 'al.mao');
$techId   = makeUser('Technician', 'al.tech');
$farmerId = makeUser('Farmer', 'al.farmer');
$typeId   = makeCropType('Audit Rice', 40000);
$aidItem  = makeItem('Audit Aid Seeds', 50);
$editItem = makeItem('Audit Stock Seeds', 70);
[$loc, $crop] = makeFarm($farmerId, $typeId, 5);

$pendingReport   = makeReport($farmerId, $loc, $crop, 1, 'Moderate', 'Pending');
$validatedReport = makeReport($farmerId, $loc, $crop, 1, 'Severe', 'Validated');
$inspectedReport = makeReport($farmerId, $loc, $crop, 2, 'Severe', 'Inspected');

$admin = new Client(); loginAs($admin, emailFor('al.admin'));
$mao   = new Client(); loginAs($mao, emailFor('al.mao'));

// Newest log entry for this user and action, or null.
function latestLog(int $userId, string $action): ?array
{
    return row('SELECT * FROM system_logs WHERE User_ID = ? AND Action = ? ORDER BY Log_ID DESC LIMIT 1', 'is', $userId, $action);
}

function mustHaveLog(int $userId, string $action): array
{
    $log = latestLog($userId, $action);
    if (!$log) {
        throw new Exception("no '$action' entry was written to the audit log");
    }
    return $log;
}

test('al101 a successful login is logged', function () use ($adminId) {
    $log = mustHaveLog($adminId, 'Login');
    assertSame('Authentication', $log['Module'], 'module');
});

test('al102 a failed login is logged with the attempt count', function () use ($farmerId) {
    (new Client())->post('api/auth/login.php', ['email' => emailFor('al.farmer'), 'password' => 'Wrong@Pass1']);
    assertContains('attempt 1 of 5', mustHaveLog($farmerId, 'Failed Login')['Description'], 'description');
});

test('al103 a logout is logged', function () use ($farmerId) {
    $c = new Client();
    loginAs($c, emailFor('al.farmer'));
    $c->get('api/auth/logout.php');
    assertSame('Authentication', mustHaveLog($farmerId, 'Logout')['Module'], 'module');
});

test('al104 creating a user account is logged', function () use ($admin, $adminId) {
    $admin->post('api/masterfile/users/create.php', [
        'fullname' => 'Test Al Newuser', 'email' => emailFor('al.newuser'), 'password' => TEST_PASSWORD,
        'role' => 'Technician', 'status' => 'Active', 'contact_number' => '09171234567',
    ]);
    $log = mustHaveLog($adminId, 'Create User');
    assertContains('Technician', $log['Description'], 'role recorded');
    assertContains(emailFor('al.newuser'), $log['Description'], 'email recorded');
    assertSame('User Management', $log['Module'], 'module');
});

test("al105 changing a user's role is logged with old and new role", function () use ($admin, $adminId) {
    $newId = (int)row('SELECT User_ID FROM users WHERE Email = ?', 's', emailFor('al.newuser'))['User_ID'];
    $admin->post('api/masterfile/users/update.php', [
        'id' => $newId, 'fullname' => 'Test Al Newuser', 'email' => emailFor('al.newuser'),
        'role' => 'MAO', 'status' => 'Active',
    ]);
    assertContains('role Technician -> MAO', mustHaveLog($adminId, 'Update User')['Description'], 'description');
});

test('al106 deactivating a user account is logged', function () use ($admin, $adminId) {
    $newId = (int)row('SELECT User_ID FROM users WHERE Email = ?', 's', emailFor('al.newuser'))['User_ID'];
    $admin->post('api/masterfile/users/soft_delete.php', ['id' => $newId]);
    assertContains("#{$newId}", mustHaveLog($adminId, 'Deactivate User')['Description'], 'description');
});

test('al107 MAO validating a report is logged under the MAO role', function () use ($mao, $maoId, $pendingReport) {
    $mao->post('api/masterfile/damage_report/update_status.php', ['id' => $pendingReport, 'status' => 'Validated']);
    $log = mustHaveLog($maoId, 'Validate Report');
    assertSame('MAO', $log['User_Role'], 'role');
    assertContains("#{$pendingReport}", $log['Description'], 'report recorded');
});

test('al108 assigning a technician is logged with the visit date', function () use ($admin, $adminId, $techId, $validatedReport) {
    $date = date('Y-m-d', strtotime('+2 days'));
    $admin->post('api/masterfile/damage_report/assign_technician.php', [
        'report_id' => $validatedReport, 'technician_id' => $techId, 'visit_date' => $date,
    ]);
    $log = mustHaveLog($adminId, 'Assign Technician');
    assertContains('Test Al Tech', $log['Description'], 'technician recorded');
    assertContains($date, $log['Description'], 'visit date recorded');
});

test("al109 an aid approval is logged with the approver's real role", function () use ($admin, $adminId, $mao, $maoId, $inspectedReport, $aidItem) {
    $body = ['report_id' => $inspectedReport, 'category' => 'Seeds', 'quantity' => 5, 'unit' => 'bags', 'item_id' => $aidItem];
    // Approving aid is Admin only: the MAO is refused and nothing is logged for it.
    assertSame('Unauthorized access.', $mao->post('api/masterfile/damage_report/approve_with_aid.php', $body)['message'], 'MAO refused');
    assertNull(latestLog($maoId, 'Approve Report'), 'no approval logged for the MAO');
    $admin->post('api/masterfile/damage_report/approve_with_aid.php', $body);
    assertSame('Admin', mustHaveLog($adminId, 'Approve Report')['User_Role'], 'recorded as Admin');
});

test('al110 a crop cost change is logged with old and new cost', function () use ($admin, $adminId, $typeId) {
    $admin->post('api/masterfile/crop_type/update.php', [
        'id' => $typeId, 'name' => TEST_PREFIX . 'Audit Rice', 'type' => 'Rice',
        'period' => '115 Days', 'prod_cost' => 45000, 'description' => '',
    ]);
    assertContains('P40,000.00 -> P45,000.00', mustHaveLog($adminId, 'Update Crop')['Description'], 'description');
});

test('al111 a manual stock edit is logged with old and new stock', function () use ($admin, $adminId, $editItem) {
    $admin->post('api/masterfile/items/update.php', [
        'id' => $editItem, 'item_name' => TEST_PREFIX . 'Audit Stock Seeds', 'category' => 'Seeds', 'unit' => 'bags',
        'price' => 0, 'current_stock' => 700, 'description' => '', 'status' => 'Active',
    ]);
    $log = mustHaveLog($adminId, 'Update Item');
    assertContains('stock 70 -> 700', $log['Description'], 'description');
    assertSame('Inventory', $log['Module'], 'module');
});

test('al112 every entry records who, role, time and IP address', function () use ($adminId) {
    $log = mustHaveLog($adminId, 'Login');
    assertSame($adminId, (int)$log['User_ID'], 'user');
    assertSame('Admin', $log['User_Role'], 'role');
    // Compared against the database's own clock: Created_At is stamped by
    // MySQL, and PHP's timezone setting can differ from it (XAMPP ships PHP on
    // Europe/Berlin while MySQL follows the system clock).
    $age = (int)row('SELECT TIMESTAMPDIFF(SECOND, ?, NOW()) AS age', 's', $log['Created_At'])['age'];
    assertTrue($age >= 0 && $age < 600, 'timestamp is current');
    assertTrue(trim((string)$log['IP_Address']) !== '', 'IP address recorded');
});

test('al113 only admins can read the audit log', function () use ($admin, $mao) {
    assertSame('Unauthorized.', $mao->get('api/audit/get_logs.php')['message'] ?? '', 'MAO refused');
    assertSame('success', $admin->get('api/audit/get_logs.php')['status'], 'admin allowed');
});

TestRun::run('Tests\Feature\AuditLogTest');
