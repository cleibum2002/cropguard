<?php
// tests/system.php
// System testing: one damage report taken through the WHOLE CropGuard workflow,
// in order, by four different users - exactly as it happens in real use:
//   Farmer registers a farm -> files a report with a photo -> MAO validates ->
//   Admin schedules a technician -> Technician inspects -> Admin reviews the
//   loss estimate and approves with relief aid -> MAO hands out the aid.
// Each step builds on the one before it.
//
// Run:  D:\Xampp\php\php.exe tests\system.php

require __DIR__ . '/support.php';

$typeId   = makeCropType('System Rice', 45000);
$itemId   = makeItem('System Rice Seeds', 100);
$farmerId = makeUser('Farmer', 'sys.farmer');
$maoId    = makeUser('MAO', 'sys.mao');
$adminId  = makeUser('Admin', 'sys.admin');
$techId   = makeUser('Technician', 'sys.tech');

$farmer = new Client(); loginAs($farmer, emailFor('sys.farmer'));
$mao    = new Client(); loginAs($mao, emailFor('sys.mao'));
$admin  = new Client(); loginAs($admin, emailFor('sys.admin'));
$tech   = new Client(); loginAs($tech, emailFor('sys.tech'));

$s = new stdClass(); // carries the farm and report from step to step
$tomorrow = date('Y-m-d', strtotime('+1 day'));

test('st101 farmer registers a farm with GPS location', function () use ($farmer, $farmerId, $typeId, $s) {
    $res = $farmer->post('api/dashboard/submit_farm.php', [
        'type_id' => $typeId, 'barangay' => 'Poblacion', 'area_size' => 3,
        'latitude' => TEST_LAT, 'longitude' => TEST_LNG,
        'land_type' => 'Irrigated', 'cropping_period' => 'Wet Season',
    ]);
    assertSame('success', $res['status'], 'status');
    $farm = row('SELECT * FROM farm_location WHERE Farmer_ID = ?', 'i', $farmerId);
    $s->locationId = (int)$farm['Location_ID']; // passed on before the checks, so one failure doesn't cascade
    assertSame(3.0, (float)$farm['Area_Size'], 'farm size saved');
    assertTrue(row('SELECT Crop_ID FROM crop WHERE Location_ID = ?', 'i', $farm['Location_ID']) !== null, 'crop added to the farm');
});

test('st102 farmer files a damage report with a photo', function () use ($farmer, $farmerId, $typeId, $s) {
    $token = 'system-test-' . uniqid();
    $res = $farmer->postForm('api/dashboard/submit_report.php', [
        'location_id' => $s->locationId, 'type_id' => $typeId, 'area' => 2,
        'planting_date' => date('Y-m-d', strtotime('-60 days')),
        'damage_type' => 'Flood', 'severity' => 'Total Loss',
        'description' => 'Field flooded after the typhoon.', 'client_token' => $token,
        'geo_lat_0' => TEST_LAT, 'geo_lng_0' => TEST_LNG,
    ], ['images[0]' => [testFile('jpg'), 'image/jpeg', 'flooded_field.jpg']]);
    assertSame('success', $res['status'], 'status');

    $report = row('SELECT * FROM damage_report WHERE Client_Token = ? AND Farmer_ID = ?', 'si', $token, $farmerId);
    assertSame('Pending', $report['Status'], 'starts as Pending');
    assertSame(2.0, (float)$report['Affected_Area'], 'affected area saved');
    assertSame(1, count(imagesOf((int)$report['Damage_Report_ID'])), 'photo attached');
    $s->reportId = (int)$report['Damage_Report_ID'];
});

test('st103 admin and MAO are notified of the new report', function () use ($mao, $s) {
    $roles = array_column(q('SELECT Target_Role FROM admin_notification WHERE Related_Report_ID = ?', 'i', $s->reportId), 'Target_Role');
    assertTrue(in_array('Admin', $roles, true), 'admin notified');
    assertTrue(in_array('MAO', $roles, true), 'MAO notified');
    $bell = $mao->get('api/dashboard/mao_notifications.php?action=fetch');
    assertContains('#REP-' . $s->reportId, json_encode($bell['data']), 'shows in the MAO bell');
});

test('st104 MAO validates the report', function () use ($mao, $maoId, $s) {
    $res = $mao->post('api/masterfile/damage_report/update_status.php', ['id' => $s->reportId, 'status' => 'Validated']);
    assertSame('success', $res['status'], 'status');
    $report = reportRow($s->reportId);
    assertSame('Validated', $report['Status'], 'now Validated');
    assertSame($maoId, (int)$report['Validated_By'], 'records who validated it');
});

test('st105 admin schedules a technician for a field visit', function () use ($admin, $techId, $tomorrow, $s) {
    $res = $admin->post('api/masterfile/damage_report/assign_technician.php', [
        'report_id' => $s->reportId, 'technician_id' => $techId, 'visit_date' => $tomorrow,
    ]);
    assertSame('success', $res['status'], 'status');
    $report = reportRow($s->reportId);
    assertSame($techId, (int)$report['Technician_ID'], 'technician assigned');
    assertSame($tomorrow, $report['Visit_Date'], 'visit date saved');
});

test('st106 technician sees the visit in their assignment queue', function () use ($tech, $tomorrow, $s) {
    $res = $tech->get('api/technician/get_dashboard.php');
    assertSame('success', $res['status'], 'status');
    $mine = array_values(array_filter($res['assignments'], fn($a) => (int)$a['id'] === $s->reportId));
    assertSame(1, count($mine), 'report is in the queue');
    assertSame($tomorrow, $mine[0]['visit_date'], 'with the scheduled date');
});

test('st107 technician submits the field inspection with evidence', function () use ($tech, $s) {
    $res = $tech->postForm('api/technician/submit_validation.php', [
        'id' => $s->reportId, 'observations' => 'Standing water across most of the field; tillers rotting.',
        'severity' => 'Severe', 'geo_lat_0' => TEST_LAT, 'geo_lng_0' => TEST_LNG,
    ], ['evidencePhotos[0]' => [testFile('png'), 'image/png', 'evidence.png']]);
    assertSame('success', $res['status'], 'status');

    $report = reportRow($s->reportId);
    assertSame('Inspected', $report['Status'], 'now Inspected');
    assertSame('Severe', $report['Damage_Severity'], 'severity confirmed by technician');
    assertSame('Total Loss', $report['Farmer_Reported_Severity'], "farmer's original severity kept");
    $roles = array_column(imagesOf($s->reportId), 'Uploaded_By_Role');
    assertTrue(in_array('Technician', $roles, true), 'evidence photo saved');
});

test('st108 admin sees the estimated loss (2 ha x P45,000 x 80%)', function () use ($admin, $s) {
    $res = $admin->get('api/dashboard/calculate_cost.php?report_id=' . $s->reportId);
    assertSame('success', $res['status'], 'status');
    assertSame('80%', $res['severity_multiplier'], 'Severe = 80%');
    assertSame('72,000.00', $res['estimated_loss'], 'estimated loss');
});

test('st109 admin approves the report with relief aid', function () use ($admin, $adminId, $itemId, $s) {
    $res = $admin->post('api/masterfile/damage_report/approve_with_aid.php', [
        'report_id' => $s->reportId, 'category' => 'Seeds', 'quantity' => 10, 'unit' => 'bags', 'item_id' => $itemId,
    ]);
    assertSame('success', $res['status'], 'status');
    $report = reportRow($s->reportId);
    assertSame('Approved', $report['Status'], 'now Approved');
    assertSame($adminId, (int)$report['Approved_By'], 'records who approved it');
    assertSame(90.0, stockOf($itemId), 'stock 100 -> 90');
    $aid = row('SELECT * FROM aid_distribution WHERE Damage_Report_ID = ?', 'i', $s->reportId);
    assertSame('Pending Distribution', $aid['Status'], 'aid waiting at the MAO office');
    $s->distId = (int)$aid['Distribution_ID'];
});

test('st110 MAO hands out the aid and marks it claimed', function () use ($mao, $s) {
    $res = $mao->post('api/mao/mark_as_claimed.php', ['dist_id' => $s->distId]);
    assertSame('success', $res['status'], 'status');
    assertSame('Claimed', row('SELECT Status FROM aid_distribution WHERE Distribution_ID = ?', 'i', $s->distId)['Status'], 'now Claimed');
});

test('st111 farmer is notified at every step', function () use ($farmer) {
    $res = $farmer->get('api/dashboard/farmer_notifications.php?action=fetch');
    $all = implode(' | ', array_column($res['data'], 'Message'));
    assertContains('marked as Validated', $all, 'validated');
    assertContains('Field inspection completed', $all, 'inspected');
    assertContains('has been approved', $all, 'approved');
    assertContains('successfully claimed', $all, 'claimed');
});

test('st112 audit log records each staff action', function () use ($techId, $adminId, $maoId) {
    $actions = fn($id) => array_column(q('SELECT Action FROM system_logs WHERE User_ID = ?', 'i', $id), 'Action');
    assertTrue(in_array('Submit Inspection', $actions($techId), true), 'inspection logged');
    assertTrue(in_array('Approve Report', $actions($adminId), true), 'approval logged');
    assertTrue(in_array('Mark as Claimed', $actions($maoId), true), 'claim logged');
});

TestRun::run('Tests\Feature\SystemTest');
