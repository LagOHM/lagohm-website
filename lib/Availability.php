<?php
declare(strict_types=1);

require_once __DIR__ . '/GoogleCalendar.php';

/**
 * Computes bookable start times for a service on a given date.
 * Weekday convention follows PHP's date('w'): 0=Sunday .. 6=Saturday,
 * matching availability_templates.weekday.
 *
 * Busy time = confirmed bookings in the DB + busy blocks from the connected Google Calendar.
 * Throws GoogleCalendarException if Google is connected but unreachable (callers fail closed).
 */
final class Availability
{
    public static function getService(PDO $pdo, string $slug): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM services WHERE slug = ? AND active = 1');
        $stmt->execute([$slug]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * @return string[] list of 'H:i' start times, in the app timezone
     */
    public static function getAvailableSlots(PDO $pdo, array $service, string $dateYmd): array
    {
        $tz = new DateTimeZone(lagohm_config()['app']['timezone'] ?? 'Europe/Berlin');
        $date = DateTime::createFromFormat('Y-m-d', $dateYmd, $tz);
        if (!$date) {
            return [];
        }
        $weekday = (int)$date->format('w');

        $granularity = (int)(lagohm_setting('slot_granularity_minutes', '15'));
        $minLeadHours = (int)(lagohm_setting('min_lead_hours', '3'));
        $maxHorizonDays = (int)(lagohm_setting('max_horizon_days', '60'));

        $now = new DateTime('now', $tz);
        $earliestStart = (clone $now)->modify("+{$minLeadHours} hours");
        $horizonEnd = (clone $now)->modify("+{$maxHorizonDays} days");
        if ($date > $horizonEnd) {
            return [];
        }

        $stmt = $pdo->prepare('SELECT start_time, end_time FROM availability_templates WHERE weekday = ? AND active = 1');
        $stmt->execute([$weekday]);
        $windows = $stmt->fetchAll();
        if (!$windows) {
            return [];
        }

        $duration = (int)$service['duration_minutes'];
        $buffer = (int)$service['buffer_minutes'];

        $existing = self::getBusyIntervals($pdo, $date, $tz);

        $slots = [];
        foreach ($windows as $window) {
            [$winStart, $winEnd] = self::windowToDateTimes($date, $window['start_time'], $window['end_time'], $tz);

            $candidate = clone $winStart;
            while (true) {
                $candidateEnd = (clone $candidate)->modify("+{$duration} minutes");
                if ($candidateEnd > $winEnd) {
                    break;
                }
                if ($candidate >= $earliestStart && !self::overlapsAny($candidate, $candidateEnd, $buffer, $existing)) {
                    $slots[] = $candidate->format('H:i');
                }
                $candidate->modify("+{$granularity} minutes");
            }
        }

        return $slots;
    }

    /**
     * Re-validates a specific slot at booking time (inside a transaction, with a fresh read).
     */
    public static function isSlotStillAvailable(PDO $pdo, array $service, DateTime $start, DateTime $end): bool
    {
        $tz = $start->getTimezone();
        $minLeadHours = (int)(lagohm_setting('min_lead_hours', '3'));
        $now = new DateTime('now', $tz);
        if ($start < (clone $now)->modify("+{$minLeadHours} hours")) {
            return false;
        }

        $weekday = (int)$start->format('w');
        $stmt = $pdo->prepare('SELECT start_time, end_time FROM availability_templates WHERE weekday = ? AND active = 1');
        $stmt->execute([$weekday]);
        $windows = $stmt->fetchAll();
        $fitsWindow = false;
        foreach ($windows as $window) {
            $day = (clone $start)->setTime(0, 0);
            [$winStart, $winEnd] = self::windowToDateTimes($day, $window['start_time'], $window['end_time'], $tz);
            if ($start >= $winStart && $end <= $winEnd) {
                $fitsWindow = true;
                break;
            }
        }
        if (!$fitsWindow) {
            return false;
        }

        $buffer = (int)$service['buffer_minutes'];
        $existing = self::getBusyIntervals($pdo, $start, $tz, true);

        return !self::overlapsAny($start, $end, $buffer, $existing);
    }

    private static function getBusyIntervals(PDO $pdo, DateTime $date, DateTimeZone $tz, bool $forUpdate = false): array
    {
        // Pull a window a bit wider than one day in UTC to safely cover timezone edges.
        $dayStartUtc = (clone $date)->setTime(0, 0)->setTimezone(new DateTimeZone('UTC'))->modify('-1 day');
        $dayEndUtc = (clone $date)->setTime(23, 59, 59)->setTimezone(new DateTimeZone('UTC'))->modify('+1 day');

        $sql = 'SELECT start_datetime, end_datetime FROM bookings
                WHERE status = "confirmed" AND start_datetime < ? AND end_datetime > ?';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$dayEndUtc->format('Y-m-d H:i:s'), $dayStartUtc->format('Y-m-d H:i:s')]);

        $intervals = [];
        foreach ($stmt->fetchAll() as $row) {
            $start = new DateTime($row['start_datetime'], new DateTimeZone('UTC'));
            $end = new DateTime($row['end_datetime'], new DateTimeZone('UTC'));
            $start->setTimezone($tz);
            $end->setTimezone($tz);
            $intervals[] = [$start, $end];
        }

        if (GoogleCalendar::isConnected()) {
            foreach (GoogleCalendar::busyIntervals($dayStartUtc, $dayEndUtc) as [$busyStart, $busyEnd]) {
                $intervals[] = [(clone $busyStart)->setTimezone($tz), (clone $busyEnd)->setTimezone($tz)];
            }
        }
        return $intervals;
    }

    private static function overlapsAny(DateTime $start, DateTime $end, int $bufferMinutes, array $intervals): bool
    {
        $bufferedEnd = (clone $end)->modify("+{$bufferMinutes} minutes");
        foreach ($intervals as [$busyStart, $busyEnd]) {
            $busyBufferedEnd = (clone $busyEnd)->modify("+{$bufferMinutes} minutes");
            if ($start < $busyBufferedEnd && $busyStart < $bufferedEnd) {
                return true;
            }
        }
        return false;
    }

    private static function windowToDateTimes(DateTime $date, string $startTime, string $endTime, DateTimeZone $tz): array
    {
        [$sh, $sm] = array_map('intval', explode(':', $startTime));
        [$eh, $em] = array_map('intval', explode(':', $endTime));
        $winStart = (clone $date)->setTimezone($tz)->setTime($sh, $sm, 0);
        $winEnd = (clone $date)->setTimezone($tz)->setTime($eh, $em, 0);
        return [$winStart, $winEnd];
    }
}
