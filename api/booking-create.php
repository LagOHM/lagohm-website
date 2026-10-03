<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/Csrf.php';
require_once __DIR__ . '/../lib/Availability.php';
require_once __DIR__ . '/../lib/Mailer.php';
require_once __DIR__ . '/../lib/Ics.php';

header('Content-Type: application/json; charset=utf-8');

function fail(int $code, string $message): void
{
    http_response_code($code);
    echo json_encode(['error' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail(405, 'POST required');
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) {
    $input = $_POST;
}

// --- Spam defenses ---
if (!Csrf::verify($input['csrf_token'] ?? null)) {
    fail(403, 'Die Seite war zu lange offen. Bitte lade sie neu und versuch es nochmal.');
}
if (!empty($input['website'])) {
    // Honeypot field; bots fill it, humans never see it.
    fail(400, 'Die Buchung konnte nicht abgeschickt werden. Bitte lade die Seite neu und versuch es nochmal.');
}
$renderedAt = (float)($input['form_rendered_at'] ?? 0);
if ($renderedAt <= 0 || (microtime(true) - $renderedAt) < 3.0) {
    fail(400, 'Die Buchung konnte nicht abgeschickt werden. Bitte lade die Seite neu und versuch es nochmal.');
}

$pdo = lagohm_db();
$ip = $_SERVER['REMOTE_ADDR'] ?? null;

// --- Field validation ---
$serviceSlug = preg_replace('/[^a-z]/', '', (string)($input['service'] ?? ''));
$dateStr = (string)($input['date'] ?? '');
$timeStr = (string)($input['time'] ?? '');
$name = trim((string)($input['name'] ?? ''));
$email = trim((string)($input['email'] ?? ''));
$phone = trim((string)($input['phone'] ?? ''));
$note = trim((string)($input['note'] ?? ''));
$consent = !empty($input['gdpr_consent']);

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr) || !preg_match('/^\d{2}:\d{2}$/', $timeStr)) {
    fail(400, 'Bitte wähle Datum und Uhrzeit aus.');
}
if ($name === '' || mb_strlen($name) > 150) {
    fail(400, 'Bitte gib deinen Namen an.');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
    fail(400, 'Bitte gib eine gültige E-Mail-Adresse an.');
}
// Catch typos like "gmail.vom": the domain must exist and be able to receive mail.
$emailDomain = substr(strrchr($email, '@'), 1);
if (function_exists('idn_to_ascii')) {
    $emailDomain = idn_to_ascii($emailDomain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $emailDomain;
}
if (!checkdnsrr($emailDomain . '.', 'MX') && !checkdnsrr($emailDomain . '.', 'A') && !checkdnsrr($emailDomain . '.', 'AAAA')) {
    fail(400, 'Die E-Mail-Adresse scheint nicht zu existieren (' . $emailDomain . '). Bitte prüf sie auf Tippfehler.');
}
if (mb_strlen($phone) > 40) {
    fail(400, 'Die Telefonnummer ist zu lang.');
}
if (mb_strlen($note) > 2000) {
    fail(400, 'Die Nachricht ist zu lang (höchstens 2000 Zeichen).');
}
if (!$consent) {
    fail(400, 'Bitte bestätige, dass du die Datenschutzerklärung gelesen hast.');
}

$service = Availability::getService($pdo, $serviceSlug);
if (!$service) {
    fail(404, 'Bitte wähle eine Leistung aus.');
}

// --- Rate limiting ---
$rl = $pdo->prepare('SELECT COUNT(*) AS c FROM bookings WHERE ip_address = ? AND created_at > (NOW() - INTERVAL 1 HOUR)');
$rl->execute([$ip]);
if ((int)$rl->fetch()['c'] >= 3) {
    fail(429, 'Von hier wurden gerade schon mehrere Buchungen abgeschickt. Bitte versuch es später nochmal oder schreib mir direkt.');
}
$rl2 = $pdo->prepare('SELECT COUNT(*) AS c FROM bookings WHERE customer_email = ? AND created_at > (NOW() - INTERVAL 1 DAY)');
$rl2->execute([$email]);
if ((int)$rl2->fetch()['c'] >= 5) {
    fail(429, 'Von hier wurden gerade schon mehrere Buchungen abgeschickt. Bitte versuch es später nochmal oder schreib mir direkt.');
}

$tz = new DateTimeZone(lagohm_config()['app']['timezone'] ?? 'Europe/Berlin');
$start = DateTime::createFromFormat('Y-m-d H:i', "$dateStr $timeStr", $tz);
if (!$start) {
    fail(400, 'Bitte wähle Datum und Uhrzeit aus.');
}
$end = (clone $start)->modify('+' . (int)$service['duration_minutes'] . ' minutes');

$startUtc = (clone $start)->setTimezone(new DateTimeZone('UTC'));
$endUtc = (clone $end)->setTimezone(new DateTimeZone('UTC'));

$pdo->beginTransaction();
try {
    if (!Availability::isSlotStillAvailable($pdo, $service, $start, $end)) {
        $pdo->rollBack();
        fail(409, 'Dieser Termin ist leider gerade nicht mehr frei. Bitte wähle eine andere Zeit.');
    }

    $cancellationToken = bin2hex(random_bytes(32));
    $ins = $pdo->prepare('INSERT INTO bookings
        (service_id, customer_name, customer_email, customer_phone, customer_note, start_datetime, end_datetime, status, cancellation_token, ip_address)
        VALUES (?, ?, ?, ?, ?, ?, ?, "confirmed", ?, ?)');
    $ins->execute([
        $service['id'],
        $name,
        $email,
        $phone !== '' ? $phone : null,
        $note !== '' ? $note : null,
        $startUtc->format('Y-m-d H:i:s'),
        $endUtc->format('Y-m-d H:i:s'),
        $cancellationToken,
        $ip,
    ]);
    $bookingId = (int)$pdo->lastInsertId();

    $log = $pdo->prepare('INSERT INTO booking_audit_log (booking_id, action, detail) VALUES (?, "created", ?)');
    $log->execute([$bookingId, "service={$serviceSlug} start={$startUtc->format('c')}"]);

    $pdo->commit();
} catch (GoogleCalendarException $e) {
    $pdo->rollBack();
    GoogleCalendar::reportFailure('booking-create', $e);
    fail(503, 'Buchen ist gerade kurz nicht möglich. Bitte versuch es in ein paar Minuten nochmal.');
} catch (Throwable $e) {
    $pdo->rollBack();
    fail(500, 'Die Buchung konnte nicht gespeichert werden. Bitte versuch es nochmal.');
}

// --- Google Calendar event (best-effort; booking already stands regardless) ---
if (GoogleCalendar::isConnected()) {
    try {
        $eventId = GoogleCalendar::createBookingEvent([
            'id' => $bookingId,
            'customer_name' => $name,
            'customer_email' => $email,
            'customer_phone' => $phone,
            'customer_note' => $note,
        ], $service, $start, $end);
        $pdo->prepare('UPDATE bookings SET google_event_id = ? WHERE id = ?')->execute([$eventId, $bookingId]);
    } catch (Throwable $e) {
        GoogleCalendar::reportFailure('create event', $e, $bookingId);
    }
}

// --- Confirmation email (best-effort; booking already stands regardless) ---
$address = lagohm_config()['business']['address'] ?? '';
$priceEuro = number_format(((int)$service['price_cents']) / 100, 2, ',', '.');
$dateLabelFormatter = clone $start;
$dateLabel = $dateLabelFormatter->format('d.m.Y') . ' um ' . $dateLabelFormatter->format('H:i') . ' Uhr';
$cancelUrl = rtrim(lagohm_config()['app']['base_url'], '/') . '/api/booking-cancel.php?token=' . $cancellationToken;

$body = "Hallo {$name},\n\n"
    . "deine Buchung bei LagOHM ist bestätigt:\n\n"
    . "Leistung: {$service['name']} ({$service['duration_minutes']} Minuten)\n"
    . "Termin: {$dateLabel}\n"
    . "Preis: {$priceEuro} €\n\n"
    . "Ort:\n{$address}\n\n"
    . "Zahlung: bar oder per Überweisung, vor Ort oder im Anschluss an den Termin.\n\n"
    . "Im Anhang findest du den Termin zum Eintragen in deinen Kalender.\n\n"
    . "Falls du den Termin absagen musst, nutze bitte diesen Link:\n{$cancelUrl}\n\n"
    . "Bis bald,\nHelena · LagOHM";

$attachments = [];
try {
    $ics = Ics::booking($bookingId, $service['name'] . ' bei LagOHM', $start, $end,
        str_replace("\n", ', ', $address), "Absagen: {$cancelUrl}");
    $attachments[] = [$ics, 'LagOHM-Termin.ics', 'text/calendar; charset=utf-8; method=PUBLISH'];
} catch (Throwable $e) {
    // The email still goes out without the calendar file.
}

$mailResult = Mailer::send($email, $name, 'Deine Buchung bei LagOHM', $body, $attachments);
if ($mailResult !== true) {
    $log = $pdo->prepare('INSERT INTO booking_audit_log (booking_id, action, detail) VALUES (?, "email_failed", ?)');
    $log->execute([$bookingId, substr((string)$mailResult, 0, 490)]);
}

echo json_encode([
    'success' => true,
    'booking_id' => $bookingId,
    'service' => $service['name'],
    'start' => $startUtc->format('c'),
    'date_label' => $dateLabel,
    'price_eur' => $priceEuro,
]);
