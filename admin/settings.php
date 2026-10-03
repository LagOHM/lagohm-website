<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/Csrf.php';

lagohm_config();
Auth::requireLogin();

$configPath = __DIR__ . '/../lib/config.php';
$config = require $configPath;

$message = null;
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        $message = 'Sitzung abgelaufen, bitte Seite neu laden.';
        $messageType = 'error';
    } elseif (isset($_POST['address_hint_form'])) {
        $addressHint = trim((string)($_POST['address_hint'] ?? ''));
        $addressHintEn = trim((string)($_POST['address_hint_en'] ?? ''));
        $save = lagohm_db()->prepare('INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        $save->execute(['address_hint', mb_substr($addressHint, 0, 255)]);
        $save->execute(['address_hint_en', mb_substr($addressHintEn, 0, 255)]);
        $message = ($addressHint === '' && $addressHintEn === '') ? 'Hinweis entfernt.' : 'Hinweis gespeichert.';
    } else {
        $config['smtp']['user'] = trim((string)($_POST['smtp_user'] ?? $config['smtp']['user']));
        $config['smtp']['from_email'] = trim((string)($_POST['smtp_from_email'] ?? $config['smtp']['from_email']));
        $config['smtp']['from_name'] = trim((string)($_POST['smtp_from_name'] ?? $config['smtp']['from_name']));

        $newPass = (string)($_POST['smtp_pass'] ?? '');
        if ($newPass !== '') {
            $config['smtp']['pass'] = $newPass;
        }

        $php = "<?php\nreturn " . var_export($config, true) . ";\n";
        file_put_contents($configPath, $php);

        require_once __DIR__ . '/../lib/Mailer.php';
        $testTo = trim((string)($_POST['test_email'] ?? ''));
        if ($testTo !== '' && filter_var($testTo, FILTER_VALIDATE_EMAIL)) {
            $result = Mailer::send($testTo, 'Test', 'LagOHM – SMTP Test', 'Das ist eine Testmail von der Einstellungen-Seite. Wenn du das liest, funktioniert der Mailversand.');
            if ($result === true) {
                $message = 'Gespeichert — Testmail wurde erfolgreich an ' . htmlspecialchars($testTo, ENT_QUOTES, 'UTF-8') . ' verschickt!';
            } else {
                $message = 'Gespeichert, aber die Testmail ist fehlgeschlagen: ' . htmlspecialchars((string)$result, ENT_QUOTES, 'UTF-8');
                $messageType = 'error';
            }
        } else {
            $message = 'Gespeichert.';
        }
    }
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Einstellungen – Admin – LagOHM</title>
<link rel="stylesheet" href="../css/style.css?v=20261003-3">
<link rel="stylesheet" href="admin.css?v=20261003-3">
</head>
<body>
<header class="admin-header">
  <div class="wrap admin-header-inner">
    <span class="brand-name">LagOHM Admin</span>
    <nav class="admin-nav">
      <a href="index.php">Buchungen</a>
      <a href="availability.php">Verfügbarkeit</a>
      <a href="calendar.php">Kalender</a>
      <a href="voucher.php">Gutscheine</a>
      <a href="settings.php" class="active">Einstellungen</a>
      <a href="logout.php">Logout</a>
    </nav>
  </div>
</header>
<main class="wrap admin-main">
  <h1>E-Mail-Versand (SMTP)</h1>
  <p class="admin-note">Hier trägst du das Passwort deines <code>helena@lagohm.de</code>-Postfachs ein, über das Buchungsbestätigungen verschickt werden. Das Feld leer lassen, um das aktuell gespeicherte Passwort zu behalten.</p>

  <?php if ($message): ?>
    <p class="<?= $messageType === 'error' ? 'admin-error' : 'admin-success' ?>"><?= $message /* already escaped above */ ?></p>
  <?php endif; ?>

  <form method="post" style="max-width:480px;">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES, 'UTF-8') ?>">

    <label>SMTP-Benutzername<br>
      <input type="text" name="smtp_user" value="<?= htmlspecialchars($config['smtp']['user'], ENT_QUOTES, 'UTF-8') ?>">
    </label>
    <br><br>
    <label>Neues SMTP-Passwort (leer lassen = unverändert)<br>
      <input type="password" name="smtp_pass" autocomplete="new-password">
    </label>
    <br><br>
    <label>Absender-E-Mail<br>
      <input type="text" name="smtp_from_email" value="<?= htmlspecialchars($config['smtp']['from_email'], ENT_QUOTES, 'UTF-8') ?>">
    </label>
    <br><br>
    <label>Absender-Name<br>
      <input type="text" name="smtp_from_name" value="<?= htmlspecialchars($config['smtp']['from_name'], ENT_QUOTES, 'UTF-8') ?>">
    </label>
    <br><br>
    <label>Test-Mail an (optional, schickt beim Speichern direkt eine Testmail)<br>
      <input type="text" name="test_email" placeholder="deine@email.de">
    </label>
    <br><br>
    <button type="submit" class="btn btn-primary">Speichern</button>
  </form>

  <h1 style="margin-top:48px;">Hinweis zur Adresse</h1>
  <p class="admin-note">Steht in Buchungsbestätigung, Erinnerung und Kalendereintrag direkt unter der Adresse. Feld leeren und speichern, um den Hinweis zu entfernen.</p>
  <form method="post" style="max-width:480px;">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="address_hint_form" value="1">
    <label>Hinweis (z. B. Klingel)<br>
      <input type="text" name="address_hint" maxlength="255" value="<?= htmlspecialchars($addressHint ?? lagohm_address_hint(), ENT_QUOTES, 'UTF-8') ?>">
    </label>
    <br><br>
    <label>Englisch (für Buchungen über die englische Seite)<br>
      <input type="text" name="address_hint_en" maxlength="255" value="<?= htmlspecialchars($addressHintEn ?? lagohm_address_hint('en'), ENT_QUOTES, 'UTF-8') ?>">
    </label>
    <br><br>
    <button type="submit" class="btn btn-primary">Hinweis speichern</button>
  </form>
</main>
</body>
</html>
