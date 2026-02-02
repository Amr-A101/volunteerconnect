<?php
// app/core/mailer.php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// FIX: Added missing slash
require_once __DIR__ . "/../../PHPMailer/src/Exception.php";
require_once __DIR__ . "/../../PHPMailer/src/PHPMailer.php";
require_once __DIR__ . "/../../PHPMailer/src/SMTP.php";

function sendVolunteerVerificationEmail($toEmail, $verifyToken)
{
    $mail = new PHPMailer(true);

    try {
        // Enable verbose debug output (remove in production)
        $mail->SMTPDebug = 2; // 0 = off, 1 = client, 2 = client and server
        $mail->Debugoutput = function($str, $level) {
            error_log("PHPMailer: $str");
        };

        // ===== SMTP CONFIG =====
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'volunteerconnect.oct25@gmail.com';
        $mail->Password   = 'pzqeepcclqljxhqa'; // App password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        // ===== EMAIL CONTENT =====
        $mail->setFrom('volunteerconnect.oct25@gmail.com', 'Volunteer Connect');
        $mail->addAddress($toEmail);
        $mail->addReplyTo('volunteerconnect.oct25@gmail.com', 'Volunteer Connect');

        // Build verification link
        $verifyLink = "http://localhost/volcon/app/verify_vol.php?token=" . urlencode($verifyToken);

        $mail->isHTML(true);
        $mail->Subject = 'Verify Your Volunteer Account';
        $mail->Body = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
                <h2 style='color: #4CAF50;'>Welcome to Volunteer Connect!</h2>
                <p>Thank you for registering as a volunteer.</p>
                <p>Please verify your email address by clicking the button below:</p>
                <p style='text-align: center; margin: 30px 0;'>
                    <a href='{$verifyLink}' 
                       style='background-color: #4CAF50; color: white; padding: 12px 30px; 
                              text-decoration: none; border-radius: 5px; display: inline-block;'>
                        Verify My Account
                    </a>
                </p>
                <p style='color: #666; font-size: 12px;'>
                    If the button doesn't work, copy and paste this link into your browser:<br>
                    <a href='{$verifyLink}'>{$verifyLink}</a>
                </p>
                <p style='color: #666; font-size: 12px;'>
                    This link will expire once used.
                </p>
            </div>
        ";

        $mail->AltBody = "Welcome to Volunteer Connect!\n\nVerify your account by visiting: {$verifyLink}\n\nThis link will expire once used.";

        $mail->send();
        error_log("Verification email sent successfully to: {$toEmail}");
        return true;

    } catch (Exception $e) {
        error_log("Mailer Error: " . $mail->ErrorInfo);
        error_log("Exception: " . $e->getMessage());
        return false;
    }
}
?>