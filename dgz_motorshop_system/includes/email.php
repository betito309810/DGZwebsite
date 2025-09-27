<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

require_once __DIR__ . '/../config/mailer.php';

function sendEmail(string $to, string $subject, string $body): bool
{
    error_log("Attempting to send email to: $to with subject: $subject");
    
    $mail = getMailer();

    try {
        //Recipients
        $mail->addAddress($to);

        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;

        $result = $mail->send();
        error_log("Email send result: " . ($result ? 'SUCCESS' : 'FAILED'));
        return $result;
    } catch (Exception $e) {
        error_log("Message could not be sent. Mailer Error: {$mail->ErrorInfo}");
        error_log("Exception: " . $e->getMessage());
        return false;
    }
}
