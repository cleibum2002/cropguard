<?php
// tests/transaction.php
// Transaction testing: relief aid moving out of inventory. Approving a report
// with aid must deduct exactly that amount from stock and record it in the aid
// ledger - all or nothing. Invalid amounts, missing items, over-drawing stock,
// double approvals and double claims must all be refused with stock untouched.
//
// Run:  D:\Xampp\php\php.exe tests\transaction.php

require __DIR__ . '/support.php';

makeUser('Admin', 'tx.admin');
makeUser('MAO', 'tx.mao');
$farmerId = makeUser('Farmer', 'tx.farmer');
$typeId   = makeCropType('Transaction Rice', 40000);
$itemId   = makeItem('Transaction Seeds', 100);
[$loc, $crop] = makeFarm($farmerId, $typeId, 10);

$report   = makeReport($farmerId, $loc, $crop, 2, 'Severe', 'Inspected');
$report2  = makeReport($farmerId, $loc, $crop, 1, 'Moderate', 'Inspected');
$report3  = makeReport($farmerId, $loc, $crop, 1, 'Light', 'Inspected');
$rejected = makeReport($farmerId, $loc, $crop, 1, 'Light', 'Rejected');

$admin  = new Client(); loginAs($admin, emailFor('tx.admin'));
$mao    = new Client(); loginAs($mao, emailFor('tx.mao'));
$farmer = new Client(); loginAs($farmer, emailFor('tx.farmer'));

function approve(Client $admin, int $reportId, $qty, ?int $itemId): array
{
    $body = ['report_id' => $reportId, 'category' => 'Seeds', 'quantity' => $qty, 'unit' => 'bags'];
    if ($itemId !== null) {
        $body['item_id'] = $itemId;
    }
    return $admin->post('api/masterfile/damage_report/approve_with_aid.php', $body);
}

function aidRows(int $reportId): array
{
    return q('SELECT * FROM aid_distribution WHERE Damage_Report_ID = ?', 'i', $reportId);
}

test('tx101 approving aid deducts the exact amount from stock (100 - 30 = 70)', function () use ($admin, $report, $itemId) {
    assertSame('success', approve($admin, $report, 30, $itemId)['status'], 'status');
    assertSame(70.0, stockOf($itemId), 'stock');
    assertSame('Approved', reportRow($report)['Status'], 'report approved');
});

test('tx102 the aid is recorded in the ledger as waiting for pickup', function () use ($admin, $report, $itemId) {
    $aid = aidRows($report);
    assertSame(1, count($aid), 'one ledger entry');
    assertSame(30.0, (float)$aid[0]['Quantity'], 'quantity');
    assertSame($itemId, (int)$aid[0]['Item_ID'], 'drawn from the chosen item');
    assertSame('Pending Distribution', $aid[0]['Status'], 'waiting at the MAO office');
    $ledger = array_filter($admin->get('api/mao/get_aid_ledger.php')['data'], fn($r) => (int)$r['report_id'] === $report);
    assertSame(1, count($ledger), 'shown in the aid ledger');
});

test('tx103 asking for more than the stock is refused and nothing changes', function () use ($admin, $report2, $itemId) {
    assertSame('error', approve($admin, $report2, 500, $itemId)['status'], 'refused');
    assertSame(70.0, stockOf($itemId), 'stock unchanged');
    assertSame('Inspected', reportRow($report2)['Status'], 'report unchanged');
    assertSame(0, count(aidRows($report2)), 'no ledger entry');
});

test('tx104 a decimal quantity is refused', function () use ($admin, $report2, $itemId) {
    assertContains('whole number', approve($admin, $report2, 2.5, $itemId)['message'], 'refused');
    assertSame(70.0, stockOf($itemId), 'stock unchanged');
});

test('tx105 a zero or negative quantity is refused', function () use ($admin, $report2, $itemId) {
    foreach ([0, -5] as $qty) {
        assertContains('whole number greater than zero', approve($admin, $report2, $qty, $itemId)['message'], "quantity $qty");
    }
    assertSame(70.0, stockOf($itemId), 'stock unchanged');
});

test('tx106 approving without choosing a relief item is refused', function () use ($admin, $report2, $itemId) {
    assertContains('select the relief item', approve($admin, $report2, 5, null)['message'], 'refused');
    assertSame('Inspected', reportRow($report2)['Status'], 'report unchanged');
});

test('tx107 the same report cannot be approved twice', function () use ($admin, $report, $itemId) {
    assertSame('error', approve($admin, $report, 30, $itemId)['status'], 'second approval refused');
    assertSame(70.0, stockOf($itemId), 'stock deducted only once');
    assertSame(1, count(aidRows($report)), 'still one ledger entry');
});

test('tx108 a rejected report cannot be approved', function () use ($admin, $rejected, $itemId) {
    assertSame('error', approve($admin, $rejected, 5, $itemId)['status'], 'refused');
    assertSame('Rejected', reportRow($rejected)['Status'], 'still rejected');
    assertSame(70.0, stockOf($itemId), 'stock unchanged');
});

test('tx109 approving exactly the remaining stock empties it', function () use ($admin, $report2, $itemId) {
    assertSame('success', approve($admin, $report2, 70, $itemId)['status'], 'status');
    assertSame(0.0, stockOf($itemId), 'stock now zero');
});

test('tx110 nothing more can be given from an empty item', function () use ($admin, $report3, $itemId) {
    assertSame('error', approve($admin, $report3, 1, $itemId)['status'], 'refused');
    assertSame(0.0, stockOf($itemId), 'stock never goes below zero');
});

test('tx111 aid can be marked as claimed only once', function () use ($mao, $report) {
    $distId = (int)aidRows($report)[0]['Distribution_ID'];
    assertSame('success', $mao->post('api/mao/mark_as_claimed.php', ['dist_id' => $distId])['status'], 'first claim');
    assertSame('Claimed', aidRows($report)[0]['Status'], 'now Claimed');
    assertSame('error', $mao->post('api/mao/mark_as_claimed.php', ['dist_id' => $distId])['status'], 'second claim refused');
});

test('tx112 the farmer is notified of the approval and the claim', function () use ($farmer) {
    $all = implode(' | ', array_column($farmer->get('api/dashboard/farmer_notifications.php?action=fetch')['data'], 'Message'));
    assertContains('You can now claim 30 bags of Seeds', $all, 'approval notice');
    assertContains('successfully claimed', $all, 'claim notice');
});

TestRun::run('Tests\Feature\TransactionTest');
