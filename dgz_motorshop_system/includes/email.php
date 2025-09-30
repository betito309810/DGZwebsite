<?php

require_once __DIR__ . '/../config/mailer.php';

/**
 * Send an email with optional PDF attachment
 * 
 * @param string $to Recipient email address
 * @param string $subject Email subject
 * @param string $body Email body content (HTML)
 * @param string|null $pdfContent Optional PDF content to attach
 * @param string $pdfFilename Optional filename for the PDF attachment (default: 'document.pdf')
 * @return bool True if email was sent successfully, false otherwise
 */
if (!function_exists('sendEmail')) {
function sendEmail(string $to, string $subject, string $body, ?string $pdfContent = null, string $pdfFilename = 'document.pdf'): bool
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

        // Attach PDF if content is provided
        if ($pdfContent !== null) {
            $mail->addStringAttachment($pdfContent, $pdfFilename, 'base64', 'application/pdf');
        }
        
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
}
