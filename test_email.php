<?php
// test_email.php

require_once 'classes/EmailSender.php';

$emailSender = new EmailSender();

echo "<h1>Email Test</h1>";
echo "<p>Is Configured: " . ($emailSender->isConfigured() ? '✅ Yes' : '❌ No') . "</p>";

$result = $emailSender->sendAccountEmail(
    'Yasier Yasin',
    'TEST001',
    'TestPassword123!',
    'yasierelyasin@gmail.com'
);

echo "<pre>";
print_r($result);
echo "</pre>";

if ($result['success']) {
    echo "<p style='color:green;font-weight:bold;'>✅ Email sent!</p>";
} else {
    echo "<p style='color:red;font-weight:bold;'>❌ Failed: " . $result['message'] . "</p>";
}
?>