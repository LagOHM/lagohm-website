<?php
declare(strict_types=1);

require_once __DIR__ . '/PHPMailer/Exception.php';
require_once __DIR__ . '/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

final class Mailer
{
    /**
     * @return true|string true on success, or an error message string on failure.
     */
    public static function send(string $toEmail, string $toName, string $subject, string $bodyText)
    {
        $cfg = lagohm_config()['smtp'];
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = $cfg['host'];
            $mail->Port = $cfg['port'];
            $mail->SMTPAuth = true;
            $mail->Username = $cfg['user'];
            $mail->Password = $cfg['pass'];
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->CharSet = 'UTF-8';

            $mail->setFrom($cfg['from_email'], $cfg['from_name']);
            $mail->addAddress($toEmail, $toName);
            $mail->isHTML(false);
            $mail->Subject = $subject;
            $mail->Body = $bodyText;

            $mail->send();
            return true;
        } catch (PHPMailerException $e) {
            return $mail->ErrorInfo ?: $e->getMessage();
        }
    }
}
