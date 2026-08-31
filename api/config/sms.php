<?php
// File Location: api/config/sms.php
// Sends the phone-verification code via Semaphore's dedicated OTP endpoint (more
// reliable delivery than their regular SMS endpoint, at 2 credits/message instead
// of 1). We pass our own generated code via the "code" param so it matches the
// same 6-digit format and verify_code.php logic already used for email.
define('SEMAPHORE_API_KEY', 'REMOVED_SEMAPHORE_API_KEY');

function sendVerificationSMS($phoneNumber, $code) {
    $message = "Your CropGuard verification code is {otp}. This code expires in 30 minutes.";

    $ch = curl_init('https://semaphore.co/api/v4/otp');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'apikey'  => SEMAPHORE_API_KEY,
        'number'  => $phoneNumber,
        'message' => $message,
        'code'    => $code
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        error_log('[' . date('Y-m-d H:i:s') . '] Semaphore SMS failed (curl error): ' . $curlError);
        return false;
    }
    if ($httpCode >= 200 && $httpCode < 300) {
        return true;
    }
    error_log('[' . date('Y-m-d H:i:s') . '] Semaphore SMS failed (HTTP ' . $httpCode . '): ' . $response);
    return false;
}
?>