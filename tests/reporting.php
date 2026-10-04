<?php
// tests/reporting.php
// Reporting testing: the numbers and lists that CropGuard's reports are built
// from - the estimated-loss formula, the damage report list used by System
// Reports and Analytics (and their Excel export), the aid distribution ledger,
// and the damage map.
//
// Run:  D:\Xampp\php\php.exe tests\reporting.php

require __DIR__ . '/support.php';

$adminId  = makeUser('Admin', 'rp.admin');
$maoId    = makeUser('MAO', 'rp.mao');
$techId   = makeUser('Technician', 'rp.tech');
$farmerId = makeUser('Farmer', 'rp.farmer');
$typeId   = makeCropType('Report Rice', 40000);
$itemId   = makeItem('Report Seeds', 50);

[$loc, $crop]       = makeFarm($farmerId, $typeId, 5);
[$mapLoc, $mapCrop] = makeFarm($farmerId, $typeId, 2, 8.75123, 124.78123);   // farm with an approved report
[$penLoc, $penCrop] = makeFarm($farmerId, $typeId, 2, 8.76456, 124.79456);   // farm with only a pending report

// A fully processed report: validated by MAO, inspected by the technician,
// then approved by Admin with aid through the real endpoint.
$approved = makeReport($farmerId, $loc, $crop, 3, 'Moderate', 'Inspected', ['technician' => $techId, 'visit_date' => date('Y-m-d')]);
q('UPDATE damage_report SET Validated_By = ? WHERE Damage_Report_ID = ?', 'ii', $maoId, $approved);
$scratch  = makeReport($farmerId, $loc, $crop, 1, 'Light', 'Pending');
$archived = makeReport($farmerId, $loc, $crop, 1, 'Light', 'Archived');
makeReport($farmerId, $mapLoc, $mapCrop, 2, 'Total Loss', 'Approved');
makeReport($farmerId, $mapLoc, $mapCrop, 1, 'Severe', 'Pending');       // must NOT add to the map
makeReport($farmerId, $penLoc, $penCrop, 1, 'Severe', 'Pending');

$admin  = new Client(); loginAs($admin, emailFor('rp.admin'));
$farmer = new Client(); loginAs($farmer, emailFor('rp.farmer'));
$admin->post('api/masterfile/damage_report/approve_with_aid.php', [
    'report_id' => $approved, 'category' => 'Seeds', 'quantity' => 5, 'unit' => 'bags', 'item_id' => $itemId,
]);

function estimate(Client $c, int $reportId): array
{
    return $c->get("api/dashboard/calculate_cost.php?report_id=$reportId");
}

function mapPoint(array $points, float $lat, float $lng): ?array
{
    foreach ($points as $p) {
        if (round($p[0], 4) === round($lat, 4) && round($p[1], 4) === round($lng, 4)) {
            return $p;
        }
    }
    return null;
}

test('rp101 estimated loss = area x cost x severity (3 ha x P40,000 x 50%)', function () use ($admin, $approved) {
    $res = estimate($admin, $approved);
    assertSame('success', $res['status'], 'status');
    assertSame('120,000.00', $res['total_production_value'], 'production value (3 x 40,000)');
    assertSame('50%', $res['severity_multiplier'], 'Moderate = 50%');
    assertSame('60,000.00', $res['estimated_loss'], 'estimated loss');
});

test('rp102 each severity level uses the right percentage', function () use ($admin, $scratch) {
    $expected = ['Minimal' => '4,000.00', 'Light' => '12,000.00', 'Moderate' => '20,000.00', 'Severe' => '32,000.00', 'Total Loss' => '40,000.00'];
    foreach ($expected as $severity => $loss) {
        q('UPDATE damage_report SET Damage_Severity = ? WHERE Damage_Report_ID = ?', 'si', $severity, $scratch);
        assertSame($loss, estimate($admin, $scratch)['estimated_loss'], "$severity on 1 ha");
    }
});

test("rp103 the estimate uses the report's affected area, not the whole farm", function () use ($admin, $scratch) {
    assertSame(1.0, (float)estimate($admin, $scratch)['area_hectares'], '1 ha affected on a 5 ha farm');
});

test('rp104 report list carries everything the printed report shows', function () use ($admin, $approved) {
    $rows = array_values(array_filter($admin->get('api/masterfile/damage_report/get_all.php')['data'], fn($r) => (int)$r['id'] === $approved));
    assertSame(1, count($rows), 'report listed');
    $r = $rows[0];
    assertSame('Test Rp Farmer', $r['farmer'], 'farmer');
    assertSame('Approved', $r['status'], 'status');
    assertSame(3.0, (float)$r['area'], 'affected area');
    assertSame('Test Rp Tech', $r['technician_name'], 'inspecting technician');
    assertSame('Test Rp Mao', $r['validated_by_name'], 'validated by');
    assertSame('Test Rp Admin', $r['approved_by_name'], 'approved by');
});

test('rp105 archived reports leave the main list but stay in the archive', function () use ($admin, $archived) {
    $main    = array_map('intval', array_column($admin->get('api/masterfile/damage_report/get_all.php')['data'], 'id'));
    $archive = array_map('intval', array_column($admin->get('api/masterfile/damage_report/get_all.php?view=archived')['data'], 'id'));
    assertFalse(in_array($archived, $main, true), 'not in the main list');
    assertTrue(in_array($archived, $archive, true), 'in the archive view');
});

test('rp106 aid ledger lists each distribution with farmer, amount and status', function () use ($admin, $approved) {
    $rows = array_values(array_filter($admin->get('api/mao/get_aid_ledger.php')['data'], fn($r) => (int)$r['report_id'] === $approved));
    assertSame(1, count($rows), 'distribution listed');
    assertSame('Test Rp Farmer', $rows[0]['farmer_name'], 'farmer');
    assertSame(5.0, (float)$rows[0]['quantity'], 'quantity');
    assertSame('bags', $rows[0]['unit'], 'unit');
    assertSame('Pending Distribution', $rows[0]['status'], 'status');
});

test('rp107 damage map shows only approved, inspected reports', function () use ($admin) {
    $points = $admin->get('api/dashboard/heatmap_data.php')['data'];
    assertTrue(mapPoint($points, 8.75123, 124.78123) !== null, 'farm with an approved report is on the map');
    assertNull(mapPoint($points, 8.76456, 124.79456), 'farm with only a pending report is not');
});

test('rp108 damage map intensity follows the confirmed severity', function () use ($admin) {
    $point = mapPoint($admin->get('api/dashboard/heatmap_data.php')['data'], 8.75123, 124.78123);
    assertSame(1.0, (float)$point[2], 'Total Loss = full intensity; the pending report adds nothing');
});

test('rp109 report data is only available to staff', function () use ($farmer) {
    foreach ([
        'api/masterfile/damage_report/get_all.php',
        'api/mao/get_aid_ledger.php',
        'api/dashboard/heatmap_data.php',
    ] as $endpoint) {
        assertSame('error', $farmer->get($endpoint)['status'], $endpoint);
    }
});

TestRun::run('Tests\Feature\ReportingTest');
