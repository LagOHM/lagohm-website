<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/Csrf.php';
require_once __DIR__ . '/../lib/GoogleCalendar.php';

lagohm_config();
Auth::requireLogin();

$configPath = __DIR__ . '/../lib/config.php';

function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['calendar_flash'] = ['error', 'Sitzung abgelaufen, bitte Seite neu laden.'];
    } elseif ($action === 'save_credentials') {
        $config = require $configPath;
        $clientId = trim((string)($_POST['client_id'] ?? ''));
        $clientSecret = trim((string)($_POST['client_secret'] ?? ''));
        if ($clientId !== '') {
            $config['google']['client_id'] = $clientId;
        }
        if ($clientSecret !== '') {
            $config['google']['client_secret'] = $clientSecret;
        }
        $config['google']['redirect_uri'] = rtrim($config['app']['base_url'], '/') . '/admin/oauth-callback.php';
        file_put_contents($configPath, "<?php\nreturn " . var_export($config, true) . ";\n");
        $_SESSION['calendar_flash'] = ['success', 'Google-Zugangsdaten gespeichert.'];
    } elseif ($action === 'disconnect') {
        GoogleCalendar::disconnect();
        $_SESSION['calendar_flash'] = ['success', 'Google Kalender getrennt. Buchungen laufen jetzt nur noch über deine Öffnungszeiten.'];
    } elseif ($action === 'set_bookings_calendar') {
        $id = trim((string)($_POST['bookings_calendar'] ?? ''));
        GoogleCalendar::setBookingsCalendar($id === '' ? null : substr($id, 0, 255));
        $_SESSION['calendar_flash'] = ['success', 'Buchungskalender gespeichert.'];
    }
    // Post/Redirect/Get, also so the freshly written config.php is loaded.
    header('Location: calendar.php');
    exit;
}

$flash = $_SESSION['calendar_flash'] ?? null;
unset($_SESSION['calendar_flash']);

$config = lagohm_config();
$configured = GoogleCalendar::isConfigured();
$connected = GoogleCalendar::isConnected();
$row = $connected ? GoogleCalendar::getRow() : null;
$redirectUri = rtrim($config['app']['base_url'], '/') . '/admin/oauth-callback.php';
$clientIdSet = ($config['google']['client_id'] ?? 'CHANGE_ME') !== 'CHANGE_ME' && ($config['google']['client_id'] ?? '') !== '';

// Health check: if connected, ask Google for the next 7 days.
$calendars = [];
$healthError = null;
$busyCount = null;
if ($connected) {
    try {
        $calendars = GoogleCalendar::listCalendars();
        $now = new DateTime('now', new DateTimeZone('UTC'));
        $busyCount = count(GoogleCalendar::busyIntervals($now, (clone $now)->modify('+7 days')));
    } catch (Throwable $ex) {
        $healthError = $ex->getMessage();
    }
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Kalender – Admin – LagOHM</title>
<link rel="stylesheet" href="../css/style.css?v=20261002">
<link rel="stylesheet" href="admin.css?v=20261002">
</head>
<body>
<header class="admin-header">
  <div class="wrap admin-header-inner">
    <span class="brand-name">LagOHM Admin</span>
    <nav class="admin-nav">
      <a href="index.php">Buchungen</a>
      <a href="availability.php">Verfügbarkeit</a>
      <a href="calendar.php" class="active">Kalender</a>
      <a href="settings.php">Einstellungen</a>
      <a href="logout.php">Logout</a>
    </nav>
  </div>
</header>
<main class="wrap admin-main">
  <h1>Google Kalender</h1>
  <p class="admin-note">Ist dein Google Kalender verbunden, blockt jeder Eintrag darin (z.&nbsp;B. private Termine) automatisch die Buchungszeiten auf der Website, und jede neue Buchung erscheint als Termin in deinem Kalender. Termine, die du in Google als „Verfügbar“ markierst, blocken nicht.</p>

  <?php if ($flash): ?>
    <p class="<?= $flash[0] === 'error' ? 'admin-error' : 'admin-success' ?>"><?= e($flash[1]) ?></p>
  <?php endif; ?>

  <section class="admin-section">
    <h2>Status</h2>
    <?php if (!$configured): ?>
      <p>Noch nicht eingerichtet — zuerst unten die Google-Zugangsdaten eintragen (Anleitung in <code>SETUP.md</code>, Phase 3).</p>
    <?php elseif (!$connected): ?>
      <p>Nicht verbunden.</p>
      <p><a class="btn btn-primary" href="google-connect.php">Mit Google Kalender verbinden</a></p>
    <?php else: ?>
      <?php if ($healthError): ?>
        <p class="admin-error">Verbunden, aber Google antwortet gerade nicht: <?= e($healthError) ?></p>
        <p>Solange das so ist, zeigt die Website keine freien Termine an. Falls der Zugriff widerrufen wurde:</p>
        <p><a class="btn btn-primary" href="google-connect.php">Neu verbinden</a></p>
      <?php else: ?>
        <p class="admin-success">Verbunden ✓ — <?= (int)$busyCount ?> belegte Zeitblöcke in den nächsten 7 Tagen gefunden.</p>
      <?php endif; ?>
      <form method="post" onsubmit="return confirm('Google Kalender wirklich trennen? Private Termine blocken dann keine Buchungen mehr.');">
        <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
        <input type="hidden" name="action" value="disconnect">
        <button type="submit" class="btn btn-ghost btn-small">Verbindung trennen</button>
      </form>
    <?php endif; ?>
  </section>

  <?php if ($connected && !$healthError): ?>
    <section class="admin-section">
      <h2>Buchungskalender</h2>
      <p class="admin-note">In welchen Kalender sollen neue Buchungen eingetragen werden? Empfohlen: ein eigener Kalender „LagOHM Termine“ (in Google Kalender links bei „Weitere Kalender“ → „+“ → „Neuen Kalender erstellen“), dann hier auswählen. Dein Hauptkalender und dieser Kalender blocken beide Buchungszeiten.</p>
      <form method="post" style="max-width:480px;">
        <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
        <input type="hidden" name="action" value="set_bookings_calendar">
        <label>Kalender<br>
          <select name="bookings_calendar">
            <option value="">Hauptkalender</option>
            <?php foreach ($calendars as $cal): ?>
              <?php if ($cal['primary'] || !in_array($cal['accessRole'], ['owner', 'writer'], true)) continue; ?>
              <option value="<?= e($cal['id']) ?>" <?= ($row['calendar_id_bookings'] ?? '') === $cal['id'] ? 'selected' : '' ?>><?= e($cal['summary']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <br>
        <button type="submit" class="btn btn-primary">Speichern</button>
      </form>
    </section>
  <?php endif; ?>

  <section class="admin-section">
    <h2>Google-Zugangsdaten</h2>
    <p class="admin-note">Client-ID und Client-Secret aus der Google Cloud Console. Als „Autorisierte Weiterleitungs-URI“ dort genau diese Adresse eintragen:<br><code><?= e($redirectUri) ?></code><br>Felder leer lassen = gespeicherten Wert behalten.</p>
    <form method="post" style="max-width:480px;">
      <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
      <input type="hidden" name="action" value="save_credentials">
      <label>Client-ID<?= $clientIdSet ? ' (gespeichert)' : '' ?><br>
        <input type="text" name="client_id" placeholder="<?= $clientIdSet ? e($config['google']['client_id']) : '…apps.googleusercontent.com' ?>">
      </label>
      <br>
      <label>Client-Secret<?= $configured ? ' (gespeichert)' : '' ?><br>
        <input type="password" name="client_secret" autocomplete="new-password">
      </label>
      <br>
      <button type="submit" class="btn btn-primary">Speichern</button>
    </form>
  </section>
</main>
</body>
</html>
