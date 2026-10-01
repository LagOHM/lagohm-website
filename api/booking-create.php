<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/Csrf.php';
require_once __DIR__ . '/../lib/Availability.php';
require_once __DIR__ . '/../lib/Mailer.php';

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
    fail(403, 'Invalid or missing CSRF token');
}
if (!empty($input['website'])) {
    // Honeypot field; bots fill it, humans never see it.
    fail(400, 'Invalid submission');
}
$renderedAt = (float)($input['form_rendered_at'] ?? 0);
if ($renderedAt <= 0 || (microtime(true) - $renderedAt) < 3.0) {
    fail(400, 'Invalid submission');
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
    fail(400, 'Invalid date/time');
}
if ($name === '' || mb_strlen($name) > 150) {
    fail(400, 'Please provide your name');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
    fail(400, 'Please provide a valid email address');
}
if (mb_strlen($phone) > 40) {
    fail(400, 'Phone number too long');
}
if (mb_strlen($note) > 2000) {
    fail(400, 'Note too long');
}
if (!$consent) {
    fail(400, 'Please accept the privacy policy');
}

$service = Availability::getService($pdo, $serviceSlug);
if (!$service) {
    fail(404, 'Unknown service');
}

// --- Rate limiting ---
$rl = $pdo->prepare('SELECT COUNT(*) AS c FROM bookings WHERE ip_address = ? AND created_at > (NOW() - INTERVAL 1 HOUR)');
$rl->execute([$ip]);
if ((int)$rl->fetch()['c'] >= 3) {
    fail(429, 'Too many requests, please try again later');
}
$rl2 = $pdo->prepare('SELECT COUNT(*) AS c FROM bookings WHERE customer_email = ? AND created_at > (NOW() - INTERVAL 1 DAY)');
$rl2->execute([$email]);
if ((int)$rl2->fetch()['c'] >= 5) {
    fail(429, 'Too many requests, please try again later');
}

$tz = new DateTimeZone(lagohm_config()['app']['timezone'] ?? 'Europe/Berlin');
$start = DateTime::createFromFormat('Y-m-d H:i', "$dateStr $timeStr", $tz);
if (!$start) {
    fail(400, 'Invalid date/time');
}
$end = (clone $start)->modify('+' . (int)$service['duration_minutes'] . ' minutes');

$startUtc = (clone $start)->setTimezone(new DateTimeZone('UTC'));
$endUtc = (clone $end)->setTimezone(new DateTimeZone('UTC'));

$pdo->beginTransaction();
try {
    if (!Availability::isSlotStillAvailable($pdo, $service, $start, $end)) {
        $pdo->rollBack();
        fail(409, 'This slot is no longer available. Please pick another one.');
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
} catch (Throwable $e) {
    $pdo->rollBack();
    fail(500, 'Could not create booking');
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
    . "Falls du den Termin absagen musst, nutze bitte diesen Link:\n{$cancelUrl}\n\n"
    . "Bis bald,\nHelena · LagOHM";

$mailResult = Mailer::send($email, $name, 'Deine Buchung bei LagOHM', $body);
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
