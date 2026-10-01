<?php
declare(strict_types=1);

/**
 * Daily retention cleanup, as promised in datenschutz.html ("Online-Terminbuchung"):
 * - IP addresses are removed from bookings 30 days after the booking was made.
 * - Bookings (and their Google Calendar events and audit log entries) are deleted
 *   12 months after the appointment.
 *
 * Run once a day as a Netcup scheduled task (see SETUP.md). CLI only.
 * Pass --dry-run to only print what would be deleted.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/GoogleCalendar.php';

const IP_RETENTION_DAYS = 30;
const BOOKING_RETENTION_MONTHS = 12;

$dryRun = in_array('--dry-run', $argv, true);
$pdo = lagohm_db();

// 1. IP addresses (created_at uses the MySQL session clock, like the rate limit in booking-create.php).
$ipWhere = 'ip_address IS NOT NULL AND created_at < (NOW() - INTERVAL ' . IP_RETENTION_DAYS . ' DAY)';
if ($dryRun) {
    $ipCount = (int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE $ipWhere")->fetchColumn();
} else {
    $ipCount = $pdo->exec("UPDATE bookings SET ip_address = NULL WHERE $ipWhere");
}

// 2. Old bookings (start/end are stored in UTC).
$old = $pdo->query('SELECT id, google_event_id, start_datetime FROM bookings
                    WHERE end_datetime < (UTC_TIMESTAMP() - INTERVAL ' . BOOKING_RETENTION_MONTHS . ' MONTH)
                    ORDER BY id')->fetchAll();

$googleConnected = GoogleCalendar::isConnected();
$deleted = 0;
$skipped = 0;
$orphanedEvents = [];

foreach ($old as $b) {
    $id = (int)$b['id'];
    if ($dryRun) {
        $deleted++;
        continue;
    }
    if (!empty($b['google_event_id'])) {
        if ($googleConnected) {
            try {
                GoogleCalendar::deleteEvent((string)$b['google_event_id']);
            } catch (Throwable $e) {
                // Keep the booking so tomorrow's run retries the calendar deletion.
                GoogleCalendar::reportFailure('retention cleanup', $e, $id);
                $skipped++;
                continue;
            }
        } else {
            // Google was disconnected later; the event has to be removed by hand.
            $orphanedEvents[] = $b['start_datetime'] . ' UTC';
        }
    }
    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM booking_audit_log WHERE booking_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM bookings WHERE id = ?')->execute([$id]);
    $pdo->commit();
    $deleted++;
}

// 3. Audit entries not tied to a booking (e.g. calendar errors) follow the same retention.
$auditWhere = 'booking_id IS NULL AND created_at < (NOW() - INTERVAL ' . BOOKING_RETENTION_MONTHS . ' MONTH)';
if ($dryRun) {
    $auditCount = (int)$pdo->query("SELECT COUNT(*) FROM booking_audit_log WHERE $auditWhere")->fetchColumn();
} else {
    $auditCount = $pdo->exec("DELETE FROM booking_audit_log WHERE $auditWhere");
}

$prefix = $dryRun ? '[dry run] would ' : '';
echo "{$prefix}clear IP address on {$ipCount} booking(s)\n";
echo "{$prefix}delete {$deleted} booking(s) older than " . BOOKING_RETENTION_MONTHS . " months\n";
echo "{$prefix}delete {$auditCount} unlinked audit log entr" . ($auditCount === 1 ? 'y' : 'ies') . "\n";
if ($skipped > 0) {
    echo "kept {$skipped} booking(s) because the Google Calendar event could not be deleted; retrying next run\n";
}
if ($orphanedEvents) {
    // Printed so the scheduled task's output email tells Helena what to clean up manually.
    echo "Google Calendar is not connected; please delete these calendar entries by hand:\n";
    foreach ($orphanedEvents as $when) {
        echo "  - {$when}\n";
    }
}
