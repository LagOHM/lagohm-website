<?php
declare(strict_types=1);

/**
 * Sends a reminder email before each confirmed appointment.
 *
 * A booking gets one reminder once its start is less than `reminder_hours_before`
 * (app_settings, default 24) hours away. Bookings made inside that window are skipped:
 * the confirmation email was sent recently enough.
 *
 * Run hourly as a Netcup scheduled task (see SETUP.md). CLI only.
 *   --dry-run      only print which reminders would be sent
 *   --booking=ID   send the reminder for this booking now, ignoring the time window (for testing)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/Mailer.php';

$dryRun = in_array('--dry-run', $argv, true);
$forceId = null;
foreach ($argv as $arg) {
    if (preg_match('/^--booking=(\d+)$/', $arg, $m)) {
        $forceId = (int)$m[1];
    }
}

$pdo = lagohm_db();
$hours = max(1, (int)lagohm_setting('reminder_hours_before', '24'));

$select = 'SELECT b.id, b.customer_name, b.customer_email, b.start_datetime, b.cancellation_token,
                  s.name AS service_name, s.duration_minutes
           FROM bookings b JOIN services s ON s.id = b.service_id
           WHERE b.status = "confirmed" AND b.start_datetime > UTC_TIMESTAMP()';
if ($forceId !== null) {
    $stmt = $pdo->prepare("$select AND b.id = ?");
    $stmt->execute([$forceId]);
} else {
    // start/end are stored in UTC, created_at uses the MySQL session clock: shift created_at to UTC.
    $offsetSeconds = (int)$pdo->query('SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW())')->fetchColumn();
    $offsetSeconds = (int)(round($offsetSeconds / 60) * 60);
    $stmt = $pdo->prepare("$select
        AND b.reminder_sent_at IS NULL
        AND b.start_datetime <= (UTC_TIMESTAMP() + INTERVAL ? HOUR)
        AND (b.created_at - INTERVAL ? SECOND) <= (b.start_datetime - INTERVAL ? HOUR)
        ORDER BY b.start_datetime");
    $stmt->execute([$hours, $offsetSeconds, $hours]);
}
$bookings = $stmt->fetchAll();

$tz = new DateTimeZone(lagohm_config()['app']['timezone'] ?? 'Europe/Berlin');
$baseUrl = rtrim(lagohm_config()['app']['base_url'], '/');
$address = lagohm_config()['business']['address'] ?? '';
$sent = 0;
$failed = 0;

foreach ($bookings as $b) {
    $id = (int)$b['id'];
    if ($dryRun) {
        echo "[dry run] would remind booking #{$id} ({$b['start_datetime']} UTC)\n";
        continue;
    }

    // Claim the booking first so overlapping runs never send twice.
    $claim = $pdo->prepare('UPDATE bookings SET reminder_sent_at = UTC_TIMESTAMP()
                            WHERE id = ?' . ($forceId !== null ? '' : ' AND reminder_sent_at IS NULL'));
    $claim->execute([$id]);
    if ($claim->rowCount() === 0) {
        continue;
    }

    $start = new DateTime($b['start_datetime'], new DateTimeZone('UTC'));
    $start->setTimezone($tz);
    $today = new DateTime('today', $tz);
    $days = (int)$today->diff((clone $start)->setTime(0, 0))->format('%r%a');
    $dayWord = $days === 0 ? 'heute' : ($days === 1 ? 'morgen' : 'am ' . $start->format('d.m.Y'));
    $cancelUrl = $baseUrl . '/api/booking-cancel.php?token=' . $b['cancellation_token'];

    $body = "Hallo {$b['customer_name']},\n\n"
        . "kleine Erinnerung: {$dayWord} um {$start->format('H:i')} Uhr ist dein Termin bei LagOHM.\n\n"
        . "Leistung: {$b['service_name']} ({$b['duration_minutes']} Minuten)\n"
        . "Termin: {$start->format('d.m.Y')} um {$start->format('H:i')} Uhr\n\n"
        . "Ort:\n{$address}\n" . (lagohm_address_hint() !== '' ? lagohm_address_hint() . "\n" : '') . "\n"
        . "Falls du doch nicht kommen kannst, sag den Termin bitte über diesen Link ab:\n{$cancelUrl}\n\n"
        . "Ich freu mich auf dich,\nHelena · LagOHM";

    try {
        $result = Mailer::send($b['customer_email'], $b['customer_name'], 'Erinnerung an deinen Termin bei LagOHM', $body);
    } catch (Throwable $e) {
        $result = $e->getMessage();
    }
    if ($result === true) {
        $pdo->prepare('INSERT INTO booking_audit_log (booking_id, action, detail) VALUES (?, "reminder_sent", NULL)')
            ->execute([$id]);
        $sent++;
    } else {
        // Release the claim so the next run retries.
        $pdo->prepare('UPDATE bookings SET reminder_sent_at = NULL WHERE id = ?')->execute([$id]);
        $pdo->prepare('INSERT INTO booking_audit_log (booking_id, action, detail) VALUES (?, "email_failed", ?)')
            ->execute([$id, substr('reminder: ' . (string)$result, 0, 490)]);
        echo "reminder for booking #{$id} failed: {$result}\n";
        $failed++;
    }
}

if (!$dryRun) {
    echo "sent {$sent} reminder(s)" . ($failed > 0 ? ", {$failed} failed (will retry next run)" : '') . "\n";
}
if ($forceId !== null && !$bookings) {
    echo "booking #{$forceId} not found, cancelled or already in the past\n";
}
exit($failed > 0 ? 1 : 0);
