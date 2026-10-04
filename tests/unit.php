<?php
// tests/unit.php
// Unit tests: each one checks a single helper function on its own - no database,
// no web server. The functions are loaded straight from the production files
// (see loadFunctionFrom() in bootstrap.php), so this tests the deployed code.
//
// Run:  D:\Xampp\php\php.exe tests\unit.php

require __DIR__ . '/bootstrap.php';

loadFunctionFrom('api/auth/register.php', 'validateEmailFormat');
loadFunctionFrom('api/auth/register.php', 'validatePasswordStrength');
loadFunctionFrom('api/auth/register.php', 'hashUserPassword');
loadFunctionFrom('api/auth/login.php', 'isPasswordWeak');
loadFunctionFrom('api/config/phone.php', 'normalizePhMobile');
loadFunctionFrom('api/config/db_connection.php', 'isTokenExpired');
loadFunctionFrom('api/config/db_connection.php', 'safeErrorMessage');
loadFunctionFrom('api/dashboard/submit_report.php', 'sanitizeHtmlInput');
loadFunctionFrom('api/masterfile/crop_type/create.php', 'validateProductionCost');
loadFunctionFrom('api/masterfile/crop_type/create.php', 'validateGrowthDuration');
loadFunctionFrom('api/masterfile/items/create.php', 'validateStock');

test('ut101 validate email format rejects typos', function () {
    assertNotNull(validateEmailFormat('juan.delacruz.gmail.com'), 'missing @');
    assertNotNull(validateEmailFormat('juan@gmail'), 'missing domain ending');
    assertNotNull(validateEmailFormat('juan@'), 'nothing after @');
    assertNull(validateEmailFormat('person5@example.com'), 'valid email');
});

test('ut102 validate password strength rejects weak passwords', function () {
    assertNotNull(validatePasswordStrength('Ab1!'), 'shorter than 8 characters');
    assertNotNull(validatePasswordStrength('password123'), 'no special character');
    assertNull(validatePasswordStrength('Palay@2026'), 'strong password');
});

test('ut103 hash user password returns a secure hash', function () {
    $hash = hashUserPassword('Palay@2026');
    assertTrue($hash !== 'Palay@2026', 'not stored as plain text');
    assertSame(60, strlen($hash), 'bcrypt hash length');
    assertTrue(strpos($hash, '$2y$') === 0, 'uses bcrypt');
    assertTrue(hashUserPassword('Palay@2026') !== $hash, 'salted - same password, different hash');
});

test('ut104 verify password matches only the correct password', function () {
    $hash = hashUserPassword('Palay@2026');
    assertTrue(password_verify('Palay@2026', $hash), 'correct password');
    assertFalse(password_verify('palay@2026', $hash), 'wrong capitalisation');
    assertFalse(password_verify('Mais@2026', $hash), 'different password');
});

test('ut105 weak password check flags weak passwords at login', function () {
    assertTrue(isPasswordWeak('12345'), 'too short');
    assertTrue(isPasswordWeak('farmer2026'), 'no special character');
    assertFalse(isPasswordWeak('Farmer@2026'), 'strong password');
});

test('ut106 normalize mobile number accepts common formats', function () {
    assertSame('09171234567', normalizePhMobile('09171234567'), 'plain');
    assertSame('09171234567', normalizePhMobile('0917 123 4567'), 'with spaces');
    assertSame('09171234567', normalizePhMobile('+63 917 123 4567'), 'with +63');
    assertSame('09171234567', normalizePhMobile('639171234567'), 'with 63');
    assertSame('09171234567', normalizePhMobile('9171234567'), 'without leading zero');
});

test('ut107 normalize mobile number rejects invalid numbers', function () {
    assertNull(normalizePhMobile(''), 'empty');
    assertNull(normalizePhMobile('12345'), 'too short');
    assertNull(normalizePhMobile('(088) 855-1234'), 'landline');
    assertNull(normalizePhMobile('abcdefghijk'), 'letters');
});

test('ut108 is token expired detects expired verification codes', function () {
    assertTrue(isTokenExpired(date('Y-m-d H:i:s', strtotime('-1 minute'))), 'expired a minute ago');
    assertFalse(isTokenExpired(date('Y-m-d H:i:s', strtotime('+5 minutes'))), 'still valid for 5 minutes');
    assertTrue(isTokenExpired(null), 'missing expiry counts as expired');
});

test('ut109 sanitize html input strips scripts and tags', function () {
    assertSame('alert(1)Flooded', sanitizeHtmlInput('<script>alert(1)</script>Flooded'), 'script tag');
    assertSame('', sanitizeHtmlInput('<img src=x onerror=alert(1)>'), 'img onerror');
    assertSame('Typhoon damage', sanitizeHtmlInput('  <b>Typhoon damage</b>  '), 'bold tag + spaces');
});

test('ut110 validate production cost rejects zero, negative and typos', function () {
    assertNotNull(validateProductionCost('0'), 'zero');
    assertNotNull(validateProductionCost('-500'), 'negative');
    assertNotNull(validateProductionCost('abc'), 'not a number');
    assertNotNull(validateProductionCost('2000000'), 'over 1,000,000 per hectare');
    assertNull(validateProductionCost('45000'), 'normal cost');
});

test('ut111 validate growth duration requires a positive whole number', function () {
    assertNull(validateGrowthDuration('115 Days'), '115 Days');
    assertNull(validateGrowthDuration('10 Months'), '10 Months');
    assertNull(validateGrowthDuration(''), 'blank is allowed');
    assertNotNull(validateGrowthDuration('-50 Days'), 'negative');
    assertNotNull(validateGrowthDuration('2.5 Months'), 'decimal');
    assertNotNull(validateGrowthDuration('abc'), 'no number');
});

test('ut112 validate stock rejects negative, decimal and runaway values', function () {
    assertNotNull(validateStock('-5'), 'negative');
    assertNotNull(validateStock('2.5'), 'decimal');
    assertNotNull(validateStock('600000'), 'over 500,000');
    assertNotNull(validateStock('ten'), 'not a number');
    assertNull(validateStock('70'), 'normal stock');
});

test('ut113 safe error message hides internal database details', function () {
    $shown = safeErrorMessage(new Exception("Table 'crop_damage_system.users' doesn't exist"));
    assertTrue(strpos($shown, 'crop_damage_system') === false, 'no database name');
    assertTrue(strpos($shown, 'users') === false, 'no table name');
    assertContains('server error', $shown, 'generic message');
});

TestRun::run('Tests\Unit\CropGuardUnitTest');
