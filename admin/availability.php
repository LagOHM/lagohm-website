<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/Csrf.php';

lagohm_config();
Auth::requireLogin();

$pdo = lagohm_db();

$weekdayLabels = [0 => 'Sonntag', 1 => 'Montag', 2 => 'Dienstag', 3 => 'Mittwoch', 4 => 'Donnerstag', 5 => 'Freitag', 6 => 'Samstag'];
$message = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        $message = 'Sitzung abgelaufen, bitte Seite neu laden.';
    } else {
        $pdo->beginTransaction();
        try {
            for ($weekday = 0; $weekday <= 6; $weekday++) {
                $del = $pdo->prepare('DELETE FROM availability_templates WHERE weekday = ?');
                $del->execute([$weekday]);

                $active = !empty($_POST["active_{$weekday}"]);
                if ($active) {
                    $start = (string)($_POST["start_{$weekday}"] ?? '09:00');
                    $end = (string)($_POST["end_{$weekday}"] ?? '19:00');
                    if (preg_match('/^\d{2}:\d{2}$/', $start) && preg_match('/^\d{2}:\d{2}$/', $end) && $start < $end) {
                        $ins = $pdo->prepare('INSERT INTO availability_templates (weekday, start_time, end_time, active) VALUES (?, ?, ?, 1)');
                        $ins->execute([$weekday, $start . ':00', $end . ':00']);
                    }
                }
            }
            $pdo->commit();
            $message = 'Verfügbarkeiten gespeichert.';
        } catch (Throwable $e) {
            $pdo->rollBack();
            $message = 'Fehler beim Speichern.';
        }
    }
}

$stmt = $pdo->query('SELECT weekday, start_time, end_time FROM availability_templates WHERE active = 1');
$current = [];
foreach ($stmt->fetchAll() as $row) {
    $current[(int)$row['weekday']] = $row;
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Verfügbarkeit – Admin – LagOHM</title>
<link rel="stylesheet" href="../css/style.css?v=20261005-3">
<link rel="stylesheet" href="admin.css?v=20261005-3">
</head>
<body>
<header class="admin-header">
  <div class="wrap admin-header-inner">
    <span class="brand-name">LagOHM Admin</span>
    <nav class="admin-nav">
      <a href="index.php">Buchungen</a>
      <a href="availability.php" class="active">Verfügbarkeit</a>
      <a href="calendar.php">Kalender</a>
      <a href="vouchers.php">Gutscheine</a>
      <a href="settings.php">Einstellungen</a>
      <a href="logout.php">Logout</a>
    </nav>
  </div>
</header>
<main class="wrap admin-main">
  <h1>Wöchentliche Verfügbarkeit</h1>
  <p class="admin-note">Das sind deine grundsätzlichen Öffnungszeiten für Buchungen. Dein privater Google Kalender blockt zusätzlich automatisch Zeiten, in denen du schon etwas anderes eingetragen hast (sofern unter „Kalender“ verbunden).</p>
  <?php if ($message): ?><p class="admin-success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>

  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES, 'UTF-8') ?>">
    <table class="admin-table">
      <thead><tr><th>Tag</th><th>Geöffnet</th><th>Von</th><th>Bis</th></tr></thead>
      <tbody>
        <?php foreach ([1, 2, 3, 4, 5, 6, 0] as $weekday): ?>
          <?php $row = $current[$weekday] ?? null; ?>
          <tr>
            <td><?= $weekdayLabels[$weekday] ?></td>
            <td><input type="checkbox" name="active_<?= $weekday ?>" <?= $row ? 'checked' : '' ?>></td>
            <td><input type="time" name="start_<?= $weekday ?>" value="<?= $row ? substr($row['start_time'], 0, 5) : '09:00' ?>"></td>
            <td><input type="time" name="end_<?= $weekday ?>" value="<?= $row ? substr($row['end_time'], 0, 5) : '19:00' ?>"></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <button type="submit" class="btn btn-primary">Speichern</button>
  </form>
</main>
</body>
</html>
