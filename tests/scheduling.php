<?php
// tests/scheduling.php
// Scheduling testing: the field-visit date set when a technician is assigned
// to a damage report - which dates are accepted, which are refused, which
// reports can be scheduled at all, and how the technician's queue is ordered.
//
// Run:  D:\Xampp\php\php.exe tests\scheduling.php

require __DIR__ . '/support.php';

makeUser('Admin', 'sc.admin');
$techId   = makeUser('Technician', 'sc.tech');
$farmerId = makeUser('Farmer', 'sc.farmer');
$typeId   = makeCropType('Schedule Rice', 40000);
[$loc, $crop] = makeFarm($farmerId, $typeId, 5);

$report        = makeReport($farmerId, $loc, $crop, 1, 'Moderate', 'Validated');
$pendingReport = makeReport($farmerId, $loc, $crop, 1, 'Light', 'Pending');
$approvedDate  = date('Y-m-d', strtotime('+3 days'));
$approvedReport = makeReport($farmerId, $loc, $crop, 1, 'Severe', 'Approved', ['technician' => $techId, 'visit_date' => $approvedDate]);
$laterReport   = makeReport($farmerId, $loc, $crop, 1, 'Moderate', 'Validated');
$soonerReport  = makeReport($farmerId, $loc, $crop, 1, 'Moderate', 'Validated');

$admin = new Client(); loginAs($admin, emailFor('sc.admin'));
$tech  = new Client(); loginAs($tech, emailFor('sc.tech'));

function schedule(Client $c, int $reportId, int $techId, ?string $date): array
{
    $body = ['report_id' => $reportId, 'technician_id' => $techId];
    if ($date !== null) {
        $body['visit_date'] = $date;
    }
    return $c->post('api/masterfile/damage_report/assign_technician.php', $body);
}

function day(int $offset): string
{
    return date('Y-m-d', strtotime("$offset days"));
}

test('sc101 visit date in the past is rejected', function () use ($admin, $report, $techId) {
    $res = schedule($admin, $report, $techId, day(-1));
    assertSame('error', $res['status'], 'status');
    assertContains('today or a future date', $res['message'], 'message');
    assertNull(reportRow($report)['Visit_Date'], 'nothing scheduled');
});

test('sc102 visit date today is accepted', function () use ($admin, $report, $techId) {
    assertSame('success', schedule($admin, $report, $techId, day(0))['status'], 'status');
    assertSame(day(0), reportRow($report)['Visit_Date'], 'saved');
});

test('sc103 visit date in the future is accepted and saved', function () use ($admin, $report, $techId) {
    assertSame('success', schedule($admin, $report, $techId, day(7))['status'], 'status');
    assertSame(day(7), reportRow($report)['Visit_Date'], 'saved');
});

test('sc104 a date that is not a real date is rejected', function () use ($admin, $report, $techId) {
    foreach (['not-a-date', '2026-13-45'] as $bad) {
        assertSame('error', schedule($admin, $report, $techId, $bad)['status'], "date: $bad");
    }
    assertSame(day(7), reportRow($report)['Visit_Date'], 'schedule unchanged');
});

test('sc105 missing visit date is rejected', function () use ($admin, $report, $techId) {
    $res = schedule($admin, $report, $techId, null);
    assertSame('error', $res['status'], 'status');
    assertContains('visit_date', $res['message'], 'message');
});

test('sc106 a report must be validated before a visit is scheduled', function () use ($admin, $pendingReport, $techId) {
    $res = schedule($admin, $pendingReport, $techId, day(2));
    assertContains('must be validated first', $res['message'], 'refused');
    assertNull(reportRow($pendingReport)['Visit_Date'], 'nothing scheduled');
});

test('sc107 a finished report cannot be rescheduled', function () use ($admin, $approvedReport, $techId, $approvedDate) {
    assertSame('error', schedule($admin, $approvedReport, $techId, day(9))['status'], 'refused');
    assertSame($approvedDate, reportRow($approvedReport)['Visit_Date'], 'original date kept');
});

test('sc108 saving the same schedule twice is not an error', function () use ($admin, $report, $techId) {
    assertSame('success', schedule($admin, $report, $techId, day(7))['status'], 'same date again');
});

test('sc109 rescheduling moves the visit to the new date', function () use ($admin, $report, $techId) {
    assertSame('success', schedule($admin, $report, $techId, day(10))['status'], 'status');
    assertSame(day(10), reportRow($report)['Visit_Date'], 'moved');
});

test("sc110 technician's queue lists visits in date order", function () use ($admin, $tech, $techId, $laterReport, $soonerReport) {
    schedule($admin, $laterReport, $techId, day(5));
    schedule($admin, $soonerReport, $techId, day(2));
    $ids = array_map('intval', array_column($tech->get('api/technician/get_dashboard.php')['assignments'], 'id'));
    $sooner = array_search($soonerReport, $ids, true);
    $later  = array_search($laterReport, $ids, true);
    assertTrue($sooner !== false && $later !== false, 'both visits listed');
    assertTrue($sooner < $later, 'the sooner visit comes first');
});

TestRun::run('Tests\Feature\SchedulingTest');
