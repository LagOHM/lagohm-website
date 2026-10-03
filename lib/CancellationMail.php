<?php
declare(strict_types=1);

require_once __DIR__ . '/Mailer.php';

/**
 * Emails sent when a booking is cancelled: a confirmation to the customer (both when they
 * cancel themselves and when Helena cancels in the admin area), and a notice to Helena when
 * a customer cancels via their self-service link. Best effort: failures are logged, never thrown.
 */
final class CancellationMail
{
    public const BY_CUSTOMER = 'customer';
    public const BY_ADMIN = 'admin';

    /** @return bool whether the customer was emailed successfully */
    public static function send(int $bookingId, string $cancelledBy): bool
    {
        try {
            $pdo = lagohm_db();
            $stmt = $pdo->prepare('SELECT b.customer_name, b.customer_email, b.start_datetime, s.name AS service_name
                                   FROM bookings b JOIN services s ON s.id = b.service_id WHERE b.id = ?');
            $stmt->execute([$bookingId]);
            $b = $stmt->fetch();
            if (!$b) {
                return false;
            }

            $tz = new DateTimeZone(lagohm_config()['app']['timezone'] ?? 'Europe/Berlin');
            $start = new DateTime($b['start_datetime'], new DateTimeZone('UTC'));
            if ($start <= new DateTime('now', new DateTimeZone('UTC'))) {
                return false; // tidying up past appointments: nobody needs an email
            }
            $start->setTimezone($tz);
            $when = $start->format('d.m.Y') . ' um ' . $start->format('H:i') . ' Uhr';
            $baseUrl = rtrim(lagohm_config()['app']['base_url'], '/');

            if ($cancelledBy === self::BY_CUSTOMER) {
                $body = "Hallo {$b['customer_name']},\n\n"
                    . "dein Termin am {$when} ({$b['service_name']}) wurde storniert.\n\n"
                    . "Falls du ihn in deinen Kalender eingetragen hast, lösch ihn dort bitte auch.\n\n"
                    . "Du kannst jederzeit einen neuen Termin buchen: {$baseUrl}\n\n"
                    . "Alles Liebe,\nHelena · LagOHM";
            } else {
                $body = "Hallo {$b['customer_name']},\n\n"
                    . "leider muss ich deinen Termin am {$when} ({$b['service_name']}) absagen. Das tut mir leid!\n\n"
                    . "Falls du ihn in deinen Kalender eingetragen hast, lösch ihn dort bitte.\n\n"
                    . "Buch dir gern einen neuen Termin unter {$baseUrl} oder schreib mir einfach.\n\n"
                    . "Alles Liebe,\nHelena · LagOHM";
            }
            $customerEmailed = self::deliver($bookingId, $b['customer_email'], $b['customer_name'], 'Dein Termin bei LagOHM wurde storniert', $body);

            if ($cancelledBy === self::BY_CUSTOMER) {
                $owner = (string)(lagohm_config()['smtp']['from_email'] ?? '');
                if ($owner !== '') {
                    $notice = "Hallo Helena,\n\n"
                        . "{$b['customer_name']} hat den Termin am {$when} ({$b['service_name']}) selbst storniert.\n\n"
                        . "Der Termin ist wieder frei und wurde aus deinem Google Kalender entfernt.\n"
                        . "Übersicht: {$baseUrl}/admin/";
                    self::deliver($bookingId, $owner, 'Helena', "Storniert: {$b['customer_name']}, {$start->format('d.m.')} {$start->format('H:i')}", $notice);
                }
            }
            return $customerEmailed;
        } catch (Throwable $e) {
            // Cancellation itself already happened; emails are best effort.
            return false;
        }
    }

    private static function deliver(int $bookingId, string $to, string $name, string $subject, string $body): bool
    {
        $result = Mailer::send($to, $name, $subject, $body);
        if ($result !== true) {
            lagohm_db()->prepare('INSERT INTO booking_audit_log (booking_id, action, detail) VALUES (?, "email_failed", ?)')
                ->execute([$bookingId, substr('cancellation: ' . (string)$result, 0, 490)]);
        }
        return $result === true;
    }
}
