<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';

// Load environment variables
if (!function_exists('loadEnv')) {
    function loadEnv($path) {
        if (!file_exists($path)) {
            return;
        }
        
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) {
                continue;
            }
            
            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);
            
            if (!array_key_exists($name, $_ENV)) {
                $_ENV[$name] = $value;
            }
        }
    }
}

// Load .env file
loadEnv(__DIR__ . '/../.env');

if (!function_exists('getMailer')) {
    function getMailer(): PHPMailer
    {
        $mail = new PHPMailer(true);

        try {
            // Server settings for Gmail
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            
            // Use direct credentials instead of env variables for testing
            $mail->Username = 'dgzstoninocapstone@gmail.com';
            $mail->Password = 'rvub inew rtnw yvpb'; // Your app password
            
            // Required for Gmail
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = 587;

            // Enable debugging temporarily to diagnose issues
            $mail->SMTPDebug = SMTP::DEBUG_SERVER; // Enable verbose debug output
            $mail->Debugoutput = function($str, $level) {
                error_log("SMTP ($level): $str");
            };

            // Proper SSL configuration for Gmail
            $mail->SMTPOptions = array(
                'ssl' => array(
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'allow_self_signed' => false
                )
            );

            // Set consistent from address
            $mail->setFrom('dgzstoninocapstone@gmail.com', 'DGZ Motorshop');
            
            // Set some default settings
            $mail->CharSet = 'UTF-8';
            $mail->Encoding = 'base64';
            $mail->isHTML(true);

            // Add reply-to
            $mail->addReplyTo('dgzstoninocapstone@gmail.com', 'DGZ Motorshop');

        } catch (Exception $e) {
            // Handle exception
            error_log("Mailer Error: {$mail->ErrorInfo}");
        }

        return $mail;
    }
}
