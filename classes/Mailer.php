<?php
// classes/Mailer.php
// Dedicated mailer for Contact Messages replies
// Uses Gmail SMTP via PHPMailer

require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class Mailer
{
    /* ---------------------------------------------------------
     *  SMTP CONFIGURATION
     * --------------------------------------------------------- */
    private string $smtpHost     = 'smtp.gmail.com';
    private int    $smtpPort     = 587;
    private string $smtpUsername = 'villanuevabea034@gmail.com';
    private string $smtpPassword = 'upegpjngpqbptthk';
    private string $fromEmail    = 'villanuevabea034@gmail.com';
    private string $fromName     = 'Bestlink College Contact Center';

    private string $logFile;

    public function __construct()
    {
        $this->logFile = __DIR__ . '/../logs/mailer_log.txt';
        $logDir = dirname($this->logFile);

        if (!is_dir($logDir)) {
            @mkdir($logDir, 0777, true);
        }
    }

    /* ---------------------------------------------------------
     *  PUBLIC: SEND REPLY
     * --------------------------------------------------------- */
    public function sendReply(
        string $toEmail,
        string $toName,
        string $subject,
        string $body,
        array  $options = []
    ): array {
        $toEmail = trim($toEmail);
        $toName  = trim($toName);
        $subject = trim($subject);
        $body    = trim($body);

        if ($toEmail === '') {
            return $this->fail('Recipient email is empty.');
        }
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return $this->fail('Invalid recipient email: ' . $toEmail);
        }
        if ($subject === '') {
            return $this->fail('Subject is required.');
        }
        if ($body === '') {
            return $this->fail('Message body is required.');
        }
        if (empty($this->smtpUsername) || empty($this->smtpPassword)) {
            return $this->fail('SMTP credentials are not configured.');
        }

        $html  = $this->buildReplyHtml($toName, $body);
        $plain = $this->buildReplyPlain($toName, $body);

        $result = $this->sendWithPHPMailer($toEmail, $subject, $html, $plain, $options);

        $this->log([
            'to'      => $toEmail,
            'subject' => $subject,
            'status'  => $result['success'] ? 'sent' : 'failed',
            'error'   => $result['success'] ? null : $result['message'],
        ]);

        return $result;
    }

    /* ---------------------------------------------------------
     *  CORE: PHPMailer transport
     * --------------------------------------------------------- */
    private function sendWithPHPMailer(
        string $recipient,
        string $subject,
        string $htmlMessage,
        string $plainMessage,
        array  $options = []
    ): array {
        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host       = $this->smtpHost;
            $mail->SMTPAuth   = true;
            $mail->Username   = $this->smtpUsername;
            $mail->Password   = $this->smtpPassword;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = $this->smtpPort;
            $mail->CharSet    = 'UTF-8';
            $mail->Timeout    = 30;

            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer'       => true,
                    'verify_peer_name'  => true,
                    'allow_self_signed' => false,
                ],
            ];

            $mail->SMTPDebug   = 0;
            $mail->Debugoutput = function ($str, $level) {
                error_log('PHPMailer SMTP: ' . trim($str));
            };

            $mail->setFrom($this->fromEmail, $this->fromName);
            $mail->addReplyTo($this->fromEmail, $this->fromName);
            $mail->addAddress($recipient);

            if (!empty($options['attachments']) && is_array($options['attachments'])) {
                foreach ($options['attachments'] as $path) {
                    if (is_file($path)) {
                        $mail->addAttachment($path);
                    }
                }
            }

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $htmlMessage;
            $mail->AltBody = $plainMessage;

            $mail->send();

            return [
                'success' => true,
                'message' => 'Email sent successfully to ' . $recipient,
            ];

        } catch (Exception $e) {
            $errorMessage = $mail->ErrorInfo ?: $e->getMessage();
            error_log('Mailer ERROR: ' . $errorMessage);

            return [
                'success' => false,
                'message' => 'SMTP Error: ' . $errorMessage,
            ];
        }
    }

    /* ---------------------------------------------------------
     *  TEMPLATES
     * --------------------------------------------------------- */
    private function buildReplyHtml(string $toName, string $body): string
    {
        $safeName = htmlspecialchars($toName !== '' ? $toName : 'there', ENT_QUOTES, 'UTF-8');
        $safeBody = nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8'));
        $year     = date('Y');

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reply from Bestlink College</title>
<style>
body { margin:0; padding:0; background:#f4f6f8; font-family:Arial, sans-serif; color:#333; }
.container { max-width:600px; margin:30px auto; background:#fff; border-radius:10px; overflow:hidden; border:1px solid #ddd; }
.header { background:#1a3c6e; color:#fff; padding:25px; text-align:center; }
.header h1 { margin:0; font-size:22px; }
.header p { margin:5px 0 0; font-size:13px; opacity:0.9; }
.content { padding:30px; }
.body-box { background:#f1f7ff; border-left:5px solid #1a3c6e; padding:20px; margin:20px 0; border-radius:4px; line-height:1.7; }
.footer { text-align:center; padding:20px; font-size:12px; color:#777; border-top:1px solid #ddd; }
</style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>Bestlink College</h1>
        <p>Contact Center Reply</p>
    </div>
    <div class="content">
        <p>Dear <strong>{$safeName}</strong>,</p>
        <div class="body-box">{$safeBody}</div>
        <p>If you have further questions, feel free to reply to this email.</p>
        <p>Best regards,<br><strong>Bestlink College Contact Center</strong></p>
    </div>
    <div class="footer">
        &copy; {$year} Bestlink College. All rights reserved.
    </div>
</div>
</body>
</html>
HTML;
    }

    private function buildReplyPlain(string $toName, string $body): string
    {
        $name = $toName !== '' ? $toName : 'there';
        return "BESTLINK COLLEGE - Contact Center Reply\n"
             . "========================================\n\n"
             . "Dear {$name},\n\n"
             . $body . "\n\n"
             . "Best regards,\n"
             . "Bestlink College Contact Center\n\n"
             . "---\n"
             . "This email was sent from the Bestlink College LMS.";
    }

    /* ---------------------------------------------------------
     *  HELPERS
     * --------------------------------------------------------- */
    private function fail(string $message): array
    {
        return ['success' => false, 'message' => $message];
    }

    private function log(array $data): void
    {
        try {
            $line = sprintf(
                "[%s] to=%s | subject=%s | status=%s%s\n",
                date('Y-m-d H:i:s'),
                $data['to'] ?? '-',
                $data['subject'] ?? '-',
                $data['status'] ?? '-',
                !empty($data['error']) ? ' | error=' . $data['error'] : ''
            );
            file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);
        } catch (Exception $e) {
            error_log('Mailer::log - ' . $e->getMessage());
        }
    }
}