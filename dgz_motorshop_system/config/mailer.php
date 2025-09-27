<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;


require_once __DIR__ . '/../vendor/autoload.php';

function getMailer(): PHPMailer
{
    $mail = new PHPMailer(true);

    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com'; // Your SMTP server
        $mail->SMTPAuth   = true;
        $mail->Username   = 'dgzwebstoninocapstone@gmail.com'; // Your SMTP username
        $mail->Password   = 'wzcl awai pkag jypf';           // Your SMTP password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        // Enable debugging (set to 0 to disable)
        $mail->SMTPDebug = 2;
        $mail->Debugoutput = function($str, $level) {
            error_log("SMTP ($level): $str");
        };

        // Recipients
        $mail->setFrom('dgzwebstoninocapstone@gmail.com', 'DGZ Motorshop');

    } catch (Exception $e) {
        // Handle exception
        error_log("Mailer Error: {$mail->ErrorInfo}");
    }

    return $mail;
}
