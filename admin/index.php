<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/Csrf.php';
require_once __DIR__ . '/../lib/GoogleCalendar.php';
require_once __DIR__ . '/../lib/CancellationMail.php';

lagohm_config();
Auth::requireLogin();

$pdo = lagohm_db();
$tz = new DateTimeZone(lagohm_config()['app']['timezone'] ?? 'Europe/Berlin');

$message = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_booking_id'])) {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        $message = 'Sitzung abgelaufen, bitte Seite neu laden.';
    } else {
        $id = (int)$_POST['cancel_booking_id'];
        $upd = $pdo->prepare('UPDATE bookings SET status = "cancelled" WHERE id = ? AND status = "confirmed"');
        $upd->execute([$id]);
        if ($upd->rowCount() > 0) {
            $log = $pdo->prepare('INSERT INTO booking_audit_log (booking_id, action, detail) VALUES (?, "cancelled", "via admin dashboard")');
            $log->execute([$id]);
            GoogleCalendar::removeBookingEvent($id);
            $message = CancellationMail::send($id, CancellationMail::BY_ADMIN)
                ? 'Termin storniert. Die Kundin/der Kunde wurde per E-Mail informiert.'
                : 'Termin storniert. (Keine E-Mail verschickt: Termin liegt in der Vergangenheit oder der Versand ist fehlgeschlagen.)';
        }
    }
}

$stmt = $pdo->query('SELECT b.*, s.name AS service_name FROM bookings b
                      JOIN services s ON s.id = b.service_id
                      ORDER BY b.start_datetime DESC
                      LIMIT 200');
$bookings = $stmt->fetchAll();

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Buchungen – Admin – LagOHM</title>
<link rel="stylesheet" href="../css/style.css?v=20261002">
<link rel="stylesheet" href="admin.css?v=20261002">
</head>
<body>
<header class="admin-header">
  <div class="wrap admin-header-inner">
    <span class="brand-name">LagOHM Admin</span>
    <nav class="admin-nav">
      <a href="index.php" class="active">Buchungen</a>
      <a href="availability.php">Verfügbarkeit</a>
      <a href="calendar.php">Kalender</a>
      <a href="settings.php">Einstellungen</a>
      <a href="logout.php">Logout (<?= htmlspecialchars($_SESSION['admin_email'] ?? '', ENT_QUOTES, 'UTF-8') ?>)</a>
    </nav>
  </div>
</header>
<main class="wrap admin-main">
  <h1>Buchungen</h1>
  <p class="admin-note">Hinweis: Stornierungen bitte immer hier vornehmen, nicht direkt im Google Kalender löschen — sonst bleibt der Slot in der Datenbank fälschlich belegt.</p>
  <?php if ($message): ?><p class="admin-success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>

  <table class="admin-table">
    <thead>
      <tr>
        <th>Nr.</th><th>Termin</th><th>Leistung</th><th>Kundin/Kunde</th><th>Kontakt</th><th>Notiz</th><th>Status</th><th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($bookings as $b): ?>
        <?php
          $start = new DateTime($b['start_datetime'], new DateTimeZone('UTC'));
          $start->setTimezone($tz);
        ?>
        <tr class="<?= $b['status'] === 'cancelled' ? 'is-cancelled' : '' ?>">
          <td>#<?= (int)$b['id'] ?></td>
          <td><?= $start->format('d.m.Y H:i') ?></td>
          <td><?= htmlspecialchars($b['service_name'], ENT_QUOTES, 'UTF-8') ?></td>
          <td><?= htmlspecialchars($b['customer_name'], ENT_QUOTES, 'UTF-8') ?></td>
          <td>
            <?= htmlspecialchars($b['customer_email'], ENT_QUOTES, 'UTF-8') ?>
            <?php if ($b['customer_phone']): ?><br><?= htmlspecialchars($b['customer_phone'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?>
          </td>
          <td><?= htmlspecialchars((string)$b['customer_note'], ENT_QUOTES, 'UTF-8') ?></td>
          <td>
            <?= htmlspecialchars($b['status'], ENT_QUOTES, 'UTF-8') ?>
            <?php if ($b['reminder_sent_at'] && $b['status'] === 'confirmed'): ?><br><small>Erinnerung gesendet</small><?php endif; ?>
          </td>
          <td>
            <?php if ($b['status'] === 'confirmed'): ?>
              <form method="post" onsubmit="return confirm('Diesen Termin wirklich stornieren?');">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="cancel_booking_id" value="<?= (int)$b['id'] ?>">
                <button type="submit" class="btn btn-ghost btn-small">Stornieren</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$bookings): ?>
        <tr><td colspan="7">Noch keine Buchungen.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</main>
</body>
</html>
