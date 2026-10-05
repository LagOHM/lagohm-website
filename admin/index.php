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

// Mark an appointment that has taken place as done, or undo that.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['complete_booking_id']) || isset($_POST['reopen_booking_id']))) {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        $message = 'Sitzung abgelaufen, bitte Seite neu laden.';
    } else {
        $complete = isset($_POST['complete_booking_id']);
        $id = (int)($complete ? $_POST['complete_booking_id'] : $_POST['reopen_booking_id']);
        $upd = $complete
            ? $pdo->prepare('UPDATE bookings SET status = "completed" WHERE id = ? AND status = "confirmed" AND start_datetime <= UTC_TIMESTAMP()')
            : $pdo->prepare('UPDATE bookings SET status = "confirmed" WHERE id = ? AND status = "completed"');
        $upd->execute([$id]);
        if ($upd->rowCount() > 0) {
            $log = $pdo->prepare('INSERT INTO booking_audit_log (booking_id, action, detail) VALUES (?, ?, "via admin dashboard")');
            $log->execute([$id, $complete ? 'completed' : 'reopened']);
            $message = $complete ? "Termin #{$id} als erledigt markiert." : "Termin #{$id} wieder als offen markiert.";
        }
    }
}

// Filter tabs: "Offen" (confirmed, upcoming or not yet marked done) is the default view.
$filters = [
    'open' => ['Offen', 'confirmed'],
    'done' => ['Erledigt', 'completed'],
    'cancelled' => ['Storniert', 'cancelled'],
    'all' => ['Alle', null],
];
$show = is_string($_GET['show'] ?? null) && isset($filters[$_GET['show']]) ? $_GET['show'] : 'open';
$counts = $pdo->query('SELECT status, COUNT(*) AS n FROM bookings GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
$counts['all'] = array_sum($counts);

$filterStatus = $filters[$show][1];
$stmt = $pdo->prepare('SELECT b.*, s.name AS service_name FROM bookings b
                        JOIN services s ON s.id = b.service_id'
                        . ($filterStatus ? ' WHERE b.status = ?' : '') . '
                        ORDER BY b.start_datetime DESC
                        LIMIT 200');
$stmt->execute($filterStatus ? [$filterStatus] : []);
$bookings = $stmt->fetchAll();
$nowUtc = new DateTime('now', new DateTimeZone('UTC'));
$statusLabels = ['confirmed' => 'bestätigt', 'completed' => 'erledigt', 'cancelled' => 'storniert'];

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Buchungen – Admin – LagOHM</title>
<link rel="stylesheet" href="../css/style.css?v=20261005-3">
<link rel="stylesheet" href="admin.css?v=20261005-3">
</head>
<body>
<header class="admin-header">
  <div class="wrap admin-header-inner">
    <span class="brand-name">LagOHM Admin</span>
    <nav class="admin-nav">
      <a href="index.php" class="active">Buchungen</a>
      <a href="availability.php">Verfügbarkeit</a>
      <a href="calendar.php">Kalender</a>
      <a href="vouchers.php">Gutscheine</a>
      <a href="settings.php">Einstellungen</a>
      <a href="logout.php">Logout (<?= htmlspecialchars($_SESSION['admin_email'] ?? '', ENT_QUOTES, 'UTF-8') ?>)</a>
    </nav>
  </div>
</header>
<main class="wrap admin-main">
  <h1>Buchungen</h1>
  <p class="admin-note">Hinweis: Stornierungen bitte immer hier vornehmen, nicht direkt im Google Kalender löschen — sonst bleibt der Slot in der Datenbank fälschlich belegt.</p>
  <?php if ($message): ?><p class="admin-success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>

  <nav class="admin-filter">
    <?php foreach ($filters as $key => [$label, $status]): ?>
      <a href="?show=<?= $key ?>" class="<?= $key === $show ? 'active' : '' ?>"><?= $label ?> <span><?= (int)($counts[$status ?? 'all'] ?? 0) ?></span></a>
    <?php endforeach; ?>
  </nav>

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
          $hasStarted = $start <= $nowUtc;
          $start->setTimezone($tz);
        ?>
        <tr class="<?= ['cancelled' => 'is-cancelled', 'completed' => 'is-completed'][$b['status']] ?? '' ?>">
          <td>#<?= (int)$b['id'] ?></td>
          <td><?= $start->format('d.m.Y H:i') ?></td>
          <td><?= htmlspecialchars($b['service_name'], ENT_QUOTES, 'UTF-8') ?></td>
          <td>
            <?= htmlspecialchars($b['customer_name'], ENT_QUOTES, 'UTF-8') ?>
            <?php if (($b['language'] ?? 'de') === 'en'): ?><br><small>Englisch</small><?php endif; ?>
          </td>
          <td>
            <?= htmlspecialchars($b['customer_email'], ENT_QUOTES, 'UTF-8') ?>
            <?php if ($b['customer_phone']): ?><br><?= htmlspecialchars($b['customer_phone'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?>
          </td>
          <td><?= htmlspecialchars((string)$b['customer_note'], ENT_QUOTES, 'UTF-8') ?></td>
          <td>
            <?= htmlspecialchars($statusLabels[$b['status']] ?? $b['status'], ENT_QUOTES, 'UTF-8') ?>
            <?php if ($b['reminder_sent_at'] && $b['status'] === 'confirmed'): ?><br><small>Erinnerung gesendet</small><?php endif; ?>
          </td>
          <td class="admin-actions">
            <?php if ($b['status'] === 'confirmed' && $hasStarted): ?>
              <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="complete_booking_id" value="<?= (int)$b['id'] ?>">
                <button type="submit" class="btn btn-primary btn-small">Erledigt</button>
              </form>
            <?php elseif ($b['status'] === 'completed'): ?>
              <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="reopen_booking_id" value="<?= (int)$b['id'] ?>">
                <button type="submit" class="btn btn-ghost btn-small">Rückgängig</button>
              </form>
            <?php endif; ?>
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
        <tr><td colspan="8">Keine Buchungen in dieser Ansicht.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
  <p class="admin-note">Die Liste aktualisiert sich automatisch jede Minute, solange die Seite sichtbar ist. Zuletzt aktualisiert: <?= (new DateTime('now', $tz))->format('H:i') ?> Uhr</p>
</main>
<script>
// Auto-refresh while visible; a hidden tab pauses (so the 2h idle logout still applies)
// and refreshes as soon as it is shown again. GET via replace(): never re-submits a cancel form.
(function () {
  var INTERVAL = 60 * 1000;
  var loadedAt = Date.now();
  function refreshIfDue() {
    if (document.visibilityState === 'visible' && Date.now() - loadedAt >= INTERVAL) {
      window.location.replace('index.php' + window.location.search);
    }
  }
  setInterval(refreshIfDue, 5000);
  document.addEventListener('visibilitychange', refreshIfDue);
})();
</script>
</body>
</html>
