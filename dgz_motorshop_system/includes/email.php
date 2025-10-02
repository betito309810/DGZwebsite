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
    error_log("Starting email send process to: $to");
    error_log("Subject: $subject");
    
    try {
        $mail = getMailer();
        
        // Clear any previous recipients
        $mail->clearAddresses();
        $mail->clearAttachments();
        
        // Add recipient with validation
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Invalid email address: $to");
        }
        $mail->addAddress($to);

        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        
        // Add plain text version for better deliverability
        $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $body));

        // Attach PDF if content is provided
        if ($pdfContent !== null) {
            error_log("Attaching PDF: " . $pdfFilename . ", Size: " . strlen($pdfContent) . " bytes");
            try {
                $mail->addStringAttachment($pdfContent, $pdfFilename, 'base64', 'application/pdf');
                error_log("PDF attachment added successfully");
            } catch (Exception $e) {
                error_log("Failed to attach PDF: " . $e->getMessage());
                throw $e; // Re-throw to be caught by outer try-catch
            }
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
