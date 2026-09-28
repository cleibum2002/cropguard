<?php
// File Location: api/config/sms.php
// Sends the verification code by SMS through Semaphore's HTTP API.
//
// Why SMS at all: user testing with real farmers in Balingasag found that email
// verification is a hard barrier - most are not familiar with email and could
// not retrieve a code from it. A phone number is the one contact method every
// farmer already has and already understands.
//
// Like Brevo, this is plain HTTPS rather than SMTP, so Railway's outbound SMTP
// block is not a problem here either.

// Never hardcode the key. Railway: Service -> Variables -> SEMAPHORE_API_KEY.
// Local XAMPP: set the same env var, or drop it in api/config/local_secrets.php
// (gitignored) which is included below when present.
if (file_exists(__DIR__ . '/local_secrets.php')) {
    require_once __DIR__ . '/local_secrets.php';
}

define('SEMAPHORE_API_KEY', getenv('SEMAPHORE_API_KEY') ?: (defined('LOCAL_SEMAPHORE_API_KEY') ? LOCAL_SEMAPHORE_API_KEY : ''));

// Sent explicitly on every request rather than left blank. This account has no
// default sender name, and Semaphore errors outright when none is supplied, so
// relying on its default is not an option. Change this one variable to the
// registered name once the carrier approves it - no code change needed.
define('SEMAPHORE_SENDER_NAME', getenv('SEMAPHORE_SENDER_NAME') ?: 'CROPGUARD');

// The OTP endpoint, not the general messages one: it routes through a path
// dedicated to one-time codes (better deliverability) and is not rate limited.
// It costs 2 credits per message instead of 1, which is irrelevant at the
// volume a verification flow generates.
define('SEMAPHORE_OTP_URL', 'https://api.semaphore.co/api/v4/otp');

/**
 * Turns whatever a farmer typed into the 09XXXXXXXXX form Semaphore expects.
 * Returns null when it cannot be read as a Philippine mobile number.
 *
 * Contact_Number is a free-text column and nothing has ever validated it, so
 * real entries look like "0917 123 4567", "+639171234567", "639171234567" and
 * "9171234567". All of those are the same number and all of them must work.
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

/**
 * Best-effort, exactly like sendVerificationEmail(): returns false and logs on
 * failure, never throws. Registration must never fail because a text could not
 * be delivered - the account is still created and the email still goes out.
 */
function sendVerificationSms($toNumber, $code) {
    if (empty(SEMAPHORE_API_KEY)) {
        error_log('[' . date('Y-m-d H:i:s') . '] Semaphore send skipped: SEMAPHORE_API_KEY not configured.');
        return false;
    }

    $number = normalizePhMobile($toNumber);
    if ($number === null) {
        error_log('[' . date('Y-m-d H:i:s') . '] Semaphore send skipped: unreadable mobile number.');
        return false;
    }

    // {otp} is substituted by Semaphore with the code we supply below. Passing
    // `code` explicitly is essential - letting Semaphore generate one would send
    // the farmer a number that does not match the one stored against their row.
    $payload = http_build_query([
        'apikey'     => SEMAPHORE_API_KEY,
        'number'     => $number,
        'message'    => 'CropGuard: Your verification code is {otp}. Do not share this code with anyone. It expires in 5 minutes.',
        'code'       => $code,
        'sendername' => SEMAPHORE_SENDER_NAME
    ]);

    $ch = curl_init(SEMAPHORE_OTP_URL);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    // No curl_close(): deprecated in PHP 8.5 and unnecessary since PHP 8 - same
    // reasoning as mailer.php, which hit that deprecation on Railway for real.

    if ($curlError) {
        error_log('[' . date('Y-m-d H:i:s') . '] Semaphore send failed (curl error): ' . $curlError);
        return false;
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        return true;
    }

    // The body carries the real reason - an unapproved sender name and an empty
    // credit balance look identical without it.
    error_log('[' . date('Y-m-d H:i:s') . '] Semaphore send failed (HTTP ' . $httpCode . '): ' . $response);
    return false;
}
?>
