<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/GoogleCalendar.php';
require_once __DIR__ . '/../lib/CancellationMail.php';

$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
    http_response_code(400);
    echo 'Ungültiger Link. / Invalid link.';
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
    echo 'Diese Buchung wurde nicht gefunden. / This booking was not found.';
    exit;
}

$lang = lagohm_lang($booking['language'] ?? null);
$t = [
    'de' => [
        'title' => 'Termin stornieren',
        'already' => 'Dieser Termin wurde bereits storniert.',
        'done' => 'Dein Termin wurde storniert.',
        'emailed' => ' Eine Bestätigung ist unterwegs an deine E-Mail-Adresse.',
        'button' => 'Ja, Termin stornieren',
        'home' => '← Zur Startseite',
        'homeUrl' => '../index.html',
    ],
    'en' => [
        'title' => 'Cancel appointment',
        'already' => 'This appointment has already been cancelled.',
        'done' => 'Your appointment has been cancelled.',
        'emailed' => ' A confirmation is on its way to your email address.',
        'button' => 'Yes, cancel appointment',
        'home' => '← Back to the homepage',
        'homeUrl' => '../en/',
    ],
][$lang];

$tz = new DateTimeZone(lagohm_config()['app']['timezone'] ?? 'Europe/Berlin');
$start = new DateTime($booking['start_datetime'], new DateTimeZone('UTC'));
$start->setTimezone($tz);
$dateLabel = $lang === 'en'
    ? $start->format('j F Y') . ' at ' . $start->format('H:i')
    : $start->format('d.m.Y') . ' um ' . $start->format('H:i') . ' Uhr';

$message = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($booking['status'] === 'cancelled') {
        $message = $t['already'];
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
        $message = $t['done'] . (!empty($emailed) ? $t['emailed'] : '');
    }
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($t['title'], ENT_QUOTES, 'UTF-8') ?> – LagOHM</title>
<link rel="stylesheet" href="../css/style.css?v=20261004-5">
</head>
<body>
<main class="legal-page">
  <div class="wrap" style="max-width:560px;">
    <h1><?= htmlspecialchars($t['title'], ENT_QUOTES, 'UTF-8') ?></h1>
    <?php if ($message): ?>
      <p><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
      <p><a class="back" href="<?= $t['homeUrl'] ?>"><?= htmlspecialchars($t['home'], ENT_QUOTES, 'UTF-8') ?></a></p>
    <?php elseif ($booking['status'] === 'cancelled'): ?>
      <p><?= htmlspecialchars($t['already'], ENT_QUOTES, 'UTF-8') ?></p>
      <p><a class="back" href="<?= $t['homeUrl'] ?>"><?= htmlspecialchars($t['home'], ENT_QUOTES, 'UTF-8') ?></a></p>
    <?php else: ?>
      <p><strong><?= htmlspecialchars($booking['service_name'], ENT_QUOTES, 'UTF-8') ?></strong><br>
      <?= htmlspecialchars($dateLabel, ENT_QUOTES, 'UTF-8') ?></p>
      <form method="post">
        <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit" class="btn btn-primary"><?= htmlspecialchars($t['button'], ENT_QUOTES, 'UTF-8') ?></button>
      </form>
    <?php endif; ?>
  </div>
</main>
</body>
</html>
