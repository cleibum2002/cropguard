<?php
// File Location: api/config/mailer.php
// Sends the email-verification link using Gmail SMTP + PHPMailer.
// Credentials are hardcoded here rather than read from environment variables -
// same reasoning as api/config/db_connection.php's Railway branch: FrankenPHP
// doesn't reliably expose container env vars to PHP in this hosting setup.
// If this Gmail account's App Password is ever changed/revoked, update it here.

require_once __DIR__ . '/../../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// TODO: replace with the actual Gmail account and App Password (not your normal
// Gmail password - see the setup steps below for how to generate one).
define('MAILER_GMAIL_ADDRESS', 'person8@example.com');
define('MAILER_GMAIL_APP_PASSWORD', 'REMOVED_MAILER_GMAIL_APP_PASSWORD');

function sendVerificationEmail($toEmail, $toName, $token) {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = MAILER_GMAIL_ADDRESS;
        $mail->Password   = MAILER_GMAIL_APP_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom(MAILER_GMAIL_ADDRESS, 'CropGuard');
        $mail->addAddress($toEmail, $toName);

        // Build the verification link using the same domain the request came in on -
        // works correctly on both Railway's production domain and local XAMPP.
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $verifyLink = "$protocol://$host/api/auth/verify_email.php?token=" . urlencode($token);

        $mail->isHTML(true);
        $mail->Subject = 'Verify your CropGuard account';
        $mail->Body = "
            <div style=\"font-family: Arial, sans-serif; max-width: 480px; margin: 0 auto;\">
                <h2 style=\"color: #11542e;\">Welcome to CropGuard, $toName!</h2>
                <p>Please confirm this is your email address to activate your account.</p>
                <p style=\"margin: 24px 0;\">
                    <a href=\"$verifyLink\" style=\"background: #11542e; color: white; padding: 12px 24px; border-radius: 8px; text-decoration: none; font-weight: bold;\">Verify My Email</a>
                </p>
                <p style=\"font-size: 12px; color: #888;\">This link expires in 24 hours. If you didn't create a CropGuard account, you can safely ignore this email.</p>
            </div>
        ";
        $mail->AltBody = "Welcome to CropGuard, $toName! Verify your account here: $verifyLink (expires in 24 hours)";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('[' . date('Y-m-d H:i:s') . '] Verification email failed: ' . $mail->ErrorInfo);
        return false;
    }
}
?>