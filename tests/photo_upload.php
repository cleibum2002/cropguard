<?php
// tests/photo_upload.php
// Proof-photo upload testing: photos attached by farmers (damage reports) and
// technicians (inspection evidence) - GPS tagging, accepted and refused file
// types, disguised scripts, safe file naming, and removing photos.
//
// Run:  D:\Xampp\php\php.exe tests\photo_upload.php

require __DIR__ . '/support.php';

$farmerId = makeUser('Farmer', 'pu.farmer');
$techId   = makeUser('Technician', 'pu.tech');
$typeId   = makeCropType('Photo Rice', 40000);
[$loc] = makeFarm($farmerId, $typeId, 5);

$farmer = new Client(); loginAs($farmer, emailFor('pu.farmer'));
$tech   = new Client(); loginAs($tech, emailFor('pu.tech'));

// Files a new report through the real form and returns [response, report id].
function fileReport(Client $farmer, int $farmerId, int $loc, int $typeId, array $files, array $extra = []): array
{
    $token = 'photo-test-' . uniqid();
    $res = $farmer->postForm('api/dashboard/submit_report.php', array_merge([
        'location_id' => $loc, 'type_id' => $typeId, 'area' => 1,
        'damage_type' => 'Pest Infestation', 'severity' => 'Moderate',
        'description' => 'Photo upload test.', 'client_token' => $token,
    ], $extra), $files);
    $row = row('SELECT Damage_Report_ID FROM damage_report WHERE Client_Token = ? AND Farmer_ID = ?', 'si', $token, $farmerId);
    return [$res, $row ? (int)$row['Damage_Report_ID'] : 0];
}

function storedFile(array $img): string
{
    return CG_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $img['Image_Path']);
}

$s = new stdClass();

test('pu101 report photo is saved with its GPS location', function () use ($farmer, $farmerId, $loc, $typeId, $s) {
    [$res, $id] = fileReport($farmer, $farmerId, $loc, $typeId,
        ['images[0]' => [testFile('jpg'), 'image/jpeg', 'damage.jpg']],
        ['geo_lat_0' => TEST_LAT, 'geo_lng_0' => TEST_LNG]);
    assertSame('success', $res['status'], 'status');
    $img = imagesOf($id)[0] ?? null;
    assertTrue($img !== null, 'photo recorded');
    assertSame(TEST_LAT, round((float)$img['Geo_Lat'], 4), 'latitude');
    assertSame(TEST_LNG, round((float)$img['Geo_Lng'], 4), 'longitude');
    assertTrue(is_file(storedFile($img)), 'file saved on the server');
    $s->reportId = $id;
});

test('pu102 JPG and PNG photos are both accepted', function () use ($farmer, $farmerId, $loc, $typeId) {
    [$res, $id] = fileReport($farmer, $farmerId, $loc, $typeId, [
        'images[0]' => [testFile('jpg'), 'image/jpeg', 'photo1.jpg'],
        'images[1]' => [testFile('png'), 'image/png', 'photo2.png'],
    ]);
    assertSame('success', $res['status'], 'status');
    assertSame(2, count(imagesOf($id)), 'both saved');
});

test('pu103 a script disguised as a .jpg photo is refused', function () use ($farmer, $farmerId, $loc, $typeId) {
    [$res, $id] = fileReport($farmer, $farmerId, $loc, $typeId,
        ['images[0]' => [testFile('fake'), 'image/jpeg', 'innocent.jpg']]);
    assertContains('could not be uploaded', $res['message'], 'farmer is told it was skipped');
    assertSame(0, count(imagesOf($id)), 'not saved');
});

test('pu104 a file that is not a photo is refused', function () use ($farmer, $farmerId, $loc, $typeId) {
    [$res, $id] = fileReport($farmer, $farmerId, $loc, $typeId,
        ['images[0]' => [testFile('txt'), 'text/plain', 'notes.txt']]);
    assertContains('could not be uploaded', $res['message'], 'farmer is told it was skipped');
    assertSame(0, count(imagesOf($id)), 'not saved');
});

test('pu105 a photo without GPS is still saved', function () use ($farmer, $farmerId, $loc, $typeId) {
    [$res, $id] = fileReport($farmer, $farmerId, $loc, $typeId,
        ['images[0]' => [testFile('png'), 'image/png', 'no_gps.png']]);
    assertSame('success', $res['status'], 'status');
    $img = imagesOf($id)[0];
    assertNull($img['Geo_Lat'], 'location left blank');
});

test('pu106 the server renames uploads instead of trusting the filename', function () use ($farmer, $farmerId, $loc, $typeId) {
    [, $id] = fileReport($farmer, $farmerId, $loc, $typeId,
        ['images[0]' => [testFile('jpg'), 'image/jpeg', '../../evil.php.jpg']]);
    $path = imagesOf($id)[0]['Image_Path'];
    assertTrue(strpos($path, "uploads/reports/rep_{$id}_") === 0, 'stored under a generated name');
    assertTrue(strpos($path, 'evil') === false && strpos($path, '..') === false, 'original name not used');
});

test('pu107 the saved photo is a real, readable image', function () use ($s) {
    $file = storedFile(imagesOf($s->reportId)[0]);
    assertTrue(getimagesize($file) !== false, 'opens as an image');
});

test('pu108 technician evidence photos are saved and tagged', function () use ($tech, $techId, $farmerId, $loc, $typeId) {
    $cropId = (int)row('SELECT Crop_ID FROM crop WHERE Location_ID = ? LIMIT 1', 'i', $loc)['Crop_ID'];
    $report = makeReport($farmerId, $loc, $cropId, 1, 'Moderate', 'Validated', ['technician' => $techId, 'visit_date' => date('Y-m-d')]);
    $res = $tech->postForm('api/technician/submit_validation.php', [
        'id' => $report, 'observations' => 'Leaf damage on most plants.', 'severity' => 'Moderate', 'damaged_area' => '0.5',
        'geo_lat_0' => TEST_LAT, 'geo_lng_0' => TEST_LNG,
    ], ['evidencePhotos[0]' => [testFile('jpg'), 'image/jpeg', 'evidence.jpg']]);
    assertSame('success', $res['status'], 'status');
    $img = imagesOf($report)[0];
    assertSame('Technician', $img['Uploaded_By_Role'], 'tagged as technician evidence');
    assertSame(TEST_LAT, round((float)$img['Geo_Lat'], 4), 'GPS saved');
    assertTrue(strpos($img['Image_Path'], 'uploads/evidence/') === 0, 'stored with the evidence photos');
});

test('pu109 farmer can remove a photo while the report is pending', function () use ($farmer, $loc, $typeId, $s) {
    $img  = imagesOf($s->reportId)[0];
    $file = storedFile($img);
    $res = $farmer->postForm('api/dashboard/submit_report.php', [
        'id' => $s->reportId, 'location_id' => $loc, 'type_id' => $typeId, 'area' => 1,
        'damage_type' => 'Pest Infestation', 'severity' => 'Moderate',
        'removed_image_ids[0]' => $img['Image_ID'],
    ]);
    assertSame('success', $res['status'], 'status');
    assertSame(0, count(imagesOf($s->reportId)), 'photo record removed');
    clearstatcache(); // PHP caches file checks; pu101 already looked at this file
    assertFalse(is_file($file), 'photo file deleted');
});

test('pu110 photos cannot be added after the report has been reviewed', function () use ($farmer, $loc, $typeId, $s) {
    q("UPDATE damage_report SET Status = 'Validated' WHERE Damage_Report_ID = ?", 'i', $s->reportId); // MAO has reviewed it
    $res = $farmer->postForm('api/dashboard/submit_report.php', [
        'id' => $s->reportId, 'location_id' => $loc, 'type_id' => $typeId, 'area' => 1,
        'damage_type' => 'Pest Infestation', 'severity' => 'Moderate',
    ], ['images[0]' => [testFile('jpg'), 'image/jpeg', 'late.jpg']]);
    assertContains('can no longer be edited', $res['message'], 'refused');
    assertSame(0, count(imagesOf($s->reportId)), 'no photo added');
});

TestRun::run('Tests\Feature\ProofPhotoUploadTest');
