<?php
// File Location: api/config/mailer.php
// Sends the email-verification link via Brevo's HTTP API - NOT SMTP.
// Railway blocks outbound SMTP connections (confirmed via a real "Connection timed
// out" error from PHPMailer talking to Gmail's SMTP server), so raw SMTP genuinely
// cannot work from this host. Brevo's REST API uses plain HTTPS instead, which is
// never blocked, so this works reliably where SMTP did not.
// PHPMailer/Composer are no longer needed for this - removed to keep things simple.

// getenv() wins whenever BREVO_API_KEY is set (Railway); local XAMPP needs its
// own env var set to send real emails - see api/config/mailer.php's history
// for why a real key must never be hardcoded here as a fallback again.
define('BREVO_API_KEY', getenv('BREVO_API_KEY') ?: '');
define('BREVO_SENDER_EMAIL', 'person1@example.com');
define('BREVO_SENDER_NAME', 'CropGuard');

function sendVerificationEmail($toEmail, $toName, $code) {
    if (empty(BREVO_API_KEY)) {
        error_log('[' . date('Y-m-d H:i:s') . '] Brevo send skipped: BREVO_API_KEY not configured.');
        return false;
    }

    $htmlBody = "
        <div style=\"font-family: Arial, sans-serif; max-width: 480px; margin: 0 auto;\">
            <h2 style=\"color: #11542e;\">Welcome to CropGuard, $toName!</h2>
            <p>Enter this code on the registration page to verify your account:</p>
            <p style=\"margin: 24px 0; font-size: 32px; font-weight: bold; letter-spacing: 8px; color: #11542e; text-align: center;\">$code</p>
            <p style=\"font-size: 12px; color: #888;\">This code expires in 5 minutes. If you didn't create a CropGuard account, you can safely ignore this email.</p>
        </div>
    ";

    $payload = json_encode([
        'sender'      => ['name' => BREVO_SENDER_NAME, 'email' => BREVO_SENDER_EMAIL],
        'to'          => [['email' => $toEmail, 'name' => $toName]],
        'subject'     => 'Verify your CropGuard account',
        'htmlContent' => $htmlBody
    ]);

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/json',
        'Content-Type: application/json',
        'api-key: ' . BREVO_API_KEY
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    // curl_close() removed - deprecated as of PHP 8.5 (confirmed via the actual error
    // Railway's server produced), and unnecessary anyway since PHP 8+ auto-releases
    // curl handles once they go out of scope.

    if ($curlError) {
        error_log('[' . date('Y-m-d H:i:s') . '] Brevo send failed (curl error): ' . $curlError);
        return false;
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        return true;
    }

    error_log('[' . date('Y-m-d H:i:s') . '] Brevo send failed (HTTP ' . $httpCode . '): ' . $response);
    return false;
}
?>