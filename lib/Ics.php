<?php
declare(strict_types=1);

/**
 * Builds an iCalendar (.ics) file for one appointment, attached to the confirmation email
 * so customers can add it to their own calendar with one tap.
 *
 * METHOD:PUBLISH (not REQUEST): mail apps offer "add to calendar" instead of treating it
 * as a meeting invitation with accept/decline replies.
 */
final class Ics
{
    public static function booking(int $bookingId, string $title, DateTime $start, DateTime $end, string $location, string $description): string
    {
        $utc = new DateTimeZone('UTC');
        $fmt = static fn(DateTime $d): string => (clone $d)->setTimezone($utc)->format('Ymd\THis\Z');
        $host = parse_url(lagohm_config()['app']['base_url'] ?? '', PHP_URL_HOST) ?: 'lagohm.de';

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//LagOHM//Buchung//DE',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:booking-' . $bookingId . '@' . $host,
            'DTSTAMP:' . $fmt(new DateTime('now', $utc)),
            'DTSTART:' . $fmt($start),
            'DTEND:' . $fmt($end),
            'SUMMARY:' . self::escape($title),
            'LOCATION:' . self::escape($location),
            'DESCRIPTION:' . self::escape($description),
            'STATUS:CONFIRMED',
            'TRANSP:OPAQUE',
            'END:VEVENT',
            'END:VCALENDAR',
        ];
        return implode("\r\n", array_map([self::class, 'fold'], $lines)) . "\r\n";
    }

    /** RFC 5545 text escaping. */
    private static function escape(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\;', '\,', '\n'], $text);
    }

    /** Folds lines longer than 75 octets without splitting UTF-8 characters. */
    private static function fold(string $line): string
    {
        $out = '';
        $current = '';
        $limit = 75;
        foreach (preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) as $char) {
            if (strlen($current) + strlen($char) > $limit) {
                $out .= $current . "\r\n ";
                $current = '';
                $limit = 74; // continuation lines start with a space
            }
            $current .= $char;
        }
        return $out . $current;
    }
}
