<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/Auth.php';

lagohm_config();
Auth::requireLogin();

/**
 * Gift voucher generator: fill in the form, check the preview, then "Als PDF speichern"
 * (the browser's print dialog, page size A5 landscape). Nothing is stored; vouchers are
 * sold and tracked by Helena by email.
 */

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function voucher_code(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0/O, 1/I
    $code = '';
    for ($i = 0; $i < 6; $i++) {
        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return 'LAG-' . $code;
}

$tz = new DateTimeZone(lagohm_config()['app']['timezone'] ?? 'Europe/Berlin');

$lang = ($_GET['lang'] ?? 'de') === 'en' ? 'en' : 'de';
$type = in_array($_GET['type'] ?? '', ['massage', 'yoga', 'value'], true) ? $_GET['type'] : 'massage';
$amount = max(0, (int)($_GET['amount'] ?? 60));
$for = trim((string)($_GET['for'] ?? ''));
$from = trim((string)($_GET['from'] ?? ''));
$message = trim((string)($_GET['message'] ?? ''));
$code = preg_match('/^[A-Z0-9-]{4,20}$/', (string)($_GET['code'] ?? '')) ? (string)$_GET['code'] : voucher_code();
$issued = DateTime::createFromFormat('!Y-m-d', (string)($_GET['issued'] ?? ''), $tz) ?: new DateTime('today', $tz);
$validUntil = new DateTime(((int)$issued->format('Y') + 3) . '-12-31', $tz); // 3 years until year end

$months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
$t = $lang === 'en'
    ? [
        'eyebrow' => 'Gift voucher',
        'massage' => ['Massage', '90 minutes · Thai yoga massage & mindful bodywork'],
        'yoga' => ['Private yoga session', '75 minutes · one-on-one, tailored to you'],
        'value' => ['€' . $amount, 'to spend on yoga or massage'],
        'for' => 'For',
        'from' => 'From',
        'valid' => 'Valid until ' . $validUntil->format('j') . ' ' . $months[(int)$validUntil->format('n') - 1] . ' ' . $validUntil->format('Y'),
        'code' => 'Voucher no.',
        'redeem' => 'To redeem, book a session at lagohm.de/en and enter the voucher number in the message field.',
        'fallback' => 'A time-out – just for you.',
    ]
    : [
        'eyebrow' => 'Gutschein',
        'massage' => ['Massage', '90 Minuten · Thai Yoga Massage & achtsame Körperarbeit'],
        'yoga' => ['Private Yoga Session', '75 Minuten · ganz persönlich, auf dich abgestimmt'],
        'value' => [$amount . ' €', 'für Yoga oder Massage'],
        'for' => 'Für',
        'from' => 'Von',
        'valid' => 'Gültig bis ' . $validUntil->format('d.m.Y'),
        'code' => 'Gutschein-Nr.',
        'redeem' => 'Einlösen: Termin auf lagohm.de buchen und die Gutschein-Nr. im Nachrichtenfeld angeben.',
        'fallback' => 'Eine Auszeit – ganz für dich.',
    ];
[$title, $subtitle] = $t[$type];

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Gutschein <?= e($code) ?> – Admin – LagOHM</title>
<link rel="stylesheet" href="../css/style.css?v=20261003-3">
<link rel="stylesheet" href="admin.css?v=20261003-3">
<style>
  .voucher-form{ display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:16px 20px; max-width: 760px; }
  .voucher-form .wide{ grid-column: 1 / -1; }
  .voucher-form select, .voucher-form input[type="number"], .voucher-form input[type="date"], .voucher-form textarea{
    width:100%; margin-top:6px; padding:10px 12px; border:1px solid var(--line); border-radius:10px; font-size:1rem; font-family: var(--sans); background:#fff;
  }
  .voucher-actions{ display:flex; gap:12px; flex-wrap:wrap; margin: 24px 0 32px; }

  /* The voucher itself: A5 landscape (210 × 148 mm) */
  .voucher{
    width: 210mm; height: 148mm; max-width: 100%; aspect-ratio: 210 / 148;
    display:grid; grid-template-columns: 38% 62%;
    background: var(--bg); border-radius: 18px; overflow:hidden;
    box-shadow: 0 18px 50px rgba(43,27,22,.16);
    -webkit-print-color-adjust: exact; print-color-adjust: exact;
  }
  .voucher-side{
    background: linear-gradient(160deg, #7A2A1D, var(--accent-1) 55%, var(--accent-2));
    display:flex; flex-direction:column; align-items:center; justify-content:center; gap: 6mm; padding: 10mm; color:#fff; text-align:center;
  }
  .voucher-side img{ width: 58%; }
  .voucher-body{ padding: 12mm 13mm 10mm; display:flex; flex-direction:column; color: var(--ink); }
  .voucher-main{ flex:1; display:flex; flex-direction:column; justify-content:center; }
  .voucher-eyebrow{ font-size: 3.2mm; letter-spacing: .18em; text-transform: uppercase; color: var(--accent-1); font-weight: 700; }
  .voucher-title{ font-family: var(--serif); font-size: 12mm; line-height: 1.05; margin: 3mm 0 2mm; font-weight: 600; }
  .voucher-subtitle{ font-size: 3.6mm; color: var(--ink-soft); }
  .voucher-names{ margin-top: 7mm; font-size: 4mm; line-height: 1.6; }
  .voucher-names strong{ font-family: var(--serif); font-weight: 600; font-size: 4.6mm; }
  .voucher-message{ margin-top: 4mm; font-family: var(--serif); font-style: italic; font-size: 4.2mm; line-height: 1.45; color: var(--ink); white-space: pre-line; }
  .voucher-foot{ margin-top: auto; padding-top: 5mm; border-top: 1px solid var(--line); font-size: 3mm; color: var(--ink-soft); line-height: 1.55; }
  .voucher-foot b{ color: var(--ink); }

  @page{ size: A5 landscape; margin: 0; }
  @media print{
    body{ background: none; }
    .admin-header, .admin-main > :not(.voucher){ display:none !important; }
    html, body{ margin:0; padding:0; }
    .admin-main.wrap{ padding:0; margin:0; width:auto; max-width:none; }
    .voucher{ border-radius:0; box-shadow:none; width:210mm; height:148mm; }
  }
</style>
</head>
<body>
<header class="admin-header">
  <div class="wrap admin-header-inner">
    <span class="brand-name">LagOHM Admin</span>
    <nav class="admin-nav">
      <a href="index.php">Buchungen</a>
      <a href="availability.php">Verfügbarkeit</a>
      <a href="calendar.php">Kalender</a>
      <a href="voucher.php" class="active">Gutscheine</a>
      <a href="settings.php">Einstellungen</a>
      <a href="logout.php">Logout</a>
    </nav>
  </div>
</header>
<main class="wrap admin-main">
  <h1>Gutschein erstellen</h1>
  <p class="admin-note">Angaben ausfüllen, „Vorschau aktualisieren“, dann „Als PDF speichern“ und das PDF an die Mail hängen. Im Druckfenster als Ziel „Als PDF speichern“ wählen; die Seitengröße A5 quer ist voreingestellt. Gespeichert wird hier nichts – notier dir die Gutschein-Nr., wenn du den Überblick behalten möchtest.</p>

  <form method="get" class="voucher-form">
    <label>Art
      <select name="type">
        <option value="massage" <?= $type === 'massage' ? 'selected' : '' ?>>Massage · 90 Min.</option>
        <option value="yoga" <?= $type === 'yoga' ? 'selected' : '' ?>>Private Yoga Session · 75 Min.</option>
        <option value="value" <?= $type === 'value' ? 'selected' : '' ?>>Wertgutschein</option>
      </select>
    </label>
    <label>Betrag (nur Wertgutschein), €
      <input type="number" name="amount" min="1" step="1" value="<?= (int)$amount ?>">
    </label>
    <label>Sprache
      <select name="lang">
        <option value="de" <?= $lang === 'de' ? 'selected' : '' ?>>Deutsch</option>
        <option value="en" <?= $lang === 'en' ? 'selected' : '' ?>>Englisch</option>
      </select>
    </label>
    <label>Für (Name, optional)
      <input type="text" name="for" maxlength="80" value="<?= e($for) ?>">
    </label>
    <label>Von (optional)
      <input type="text" name="from" maxlength="80" value="<?= e($from) ?>">
    </label>
    <label>Ausstellungsdatum
      <input type="date" name="issued" value="<?= e($issued->format('Y-m-d')) ?>">
    </label>
    <label class="wide">Grußtext (optional)
      <textarea name="message" rows="2" maxlength="300"><?= e($message) ?></textarea>
    </label>
    <label>Gutschein-Nr.
      <input type="text" name="code" maxlength="20" value="<?= e($code) ?>">
    </label>
    <div class="voucher-actions wide">
      <button type="submit" class="btn btn-primary">Vorschau aktualisieren</button>
      <button type="button" class="btn btn-primary" onclick="window.print()">Als PDF speichern</button>
      <a class="btn btn-ghost" href="voucher.php">Neuer Gutschein</a>
    </div>
  </form>

  <div class="voucher">
    <div class="voucher-side">
      <img src="../images/logo-white.svg" alt="LagOHM">
    </div>
    <div class="voucher-body">
      <div class="voucher-main">
      <div class="voucher-eyebrow"><?= e($t['eyebrow']) ?></div>
      <div class="voucher-title"><?= e($title) ?></div>
      <div class="voucher-subtitle"><?= e($subtitle) ?></div>
      <?php if ($for !== '' || $from !== ''): ?>
        <div class="voucher-names">
          <?php if ($for !== ''): ?><?= e($t['for']) ?> <strong><?= e($for) ?></strong><br><?php endif; ?>
          <?php if ($from !== ''): ?><?= e($t['from']) ?> <strong><?= e($from) ?></strong><?php endif; ?>
        </div>
      <?php endif; ?>
      <?php if ($message !== ''): ?>
        <div class="voucher-message"><?= $lang === 'en' ? '“' : '„' ?><?= e($message) ?><?= $lang === 'en' ? '”' : '“' ?></div>
      <?php else: ?>
        <div class="voucher-message"><?= e($t['fallback']) ?></div>
      <?php endif; ?>
      </div>
      <div class="voucher-foot">
        <b><?= e($t['valid']) ?></b> · <?= e($t['code']) ?> <b><?= e($code) ?></b><br>
        <?= e($t['redeem']) ?>
      </div>
    </div>
  </div>
</main>
</body>
</html>
