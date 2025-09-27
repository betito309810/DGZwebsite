<?php
require_once __DIR__ . '/includes/email.php';

// Test email sending
$testEmail = 'christopher4betito@gmail.com'; // Replace with your email
$subject = 'DGZ Motorshop - Email Test';
$body = '
<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
    <h2 style="color: #333;">Email Test Successful!</h2>
    <p>This is a test email from DGZ Motorshop system.</p>
    <p>If you received this, the email configuration is working correctly.</p>
    <hr>
    <p style="font-size: 12px; color: #666;">
        This is an automated message from DGZ Motorshop.<br>
        Please do not reply to this email.
    </p>
</div>
';

echo "Testing email to: $testEmail\n";
$result = sendEmail($testEmail, $subject, $body);

if ($result) {
    echo "✅ Email sent successfully!\n";
    echo "Check your inbox (and spam folder) for the test email.\n";
} else {
    echo "❌ Email failed to send.\n";
    echo "Check the PHP error log for details.\n";
}
?>