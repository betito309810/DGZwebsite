<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/email.php';

use Dompdf\Dompdf;
use Dompdf\Options;

// Test PDF generation
$options = new Options();
$options->set('defaultFont', 'Arial');
$dompdf = new Dompdf($options);

$html = '<h1>Test PDF</h1><p>This is a test PDF document.</p>';
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$pdfContent = $dompdf->output();
echo "PDF Generated. Size: " . strlen($pdfContent) . " bytes\n";

// Test email with attachment
$to = 'christopher4betito@gmail.com'; // Replace with your email
$subject = 'Test PDF Attachment';
$body = '<h1>Test Email</h1><p>This email should have a PDF attachment.</p>';

try {
    $result = sendEmail($to, $subject, $body, $pdfContent, 'test.pdf');
    echo "Email sent: " . ($result ? "SUCCESS" : "FAILED") . "\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}