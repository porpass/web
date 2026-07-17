<?php
/**
 * mailer.php — PHPMailer wrapper for PORPASS transactional email.
 *
 * Provides a configured PHPMailer instance and helper functions for
 * sending transactional emails. SMTP credentials are loaded from the
 * project .env file.
 *
 * Required .env variables:
 *   MAIL_HOST      — SMTP server hostname
 *   MAIL_PORT      — SMTP port (typically 587 for TLS)
 *   MAIL_USERNAME  — SMTP username
 *   MAIL_PASSWORD  — SMTP password
 *   MAIL_FROM      — From address (e.g. noreply@porpass.psi.edu)
 *   MAIL_FROM_NAME — From name (e.g. PORPASS)
 *   APP_URL        — Base URL of the application (e.g. https://porpass.psi.edu)
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Create and return a configured PHPMailer instance.
 *
 * Credentials and connection settings are read from environment variables
 * loaded via the project .env file.
 *
 * @return PHPMailer A configured PHPMailer instance ready to send.
 * @throws Exception If PHPMailer cannot be configured.
 */
function get_mailer(): PHPMailer {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = $_ENV['MAIL_HOST']      ?? 'smtp.example.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = $_ENV['MAIL_USERNAME']  ?? '';
    $mail->Password   = $_ENV['MAIL_PASSWORD']  ?? '';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = (int)($_ENV['MAIL_PORT'] ?? 587);
    $mail->setFrom(
        $_ENV['MAIL_FROM']      ?? 'noreply@example.com',
        $_ENV['MAIL_FROM_NAME'] ?? 'PORPASS'
    );
    $mail->isHTML(true);
    return $mail;
}

/**
 * Build a reusable HTML email wrapper with consistent PORPASS branding.
 *
 * @param string $body_html The inner HTML content of the email body.
 *
 * @return string Complete HTML email string.
 */
function email_template(string $body_html): string {
    return '
    <div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;">
        <div style="background:#212529;padding:16px 24px;">
            <h1 style="color:#fff;margin:0;font-size:20px;">PORPASS</h1>
            <p style="color:#adb5bd;margin:4px 0 0;font-size:12px;">
                Planetary Orbital Radar Processing and Simulation System
            </p>
        </div>
        <div style="padding:24px;border:1px solid #dee2e6;border-top:none;">
            ' . $body_html . '
        </div>
        <div style="padding:12px 24px;background:#f8f9fa;border:1px solid #dee2e6;
                    border-top:none;font-size:12px;color:#6c757d;text-align:center;">
            The Planetary Science Institute &mdash;
            1700 East Fort Lowell, Suite 106, Tucson, AZ 85719
        </div>
    </div>';
}

/**
 * Send an email verification message to a newly registered user.
 *
 * The raw token is included in the verification URL. Only a sha256 hash
 * of the token is stored in the database.
 *
 * @param string $to_email  Recipient email address.
 * @param string $to_name   Recipient display name.
 * @param string $token     The raw (unhashed) verification token.
 *
 * @return bool True if the email was sent successfully, false otherwise.
 */
function send_email_verification(string $to_email, string $to_name, string $token): bool {
    $base_url  = rtrim($_ENV['APP_URL'] ?? 'http://porpass.local', '/');
    $verify_url = $base_url . '/auth/verify.php?token=' . urlencode($token);

    $body = '
        <p>Hello ' . htmlspecialchars($to_name) . ',</p>
        <p>Thank you for registering with PORPASS. Please verify your email
           address by clicking the button below.</p>
        <p style="margin:24px 0;">
            <a href="' . $verify_url . '"
               style="background:#0d6efd;color:#fff;padding:10px 20px;
                      text-decoration:none;border-radius:4px;">
                Verify Email Address
            </a>
        </p>
        <p>This link will expire in <strong>24 hours</strong>.</p>
        <p>Once your email is verified, your account will be reviewed and
           approved by a PORPASS administrator before you can sign in.</p>
        <p>If you did not register for PORPASS, you can safely ignore this email.</p>
        <p>— The PORPASS Team</p>';

    try {
        $mail = get_mailer();
        $mail->addAddress($to_email, $to_name);
        $mail->Subject = 'PORPASS — Please Verify Your Email Address';
        $mail->Body    = email_template($body);
        $mail->AltBody = "Verify your PORPASS email address:\n\n"
                       . $verify_url
                       . "\n\nThis link expires in 24 hours.";
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('PHPMailer error (verify): ' . $e->getMessage());
        return false;
    }
}

/**
 * Send a password reset email containing a tokenised reset link.
 *
 * The raw token is included in the reset URL. Only a sha256 hash of the
 * token is stored in the database.
 *
 * @param string $to_email  Recipient email address.
 * @param string $to_name   Recipient display name.
 * @param string $token     The raw (unhashed) reset token.
 *
 * @return bool True if the email was sent successfully, false otherwise.
 */
function send_password_reset(string $to_email, string $to_name, string $token): bool {
    $base_url  = rtrim($_ENV['APP_URL'] ?? 'http://porpass.local', '/');
    $reset_url = $base_url . '/auth/reset.php?token=' . urlencode($token);

    $body = '
        <p>Hello ' . htmlspecialchars($to_name) . ',</p>
        <p>We received a request to reset your PORPASS password. Click the
           button below to choose a new password. This link will expire in
           <strong>15 minutes</strong>.</p>
        <p style="margin:24px 0;">
            <a href="' . $reset_url . '"
               style="background:#0d6efd;color:#fff;padding:10px 20px;
                      text-decoration:none;border-radius:4px;">
                Reset Password
            </a>
        </p>
        <p>If you did not request a password reset, you can safely ignore
           this email. Your password will not be changed.</p>
        <p>— The PORPASS Team</p>';

    try {
        $mail = get_mailer();
        $mail->addAddress($to_email, $to_name);
        $mail->Subject = 'PORPASS — Password Reset Request';
        $mail->Body    = email_template($body);
        $mail->AltBody = "Reset your PORPASS password:\n\n"
                       . $reset_url
                       . "\n\nThis link expires in 15 minutes. "
                       . "If you did not request this, ignore this email.";
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('PHPMailer error (reset): ' . $e->getMessage());
        return false;
    }
}

/**
 * Send an email change verification message to a user's new email address.
 *
 * Called when a user submits an email change request via Account Settings.
 * The change is not applied until the user verifies the new address.
 *
 * @param string $to_email  The new email address to verify.
 * @param string $to_name   Recipient display name.
 * @param string $token     The raw (unhashed) verification token.
 *
 * @return bool True if the email was sent successfully, false otherwise.
 */
function send_email_change_verification(string $to_email, string $to_name, string $token): bool {
    $base_url   = rtrim($_ENV['APP_URL'] ?? 'http://porpass.local', '/');
    $verify_url = $base_url . '/auth/verify_email_change.php?token=' . urlencode($token);

    $body = '
        <p>Hello ' . htmlspecialchars($to_name) . ',</p>
        <p>A request was made to change the email address on your PORPASS account
           to this address. Click the button below to confirm this change.</p>
        <p style="margin:24px 0;">
            <a href="' . $verify_url . '"
               style="background:#0d6efd;color:#fff;padding:10px 20px;
                      text-decoration:none;border-radius:4px;">
                Confirm Email Change
            </a>
        </p>
        <p>This link will expire in <strong>24 hours</strong>.</p>
        <p>If you did not request this change, please contact the PORPASS
           team immediately.</p>
        <p>— The PORPASS Team</p>';

    try {
        $mail = get_mailer();
        $mail->addAddress($to_email, $to_name);
        $mail->Subject = 'PORPASS — Confirm Your New Email Address';
        $mail->Body    = email_template($body);
        $mail->AltBody = "Confirm your new PORPASS email address:\n\n"
                       . $verify_url
                       . "\n\nThis link expires in 24 hours.";
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('PHPMailer error (email change): ' . $e->getMessage());
        return false;
    }
}