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
// The sender must be an address verified in Brevo. Set BREVO_SENDER_EMAIL on
// the server; the address below is only the fallback for this deployment.
define('BREVO_SENDER_EMAIL', getenv('BREVO_SENDER_EMAIL') ?: 'person1@example.com');
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

// Generic Brevo send used by the registration approval / rejection emails.
// Best-effort like sendVerificationEmail(): returns false instead of throwing.
function sendBrevoEmail($toEmail, $toName, $subject, $htmlBody) {
    if (empty(BREVO_API_KEY)) {
        error_log('[' . date('Y-m-d H:i:s') . '] Brevo send skipped: BREVO_API_KEY not configured.');
        return false;
    }
    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'sender'      => ['name' => BREVO_SENDER_NAME, 'email' => BREVO_SENDER_EMAIL],
        'to'          => [['email' => $toEmail, 'name' => $toName]],
        'subject'     => $subject,
        'htmlContent' => $htmlBody
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json', 'Content-Type: application/json', 'api-key: ' . BREVO_API_KEY]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    if ($curlError || $httpCode < 200 || $httpCode >= 300) {
        error_log('[' . date('Y-m-d H:i:s') . '] Brevo send failed: ' . ($curlError ?: "HTTP $httpCode $response"));
        return false;
    }
    return true;
}

function sendRegistrationApprovedEmail($toEmail, $toName) {
    $name = htmlspecialchars($toName, ENT_QUOTES, 'UTF-8');
    return sendBrevoEmail($toEmail, $toName, 'Your CropGuard registration was approved', "
        <div style=\"font-family: Arial, sans-serif; max-width: 480px; margin: 0 auto;\">
            <h2 style=\"color: #11542e;\">Welcome to CropGuard, $name!</h2>
            <p>Your farmer registration has been reviewed and <strong>approved</strong> by the Department of Agriculture.</p>
            <p>You can now log in with your email address and the password you created.</p>
        </div>");
}

// Code for changing a password from My Profile (api/profile/send_password_code.php).
function sendPasswordCodeEmail($toEmail, $toName, $code) {
    $name = htmlspecialchars($toName, ENT_QUOTES, 'UTF-8');
    $code = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
    return sendBrevoEmail($toEmail, $toName, 'Your CropGuard password change code', "
        <div style=\"font-family: Arial, sans-serif; max-width: 480px; margin: 0 auto;\">
            <h2 style=\"color: #11542e;\">Hello, $name</h2>
            <p>Someone asked to change the password of your CropGuard account. To confirm it was you, enter this code:</p>
            <p style=\"font-size: 28px; font-weight: bold; letter-spacing: 6px; color: #11542e; background:#f6f8f6; padding:12px 14px; border-radius:8px; text-align:center;\">$code</p>
            <p style=\"font-size: 12px; color: #888;\">This code expires in 5 minutes. If you did not ask for this, ignore this email and your password stays the same.</p>
        </div>");
}

function sendRegistrationRejectedEmail($toEmail, $toName, $reason) {
    $name   = htmlspecialchars($toName, ENT_QUOTES, 'UTF-8');
    $reason = htmlspecialchars($reason, ENT_QUOTES, 'UTF-8');
    return sendBrevoEmail($toEmail, $toName, 'Your CropGuard registration was not approved', "
        <div style=\"font-family: Arial, sans-serif; max-width: 480px; margin: 0 auto;\">
            <h2 style=\"color: #11542e;\">Hello, $name</h2>
            <p>Your farmer registration was reviewed by the Department of Agriculture and was <strong>not approved</strong>.</p>
            <p style=\"background:#f6f8f6; padding:12px 14px; border-radius:8px;\"><strong>Reason:</strong> $reason</p>
            <p>Your details were not kept. You may register again with corrected information, or visit the Municipal Agriculture Office for help.</p>
        </div>");
}
?>