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
            // Server settings from environment variables
            $mail->isSMTP();
            $mail->Host       = $_ENV['SMTP_HOST'] ?? 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = $_ENV['SMTP_USERNAME'] ?? 'dgzstoninocapstone@gmail.com';
            $mail->Password   = $_ENV['SMTP_PASSWORD'] ?? 'rvub inew rtnw yvpb';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = $_ENV['SMTP_PORT'] ?? 587;

            // Enable debugging (set to 0 to disable)
            $mail->SMTPDebug = 0; // Disabled for production
            $mail->Debugoutput = function($str, $level) {
                error_log("SMTP ($level): $str");
            };

            // Additional settings for better Gmail delivery
            $mail->SMTPOptions = array(
                'ssl' => array(
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                )
            );

            // Recipients
            $fromEmail = $_ENV['SMTP_FROM_EMAIL'] ?? $_ENV['SMTP_USERNAME'] ?? '';
            $fromName = $_ENV['SMTP_FROM_NAME'] ?? 'DGZ Motorshop';
            $mail->setFrom($fromEmail, $fromName);

            // Add reply-to
            $mail->addReplyTo($fromEmail, $fromName);

        } catch (Exception $e) {
            // Handle exception
            error_log("Mailer Error: {$mail->ErrorInfo}");
        }

        return $mail;
    }
}
