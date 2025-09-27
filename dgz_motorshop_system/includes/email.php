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
        // Recipients
        $mail->addAddress($to);

        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        
        // Add plain text version for better deliverability
        $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $body));
        
        // Additional headers for better deliverability
        $mail->addCustomHeader('X-Mailer', 'DGZ Motorshop System');
        $mail->addCustomHeader('X-Priority', '3');
        $mail->addCustomHeader('X-MSMail-Priority', 'Normal');
        $mail->addCustomHeader('Importance', 'Normal');
        
        // Set encoding
        $mail->CharSet = 'UTF-8';
        $mail->Encoding = 'base64';

        $result = $mail->send();
        error_log("Email send result: " . ($result ? 'SUCCESS' : 'FAILED'));
        
        // Clear addresses for next use
        $mail->clearAddresses();
        $mail->clearAttachments();
        
        return $result;
    } catch (Exception $e) {
        error_log("Message could not be sent. Mailer Error: {$mail->ErrorInfo}");
        error_log("Exception: " . $e->getMessage());
        return false;
    }
}
