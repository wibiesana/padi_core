<?php

declare(strict_types=1);

namespace Wibiesana\Padi\Core;

/**
 * Email Helper - Send emails via PHPMailer
 * 
 * Worker-mode safe: creates fresh PHPMailer instance per send.
 * Shared hosting safe: supports SMTP and PHP mail().
 * 
 * Requires: phpmailer/phpmailer (optional dependency, install via composer require phpmailer/phpmailer)
 */
class Email
{
    /**
     * Send an email
     * 
     * @param string $to Recipient email address
     * @param string $subject Email subject
     * @param string $body HTML email body
     * @param array $attachments File paths to attach
     * @return bool Success status
     * @throws \RuntimeException If phpmailer/phpmailer is not installed
     */
    public static function send(string $to, string $subject, string $body, array $attachments = []): bool
    {
        if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            throw new \RuntimeException(
                'PHPMailer is required for sending emails. Install it via: composer require phpmailer/phpmailer'
            );
        }

        // Validate email address
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            Logger::error("Invalid email recipient", ['to' => $to]);
            return false;
        }

        $root = defined('PADI_ROOT') ? PADI_ROOT : dirname(__DIR__, 4);
        $configPath = $root . '/config/mail.php';

        if (!file_exists($configPath)) {
            Logger::error("Mail configuration file not found");
            return false;
        }

        $config = require $configPath;
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

        try {
            // Server settings
            if (($config['driver'] ?? 'smtp') === 'smtp') {
                $mail->isSMTP();
                $mail->Host       = $config['host'];
                $mail->SMTPAuth   = true;
                $mail->Username   = $config['username'];
                $mail->Password   = $config['password'];
                $mail->SMTPSecure = $config['encryption'] === 'tls' ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
                $mail->Port       = $config['port'];
                $mail->Timeout    = $config['timeout'] ?? 30;
            }

            // Recipients
            $mail->setFrom($config['from_address'], $config['from_name']);
            $mail->addAddress($to);

            // Content
            $mail->isHTML(true);
            $mail->CharSet = 'UTF-8';
            $mail->Subject = $subject;
            $mail->Body    = $body;

            // Attachments
            foreach ($attachments as $attachment) {
                if (file_exists($attachment) && is_file($attachment)) {
                    $mail->addAttachment($attachment);
                }
            }

            $mail->send();
            return true;
        } catch (\PHPMailer\PHPMailer\Exception $e) {
            if (Env::get('APP_DEBUG') === 'true') {
                error_log("Email failed to send: " . $mail->ErrorInfo);
            }
            Logger::error("Email failed to send", ['error' => $mail->ErrorInfo, 'to' => $to]);
            return false;
        }
    }
}
