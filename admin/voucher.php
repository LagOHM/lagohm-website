<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/Auth.php';

lagohm_config();
Auth::requireLogin();

require_once __DIR__ . '/../lib/Csrf.php';

/**
 * Gift voucher generator: fill in the form, check the preview, then "Speichern & PDF erstellen"
 * stores the voucher in the register (vouchers.php) and opens the print dialog (A4 landscape).
 * Names and the greeting are only rendered into the PDF, never stored. The form is POSTed so
 * they don't end up in URLs or server logs either.
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

$pdo = lagohm_db();
$in = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : [];
$notice = null;
$error = null;
$autoPrint = false;

// Reprint from the register: start from the stored voucher (names have to be typed in again).
if (!$in && isset($_GET['reprint'])) {
    $stmt = $pdo->prepare('SELECT * FROM vouchers WHERE id = ?');
    $stmt->execute([(int)$_GET['reprint']]);
    if ($v = $stmt->fetch()) {
        $in = [
            'type' => $v['type'], 'lang' => $v['language'], 'code' => $v['code'], 'issued' => $v['issued_on'],
            'amount' => $v['amount_cents'] !== null ? (int)($v['amount_cents'] / 100) : 60,
        ];
        $notice = 'Nachdruck von ' . $v['code'] . '. Namen und Grußtext bei Bedarf neu eintragen.';
    }
}

$lang = ($in['lang'] ?? 'de') === 'en' ? 'en' : 'de';
$type = in_array($in['type'] ?? '', ['massage', 'yoga', 'value'], true) ? $in['type'] : 'massage';
$amount = max(0, (int)($in['amount'] ?? 60));
$for = trim((string)($in['for'] ?? ''));
$from = trim((string)($in['from'] ?? ''));
$message = trim((string)($in['message'] ?? ''));
$code = preg_match('/^[A-Z0-9-]{4,20}$/', (string)($in['code'] ?? '')) ? (string)$in['code'] : voucher_code();
$issued = DateTime::createFromFormat('!Y-m-d', (string)($in['issued'] ?? ''), $tz) ?: new DateTime('today', $tz);
$validUntil = new DateTime(((int)$issued->format('Y') + 3) . '-12-31', $tz); // 3 years until year end

if (($in['action'] ?? '') === 'save') {
    if (!Csrf::verify($in['csrf_token'] ?? null)) {
        $error = 'Sitzung abgelaufen, bitte Seite neu laden.';
    } elseif ($type === 'value' && $amount < 1) {
        $error = 'Bitte einen Betrag für den Wertgutschein eintragen.';
    } else {
        $exists = $pdo->prepare('SELECT id FROM vouchers WHERE code = ?');
        $exists->execute([$code]);
        if ($exists->fetch()) {
            $notice = 'Gutschein ' . $code . ' ist bereits in der Liste gespeichert.';
        } else {
            $pdo->prepare('INSERT INTO vouchers (code, type, amount_cents, language, issued_on, valid_until) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$code, $type, $type === 'value' ? $amount * 100 : null, $lang, $issued->format('Y-m-d'), $validUntil->format('Y-m-d')]);
            $notice = 'Gutschein ' . $code . ' gespeichert ✓ – im Druckfenster „Als PDF speichern“ wählen.';
        }
        $autoPrint = true;
    }
}

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
        'nocash' => 'No cash refund – any remaining balance can be used for further sessions until the voucher expires.',
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
        'nocash' => 'Keine Barauszahlung – ein Restbetrag kann bis zum Ablauf für weitere Termine genutzt werden.',
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
<link rel="stylesheet" href="../css/style.css?v=20261004-4">
<link rel="stylesheet" href="admin.css?v=20261004-4">
<style>
  .voucher-form{ display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:16px 20px; max-width: 760px; }
  .voucher-form .wide{ grid-column: 1 / -1; }
  .voucher-form select, .voucher-form input[type="number"], .voucher-form input[type="date"], .voucher-form textarea{
    width:100%; margin-top:6px; padding:10px 12px; border:1px solid var(--line); border-radius:10px; font-size:1rem; font-family: var(--sans); background:#fff;
  }
  .voucher-actions{ display:flex; gap:12px; flex-wrap:wrap; margin: 24px 0 32px; }

  /* The voucher: an A4 landscape page (297 × 210 mm) with a white margin around the card,
     so home printers (which can't print to the edge) need no scaling and no cutting. */
  .voucher-scroll{ overflow-x:auto; padding-bottom: 8px; }
  .voucher-page{
    width: 297mm; height: 210mm; padding: 10mm; box-sizing: border-box;
    background: #fff; box-shadow: 0 18px 50px rgba(43,27,22,.16);
  }
  .voucher{
    width: 100%; height: 100%;
    display:grid; grid-template-columns: 38% 62%;
    background: var(--bg); border-radius: 6mm; overflow:hidden;
    -webkit-print-color-adjust: exact; print-color-adjust: exact;
  }
  .voucher-side{
    background: linear-gradient(160deg, #7A2A1D, var(--accent-1) 55%, var(--accent-2));
    display:flex; flex-direction:column; align-items:center; justify-content:center; padding: 13mm; color:#fff; text-align:center;
  }
  .voucher-side img{ width: 46%; }
  .voucher-word{ font-family: var(--serif); font-weight: 600; font-size: 13mm; line-height: 1; margin-top: 8mm; letter-spacing: -.2mm; }
  .voucher-word span{ color: #FBD3B4; }
  .voucher-body{ padding: 16mm 17mm 13mm; display:flex; flex-direction:column; color: var(--ink); }
  .voucher-main{ flex:1; display:flex; flex-direction:column; justify-content:center; }
  .voucher-eyebrow{ font-size: 4.2mm; letter-spacing: .18em; text-transform: uppercase; color: var(--accent-1); font-weight: 700; }
  .voucher-title{ font-family: var(--serif); font-size: 16mm; line-height: 1.05; margin: 4mm 0 2.6mm; font-weight: 600; }
  .voucher-subtitle{ font-size: 4.7mm; color: var(--ink-soft); }
  .voucher-names{ margin-top: 9mm; font-size: 5.2mm; line-height: 1.6; }
  .voucher-names strong{ font-family: var(--serif); font-weight: 600; font-size: 6mm; }
  .voucher-message{ margin-top: 5mm; font-family: var(--serif); font-style: italic; font-size: 5.5mm; line-height: 1.45; color: var(--ink); white-space: pre-line; }
  .voucher-foot{ margin-top: auto; padding-top: 6.5mm; border-top: 1px solid var(--line); font-size: 3.9mm; color: var(--ink-soft); line-height: 1.55; }
  .voucher-foot b{ color: var(--ink); }

  @page{ size: A4 landscape; margin: 0; }
  @media print{
    body{ background: #fff; }
    .admin-header, .admin-main > :not(.voucher-scroll){ display:none !important; }
    html, body{ margin:0; padding:0; }
    .admin-main.wrap{ padding:0; margin:0; width:auto; max-width:none; }
    .voucher-scroll{ overflow:visible; padding:0; }
    .voucher-page{ box-shadow:none; }
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
      <a href="vouchers.php" class="active">Gutscheine</a>
      <a href="settings.php">Einstellungen</a>
      <a href="logout.php">Logout</a>
    </nav>
  </div>
</header>
<main class="wrap admin-main">
  <p><a class="back" href="vouchers.php">← Zur Gutschein-Liste</a></p>
  <h1>Gutschein erstellen</h1>
  <p class="admin-note">Angaben ausfüllen, „Vorschau aktualisieren“, dann „Speichern &amp; PDF erstellen“: Der Gutschein kommt in die Liste, und das Druckfenster öffnet sich – dort als Ziel „Als PDF speichern“ wählen (A4 quer, Ränder: keine, Hintergrundgrafiken an). In der Liste werden nur Nummer, Art, Betrag und Daten gespeichert; Namen und Grußtext stehen nur im PDF.</p>
  <?php if ($error): ?><p class="admin-error"><?= e($error) ?></p><?php endif; ?>
  <?php if ($notice): ?><p class="admin-success"><?= e($notice) ?></p><?php endif; ?>

  <form method="post" class="voucher-form">
    <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
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
      <button type="submit" name="action" value="preview" class="btn btn-ghost">Vorschau aktualisieren</button>
      <button type="submit" name="action" value="save" class="btn btn-primary">Speichern &amp; PDF erstellen</button>
      <a class="btn btn-ghost" href="voucher.php">Neuer Gutschein</a>
    </div>
  </form>

  <div class="voucher-scroll"><div class="voucher-page">
  <div class="voucher">
    <div class="voucher-side">
      <img src="../images/icon-mark-white.svg" alt="">
      <div class="voucher-word">LagOHM<span>.</span></div>
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
        <b><?= e($t['valid']) ?></b><br>
        <?= e($t['code']) ?> <b><?= e($code) ?></b><br>
        <?= e($t['redeem']) ?>
        <?php if ($type === 'value'): ?><br><?= e($t['nocash']) ?><?php endif; ?>
      </div>
    </div>
  </div>
  </div></div>
</main>
<?php if ($autoPrint): ?>
<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 300); });</script>
<?php endif; ?>
</body>
</html>
