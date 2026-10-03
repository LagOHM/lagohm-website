<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/GoogleCalendar.php';
require_once __DIR__ . '/../lib/CancellationMail.php';

$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
    http_response_code(400);
    echo 'Ungültiger Link.';
    exit;
}

$pdo = lagohm_db();
$stmt = $pdo->prepare('SELECT b.*, s.name AS service_name FROM bookings b
                        JOIN services s ON s.id = b.service_id
                        WHERE b.cancellation_token = ?');
$stmt->execute([$token]);
$booking = $stmt->fetch();

if (!$booking) {
    http_response_code(404);
    echo 'Diese Buchung wurde nicht gefunden.';
    exit;
}

$tz = new DateTimeZone(lagohm_config()['app']['timezone'] ?? 'Europe/Berlin');
$start = new DateTime($booking['start_datetime'], new DateTimeZone('UTC'));
$start->setTimezone($tz);
$dateLabel = $start->format('d.m.Y') . ' um ' . $start->format('H:i') . ' Uhr';

$message = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($booking['status'] === 'cancelled') {
        $message = 'Dieser Termin wurde bereits storniert.';
    } else {
        // "AND status" so a double tap on the button never sends the emails twice.
        $upd = $pdo->prepare('UPDATE bookings SET status = "cancelled" WHERE id = ? AND status = "confirmed"');
        $upd->execute([$booking['id']]);
        if ($upd->rowCount() > 0) {
            $log = $pdo->prepare('INSERT INTO booking_audit_log (booking_id, action, detail) VALUES (?, "cancelled", "via self-service link")');
            $log->execute([$booking['id']]);
            GoogleCalendar::removeBookingEvent((int)$booking['id']);
            $emailed = CancellationMail::send((int)$booking['id'], CancellationMail::BY_CUSTOMER);
        }
        $booking['status'] = 'cancelled';
        $message = 'Dein Termin wurde storniert.'
            . (!empty($emailed) ? ' Eine Bestätigung ist unterwegs an deine E-Mail-Adresse.' : '');
    }
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Termin stornieren – LagOHM</title>
<link rel="stylesheet" href="../css/style.css?v=20261002">
</head>
<body>
<main class="legal-page">
  <div class="wrap" style="max-width:560px;">
    <h1>Termin stornieren</h1>
    <?php if ($message): ?>
      <p><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
      <p><a class="back" href="../index.html">← Zur Startseite</a></p>
    <?php elseif ($booking['status'] === 'cancelled'): ?>
      <p>Dieser Termin ist bereits storniert.</p>
      <p><a class="back" href="../index.html">← Zur Startseite</a></p>
    <?php else: ?>
      <p><strong><?= htmlspecialchars($booking['service_name'], ENT_QUOTES, 'UTF-8') ?></strong><br>
      <?= htmlspecialchars($dateLabel, ENT_QUOTES, 'UTF-8') ?></p>
      <form method="post">
        <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit" class="btn btn-primary">Ja, Termin stornieren</button>
      </form>
    <?php endif; ?>
  </div>
</main>
</body>
</html>
