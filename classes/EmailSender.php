<?php
// classes/EmailSender.php - COMPLETE WITH YOUR CREDENTIALS

require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class EmailSender
{
    private $smtpHost = 'smtp.gmail.com';
    private $smtpPort = 587;

    // YOUR ACTUAL CREDENTIALS
    private $smtpUsername = 'villanuevabea034@gmail.com';
    private $smtpPassword = 'upegpjngpqbptthk';
    private $fromEmail = 'villanuevabea034@gmail.com';
    private $fromName = 'Bestlink College Enrollment System';

    private $logFile;

    public function __construct()
    {
        $this->logFile = __DIR__ . '/../logs/email_log.txt';
        $logDir = dirname($this->logFile);

        if (!is_dir($logDir)) {
            @mkdir($logDir, 0777, true);
        }
    }

    /**
     * Send student account credentials
     */
    public function sendAccountEmail($studentName, $studentNumber, $password, $recipientEmail)
    {
        $recipientEmail = trim($recipientEmail);

        // Validate recipient
        if (empty($recipientEmail)) {
            return [
                'success' => false,
                'message' => 'Student email address is empty.'
            ];
        }

        if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            return [
                'success' => false,
                'message' => 'Invalid student email address: ' . $recipientEmail
            ];
        }

        // Validate SMTP configuration
        if (empty($this->smtpUsername) || empty($this->smtpPassword)) {
            return [
                'success' => false,
                'message' => 'SMTP credentials are not configured in EmailSender.php.'
            ];
        }

        $subject = 'Welcome to Bestlink College - Your Student Account';

        $htmlMessage = $this->buildHtmlMessage($studentName, $studentNumber, $password);
        $plainMessage = $this->buildPlainMessage($studentName, $studentNumber, $password);

        $result = $this->sendWithPHPMailer($recipientEmail, $subject, $htmlMessage, $plainMessage);

        if ($result['success']) {
            $this->logEmail($recipientEmail, $studentNumber, 'sent');
        } else {
            $this->logEmail($recipientEmail, $studentNumber, 'failed');
        }

        return $result;
    }

    /**
     * Send email through Gmail SMTP
     */
    private function sendWithPHPMailer($recipient, $subject, $htmlMessage, $plainMessage)
    {
        $mail = new PHPMailer(true);

        try {
            // SMTP SERVER
            $mail->isSMTP();
            $mail->Host = $this->smtpHost;
            $mail->SMTPAuth = true;
            $mail->Username = $this->smtpUsername;
            $mail->Password = $this->smtpPassword;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = $this->smtpPort;
            $mail->CharSet = 'UTF-8';
            $mail->Timeout = 30;

            // SSL options for XAMPP
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'allow_self_signed' => false
                ]
            ];

            // DEBUG MODE - Set to 0 for production, 2 for debugging
            $mail->SMTPDebug = 0;
            $mail->Debugoutput = function ($str, $level) {
                error_log('PHPMailer SMTP: ' . trim($str));
            };

            // FROM - Must match Gmail account
            $mail->setFrom($this->fromEmail, $this->fromName);

            // RECIPIENT
            $mail->addAddress($recipient);

            // REPLY TO
            $mail->addReplyTo($this->fromEmail, $this->fromName);

            // CONTENT
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $htmlMessage;
            $mail->AltBody = $plainMessage;

            // SEND
            $mail->send();

            return [
                'success' => true,
                'message' => 'Email sent successfully to ' . $recipient
            ];

        } catch (Exception $e) {
            $errorMessage = $mail->ErrorInfo ?: $e->getMessage();
            error_log('PHPMailer ERROR: ' . $errorMessage);

            return [
                'success' => false,
                'message' => 'SMTP Error: ' . $errorMessage
            ];
        }
    }

    /**
     * HTML email
     */
    private function buildHtmlMessage($studentName, $studentNumber, $password)
    {
        $studentName = htmlspecialchars($studentName, ENT_QUOTES, 'UTF-8');
        $studentNumber = htmlspecialchars($studentNumber, ENT_QUOTES, 'UTF-8');
        $password = htmlspecialchars($password, ENT_QUOTES, 'UTF-8');
        $portalUrl = 'https://www.bestlink.edu.ph/';

        return '
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Student Account</title>
<style>
body { margin: 0; padding: 0; background: #f4f6f8; font-family: Arial, sans-serif; color: #333; }
.container { max-width: 600px; margin: 30px auto; background: #ffffff; border-radius: 10px; overflow: hidden; border: 1px solid #ddd; }
.header { background: #1a3c6e; color: white; padding: 30px; text-align: center; }
.header h1 { margin: 0; font-size: 26px; }
.content { padding: 30px; }
.credentials { background: #f1f7ff; border-left: 5px solid #1a3c6e; padding: 20px; margin: 20px 0; }
.row { margin: 12px 0; }
.label { font-weight: bold; }
.value { display: inline-block; background: #eeeeee; padding: 6px 10px; border-radius: 4px; font-family: monospace; }
.warning { background: #fff3cd; border: 1px solid #ffc107; padding: 15px; border-radius: 5px; }
.button { display: inline-block; background: #1a3c6e; color: white !important; text-decoration: none; padding: 12px 25px; border-radius: 5px; margin-top: 15px; }
.footer { text-align: center; padding: 20px; font-size: 12px; color: #777; border-top: 1px solid #ddd; }
</style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>Bestlink College</h1>
        <p>Student Enrollment System</p>
    </div>
    <div class="content">
        <p>Dear <strong>' . $studentName . '</strong>,</p>
        <p>Congratulations! Your application has been approved and your student account has been created.</p>
        <div class="credentials">
            <h3>Your Login Credentials</h3>
            <div class="row">
                <span class="label">Student Number:</span>
                <span class="value">' . $studentNumber . '</span>
            </div>
            <div class="row">
                <span class="label">Password:</span>
                <span class="value">' . $password . '</span>
            </div>
        </div>
        <div class="warning">
            <strong>Security Notice</strong>
            <ul>
                <li>Do not share your password.</li>
                <li>Change your password after logging in.</li>
                <li>Keep your account credentials secure.</li>
            </ul>
        </div>
        <p style="text-align:center;">
            <a href="' . $portalUrl . '" class="button">Access Student Portal</a>
        </p>
        <p>If you have questions, please contact the Admissions Office.</p>
        <p>Best regards,<br><strong>Bestlink College Enrollment System</strong></p>
    </div>
    <div class="footer">
        This is an automated email. Please do not reply.
        <br><br>
        &copy; ' . date('Y') . ' Bestlink College
    </div>
</div>
</body>
</html>';
    }

    /**
     * Plain text version
     */
    private function buildPlainMessage($studentName, $studentNumber, $password)
    {
        return "
BESTLINK COLLEGE
Student Enrollment System

Dear {$studentName},

Congratulations!

Your application has been approved and your
student account has been created.

LOGIN CREDENTIALS
------------------------------
Student Number: {$studentNumber}
Password: {$password}
------------------------------

SECURITY NOTICE
- Do not share your password.
- Change your password after logging in.
- Keep your account credentials secure.

Student Portal:
https://www.bestlink.edu.ph/

Best regards,

Bestlink College Enrollment System

This is an automated email.";
    }

    /**
     * Test email
     */
    public function sendTestEmail($recipient)
    {
        return $this->sendAccountEmail(
            'Test Student',
            'TEST001',
            'TestPass123!',
            $recipient
        );
    }

    /**
     * Log email
     */
    private function logEmail($recipient, $studentNumber, $status)
    {
        try {
            $timestamp = date('Y-m-d H:i:s');
            $entry = '[' . $timestamp . '] Student: ' . $studentNumber .
                     ' | Email: ' . $recipient .
                     ' | Status: ' . $status . PHP_EOL;

            file_put_contents($this->logFile, $entry, FILE_APPEND | LOCK_EX);
        } catch (Exception $e) {
            error_log('Email log error: ' . $e->getMessage());
        }
    }
}
?>