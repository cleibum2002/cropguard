<?php
// tests/personnel.php
// Personnel assignment testing: assigning field technicians to damage reports -
// who can be assigned, who can do the assigning, reassignment, and making sure
// only the assigned technician sees and works on the report.
//
// Run:  D:\Xampp\php\php.exe tests\personnel.php

require __DIR__ . '/support.php';

makeUser('Admin', 'pa.admin');
$maoId      = makeUser('MAO', 'pa.mao');
$techA      = makeUser('Technician', 'pa.tech.a');
$techB      = makeUser('Technician', 'pa.tech.b');
$inactive   = makeUser('Technician', 'pa.tech.off', ['status' => 'Inactive']);
$farmerId   = makeUser('Farmer', 'pa.farmer');
$typeId     = makeCropType('Personnel Rice', 40000);
[$loc, $crop] = makeFarm($farmerId, $typeId, 5);

$report    = makeReport($farmerId, $loc, $crop, 2, 'Severe', 'Validated');
$maoReport = makeReport($farmerId, $loc, $crop, 1, 'Light', 'Validated');
$visit     = date('Y-m-d', strtotime('+2 days'));

$admin   = new Client(); loginAs($admin, emailFor('pa.admin'));
$mao     = new Client(); loginAs($mao, emailFor('pa.mao'));
$techAc  = new Client(); loginAs($techAc, emailFor('pa.tech.a'));
$techBc  = new Client(); loginAs($techBc, emailFor('pa.tech.b'));
$farmer  = new Client(); loginAs($farmer, emailFor('pa.farmer'));

function assign(Client $c, int $reportId, int $techId, string $date): array
{
    return $c->post('api/masterfile/damage_report/assign_technician.php', [
        'report_id' => $reportId, 'technician_id' => $techId, 'visit_date' => $date,
    ]);
}

function queueIds(Client $tech): array
{
    return array_map('intval', array_column($tech->get('api/technician/get_dashboard.php')['assignments'], 'id'));
}

test('pa101 technician list shows the field technicians', function () use ($admin, $techA, $techB) {
    $ids = array_map('intval', array_column($admin->get('api/masterfile/damage_report/get_technicians.php')['data'], 'id'));
    assertTrue(in_array($techA, $ids, true) && in_array($techB, $ids, true), 'both technicians listed');
});

test('pa102 admin can assign an active technician', function () use ($admin, $report, $techA, $visit) {
    assertSame('success', assign($admin, $report, $techA, $visit)['status'], 'status');
    assertSame($techA, (int)reportRow($report)['Technician_ID'], 'assigned');
});

test("pa103 the report appears in that technician's queue", function () use ($techAc, $report) {
    assertTrue(in_array($report, queueIds($techAc), true), 'in queue');
});

test("pa104 the report does not appear in another technician's queue", function () use ($techBc, $report) {
    assertFalse(in_array($report, queueIds($techBc), true), 'not in queue');
});

test('pa105 a farmer or MAO account cannot be assigned as technician', function () use ($admin, $report, $farmerId, $maoId, $visit, $techA) {
    foreach (['farmer' => $farmerId, 'MAO' => $maoId] as $who => $id) {
        assertContains('valid, active technician', assign($admin, $report, $id, $visit)['message'], $who);
    }
    assertSame($techA, (int)reportRow($report)['Technician_ID'], 'assignment unchanged');
});

test('pa106 an inactive technician cannot be assigned', function () use ($admin, $report, $inactive, $visit) {
    assertContains('valid, active technician', assign($admin, $report, $inactive, $visit)['message'], 'refused');
});

test('pa107 a user that does not exist cannot be assigned', function () use ($admin, $report, $visit) {
    assertContains('valid, active technician', assign($admin, $report, 999999999, $visit)['message'], 'refused');
});

test('pa108 a report can be reassigned before it is inspected', function () use ($admin, $report, $techB, $techAc, $techBc, $visit) {
    assertSame('success', assign($admin, $report, $techB, $visit)['status'], 'status');
    assertTrue(in_array($report, queueIds($techBc), true), 'moved to the new technician');
    assertFalse(in_array($report, queueIds($techAc), true), 'removed from the old one');
});

test('pa109 only the assigned technician can submit the inspection', function () use ($techAc, $techBc, $report) {
    $body = ['id' => $report, 'observations' => 'Lodged plants, about 70% damaged.', 'severity' => 'Severe'];
    assertSame('error', $techAc->postForm('api/technician/submit_validation.php', $body)['status'], 'previous technician refused');
    assertSame('success', $techBc->postForm('api/technician/submit_validation.php', $body)['status'], 'assigned technician accepted');
    assertSame('Inspected', reportRow($report)['Status'], 'now Inspected');
});

test('pa110 MAO can also assign technicians', function () use ($mao, $maoReport, $techA, $visit) {
    assertSame('success', assign($mao, $maoReport, $techA, $visit)['status'], 'status');
    assertSame($techA, (int)reportRow($maoReport)['Technician_ID'], 'assigned');
});

test('pa111 a farmer cannot assign technicians', function () use ($farmer, $maoReport, $techB, $techA, $visit) {
    assertSame('Unauthorized.', assign($farmer, $maoReport, $techB, $visit)['message'], 'refused');
    assertSame($techA, (int)reportRow($maoReport)['Technician_ID'], 'assignment unchanged');
});

TestRun::run('Tests\Feature\PersonnelAssignmentTest');
