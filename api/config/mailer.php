<?php
// File Location: api/config/mailer.php
// Sends the email-verification link via Brevo's HTTP API - NOT SMTP.
// Railway blocks outbound SMTP connections (confirmed via a real "Connection timed
// out" error from PHPMailer talking to Gmail's SMTP server), so raw SMTP genuinely
// cannot work from this host. Brevo's REST API uses plain HTTPS instead, which is
// never blocked, so this works reliably where SMTP did not.
// PHPMailer/Composer are no longer needed for this - removed to keep things simple.

define('BREVO_API_KEY', 'REMOVED_BREVO_API_KEY');
define('BREVO_SENDER_EMAIL', 'person8@example.com');
define('BREVO_SENDER_NAME', 'CropGuard');

function sendVerificationEmail($toEmail, $toName, $token) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $verifyLink = "$protocol://$host/api/auth/verify_email.php?token=" . urlencode($token);

    $htmlBody = "
        <div style=\"font-family: Arial, sans-serif; max-width: 480px; margin: 0 auto;\">
            <h2 style=\"color: #11542e;\">Welcome to CropGuard, $toName!</h2>
            <p>Please confirm this is your email address to activate your account.</p>
            <p style=\"margin: 24px 0;\">
                <a href=\"$verifyLink\" style=\"background: #11542e; color: white; padding: 12px 24px; border-radius: 8px; text-decoration: none; font-weight: bold;\">Verify My Email</a>
            </p>
            <p style=\"font-size: 12px; color: #888;\">This link expires in 24 hours. If you didn't create a CropGuard account, you can safely ignore this email.</p>
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
    curl_close($ch);

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