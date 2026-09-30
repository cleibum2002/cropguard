<?php
// File Location: api/config/phone.php
// Philippine mobile number cleanup, used wherever Contact_Number is written
// (registration and the profile page).
//
// This file used to be sms.php and also sent verification codes by SMS through
// Semaphore. That was removed: Philippine carriers require a registered Sender
// Name, and getting one approved needs signed endorsement letters from the
// school and from DA RFO X, then 3 to 15 business days of carrier review - a
// timeline the thesis could not wait on. Verification is email-only again (see
// mailer.php). The phone number is still collected and kept clean, because it
// is the farmer's contact information - it just no longer verifies anything.

/**
 * Turns whatever was typed into the 09XXXXXXXXX form.
 * Returns null when it cannot be read as a Philippine mobile number.
 *
 * Contact_Number is a free-text column, so real entries look like
 * "0917 123 4567", "+639171234567", "639171234567" and "9171234567". All of
 * those are the same number; storing one shape keeps the column consistent.
 */
function normalizePhMobile($raw) {
    $digits = preg_replace('/\D+/', '', (string)$raw);
    if ($digits === '') {
        return null;
    }

    // +63 9XX XXX XXXX / 63 9XX XXX XXXX
    if (strlen($digits) === 12 && strpos($digits, '63') === 0) {
        $digits = '0' . substr($digits, 2);
    }

    // Typed without the leading zero.
    if (strlen($digits) === 10 && $digits[0] === '9') {
        $digits = '0' . $digits;
    }

    // Every PH mobile number is 09 followed by nine more digits.
    if (strlen($digits) === 11 && strpos($digits, '09') === 0) {
        return $digits;
    }

    return null;
}
?>
