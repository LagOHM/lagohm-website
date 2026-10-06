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
     * Sends a simple branded email: the LagOHM logo on top, then the given
     * plain text as the body (line breaks preserved). A plain-text
     * alternative is sent alongside for clients that prefer it.
     *
     * Optional $attachments: list of [content, filename, mime type], e.g. a calendar file.
     * Optional $replyTo: [email, name], so "Antworten" goes to that address instead of the sender.
     *
     * @return true|string true on success, or an error message string on failure.
     */
    public static function send(string $toEmail, string $toName, string $subject, string $bodyText, array $attachments = [], ?array $replyTo = null)
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
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; // Netcup requires implicit SSL/TLS on port 465, not STARTTLS on 587
            $mail->CharSet = 'UTF-8';

            $mail->setFrom($cfg['from_email'], $cfg['from_name']);
            $mail->addAddress($toEmail, $toName);
            if ($replyTo) {
                $mail->addReplyTo($replyTo[0], $replyTo[1] ?? '');
            }

            $logoPath = __DIR__ . '/../images/brand/lagohm-mail-logo.png';
            $hasLogo = is_file($logoPath);
            if ($hasLogo) {
                $mail->addEmbeddedImage($logoPath, 'lagohm-logo', 'lagohm-logo.png');
            }

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = self::renderHtml($bodyText, $hasLogo);
            $mail->AltBody = $bodyText;
            foreach ($attachments as [$content, $filename, $type]) {
                $mail->addStringAttachment($content, $filename, PHPMailer::ENCODING_BASE64, $type);
            }

            $mail->send();
            return true;
        } catch (PHPMailerException $e) {
            return $mail->ErrorInfo ?: $e->getMessage();
        }
    }

    private static function renderHtml(string $bodyText, bool $hasLogo): string
    {
        $logoHtml = $hasLogo
            ? '<img src="cid:lagohm-logo" alt="LagOHM" width="64" height="64" style="display:block;">'
            : '';
        $bodyHtml = nl2br(htmlspecialchars($bodyText, ENT_QUOTES, 'UTF-8'));

        return <<<HTML
<!DOCTYPE html>
<html lang="de">
<body style="margin:0;padding:0;background:#FBF4EF;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#FBF4EF;padding:32px 0;">
    <tr>
      <td align="center">
        <table role="presentation" width="480" cellpadding="0" cellspacing="0" style="background:#FBE5D3;border-radius:22px;padding:32px;font-family:Arial,Helvetica,sans-serif;color:#2B1B16;">
          <tr>
            <td align="center" style="padding-bottom:20px;">
              {$logoHtml}
            </td>
          </tr>
          <tr>
            <td style="font-size:15px;line-height:1.6;">
              {$bodyHtml}
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
    }
}
