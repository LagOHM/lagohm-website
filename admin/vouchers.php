<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/Csrf.php';

lagohm_config();
Auth::requireLogin();

/**
 * Register of issued gift vouchers: status, remaining balance, redeem, reprint, delete.
 * Stores no personal data (see voucher.php).
 */

function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function euro(int $cents): string
{
    return number_format($cents / 100, $cents % 100 === 0 ? 0 : 2, ',', '.') . ' €';
}

$pdo = lagohm_db();
$tz = new DateTimeZone(lagohm_config()['app']['timezone'] ?? 'Europe/Berlin');
$today = (new DateTime('today', $tz))->format('Y-m-d');
$message = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        $error = 'Sitzung abgelaufen, bitte Seite neu laden.';
    } else {
        $id = (int)($_POST['voucher_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT v.*, COALESCE(SUM(r.amount_cents), 0) AS used_cents, COUNT(r.id) AS redemptions
                               FROM vouchers v LEFT JOIN voucher_redemptions r ON r.voucher_id = v.id
                               WHERE v.id = ? GROUP BY v.id');
        $stmt->execute([$id]);
        $v = $stmt->fetch();
        $action = (string)($_POST['action'] ?? '');

        if (!$v) {
            $error = 'Gutschein nicht gefunden.';
        } elseif ($action === 'redeem') {
            if ($v['valid_until'] < $today) {
                $error = $v['code'] . ' ist abgelaufen (gültig bis ' . (new DateTime($v['valid_until']))->format('d.m.Y') . ').';
            } elseif ($v['type'] === 'value') {
                $remaining = (int)$v['amount_cents'] - (int)$v['used_cents'];
                $cents = (int)round((float)str_replace(',', '.', (string)($_POST['amount'] ?? '0')) * 100);
                if ($cents < 1) {
                    $error = 'Bitte den eingelösten Betrag eintragen.';
                } elseif ($cents > $remaining) {
                    $error = 'Auf ' . $v['code'] . ' sind nur noch ' . euro($remaining) . ' übrig.';
                } else {
                    $pdo->prepare('INSERT INTO voucher_redemptions (voucher_id, amount_cents) VALUES (?, ?)')->execute([$id, $cents]);
                    $left = $remaining - $cents;
                    $message = $v['code'] . ': ' . euro($cents) . ' eingelöst' . ($left > 0 ? ', Rest ' . euro($left) . '.' : ', vollständig eingelöst.');
                }
            } elseif ((int)$v['redemptions'] > 0) {
                $error = $v['code'] . ' wurde bereits eingelöst.';
            } else {
                $pdo->prepare('INSERT INTO voucher_redemptions (voucher_id, amount_cents) VALUES (?, NULL)')->execute([$id]);
                $message = $v['code'] . ' eingelöst ✓';
            }
        } elseif ($action === 'undo') {
            $pdo->prepare('DELETE FROM voucher_redemptions WHERE voucher_id = ? ORDER BY id DESC LIMIT 1')->execute([$id]);
            $message = 'Letzte Einlösung von ' . $v['code'] . ' rückgängig gemacht.';
        } elseif ($action === 'delete') {
            $pdo->prepare('DELETE FROM vouchers WHERE id = ?')->execute([$id]);
            $message = $v['code'] . ' gelöscht.';
        }
    }
    // Post/Redirect/Get: a reload must never redeem twice.
    $_SESSION['voucher_flash'] = ['message' => $message, 'error' => $error];
    header('Location: vouchers.php' . (isset($_GET['q']) ? '?q=' . rawurlencode((string)$_GET['q']) : ''), true, 303);
    exit;
}
if (isset($_SESSION['voucher_flash'])) {
    ['message' => $message, 'error' => $error] = $_SESSION['voucher_flash'];
    unset($_SESSION['voucher_flash']);
}

$q = strtoupper(trim((string)($_GET['q'] ?? '')));
$sql = 'SELECT v.*, COALESCE(SUM(r.amount_cents), 0) AS used_cents, COUNT(r.id) AS redemptions, MAX(r.redeemed_at) AS last_redeemed
        FROM vouchers v LEFT JOIN voucher_redemptions r ON r.voucher_id = v.id';
$params = [];
if ($q !== '') {
    $sql .= ' WHERE v.code LIKE ?';
    $params[] = '%' . $q . '%';
}
$sql .= ' GROUP BY v.id ORDER BY v.issued_on DESC, v.id DESC LIMIT 500';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$vouchers = $stmt->fetchAll();

$typeLabels = ['massage' => 'Massage · 90 Min.', 'yoga' => 'Private Yoga Session · 75 Min.', 'value' => 'Wertgutschein'];

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Gutscheine – Admin – LagOHM</title>
<link rel="stylesheet" href="../css/style.css?v=20261004-4">
<link rel="stylesheet" href="admin.css?v=20261004-4">
<style>
  .voucher-toolbar{ display:flex; gap:12px; flex-wrap:wrap; align-items:center; margin: 8px 0 8px; }
  .voucher-toolbar input[type="search"]{ padding:10px 12px; border:1px solid var(--line); border-radius:10px; font-size:1rem; min-width: 220px; }
  .voucher-row-actions{ display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
  .voucher-row-actions form{ display:flex; gap:6px; align-items:center; margin:0; }
  .voucher-row-actions input[type="text"]{ width: 80px; padding:6px 8px; border:1px solid var(--line); border-radius:8px; }
  .status-open{ color:#2f7a3d; font-weight:600; }
  .status-partial{ color: var(--accent-1); font-weight:600; }
  .status-done, .status-expired{ color: var(--ink-soft); }
  .admin-table code{ font-size: .95rem; font-weight: 700; }
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
  <h1>Gutscheine</h1>
  <p class="admin-note">Alle ausgestellten Gutscheine. Wenn jemand mit Gutschein bucht, die Nummer hier suchen und „Einlösen“ klicken – bei Wertgutscheinen mit dem verbrauchten Betrag; der Rest wird automatisch berechnet.</p>
  <?php if ($error): ?><p class="admin-error"><?= e($error) ?></p><?php endif; ?>
  <?php if ($message): ?><p class="admin-success"><?= e($message) ?></p><?php endif; ?>

  <div class="voucher-toolbar">
    <a class="btn btn-primary" href="voucher.php">+ Neuer Gutschein</a>
    <form method="get" style="display:flex; gap:8px; margin:0;">
      <input type="search" name="q" placeholder="Nummer suchen, z. B. K7P3" value="<?= e($q) ?>">
      <button type="submit" class="btn btn-ghost btn-small">Suchen</button>
      <?php if ($q !== ''): ?><a class="btn btn-ghost btn-small" href="vouchers.php">Alle</a><?php endif; ?>
    </form>
  </div>

  <table class="admin-table">
    <thead>
      <tr><th>Nr.</th><th>Art</th><th>Ausgestellt</th><th>Gültig bis</th><th>Status</th><th></th></tr>
    </thead>
    <tbody>
      <?php foreach ($vouchers as $v): ?>
        <?php
          $expired = $v['valid_until'] < $today;
          $isValue = $v['type'] === 'value';
          $remaining = $isValue ? (int)$v['amount_cents'] - (int)$v['used_cents'] : 0;
          $done = $isValue ? $remaining <= 0 : (int)$v['redemptions'] > 0;
          $lastRedeemed = $v['last_redeemed'] ? (new DateTime($v['last_redeemed']))->format('d.m.Y') : '';
          if ($done) {
              [$cls, $status] = ['status-done', 'eingelöst' . ($lastRedeemed ? ' am ' . $lastRedeemed : '')];
          } elseif ($expired) {
              [$cls, $status] = ['status-expired', 'abgelaufen' . ($isValue && (int)$v['used_cents'] > 0 ? ' (Rest ' . euro($remaining) . ')' : '')];
          } elseif ($isValue && (int)$v['used_cents'] > 0) {
              [$cls, $status] = ['status-partial', 'teilweise eingelöst – Rest ' . euro($remaining)];
          } else {
              [$cls, $status] = ['status-open', 'offen'];
          }
        ?>
        <tr>
          <td><code><?= e($v['code']) ?></code><?= $v['language'] === 'en' ? '<br><small>Englisch</small>' : '' ?></td>
          <td><?= e($typeLabels[$v['type']] ?? $v['type']) ?><?= $isValue ? '<br>' . e(euro((int)$v['amount_cents'])) : '' ?></td>
          <td><?= e((new DateTime($v['issued_on']))->format('d.m.Y')) ?></td>
          <td><?= e((new DateTime($v['valid_until']))->format('d.m.Y')) ?></td>
          <td class="<?= $cls ?>"><?= e($status) ?></td>
          <td>
            <div class="voucher-row-actions">
              <?php if (!$done && !$expired): ?>
                <form method="post" onsubmit="return confirm('<?= $isValue ? 'Betrag von ' . e($v['code']) . ' einlösen?' : e($v['code']) . ' als eingelöst markieren?' ?>');">
                  <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
                  <input type="hidden" name="voucher_id" value="<?= (int)$v['id'] ?>">
                  <input type="hidden" name="action" value="redeem">
                  <?php if ($isValue): ?><input type="text" name="amount" inputmode="decimal" placeholder="€" aria-label="Betrag in Euro"><?php endif; ?>
                  <button type="submit" class="btn btn-primary btn-small">Einlösen</button>
                </form>
              <?php endif; ?>
              <a class="btn btn-ghost btn-small" href="voucher.php?reprint=<?= (int)$v['id'] ?>">Nachdrucken</a>
              <?php if ((int)$v['redemptions'] > 0): ?>
                <form method="post" onsubmit="return confirm('Letzte Einlösung von <?= e($v['code']) ?> rückgängig machen?');">
                  <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
                  <input type="hidden" name="voucher_id" value="<?= (int)$v['id'] ?>">
                  <input type="hidden" name="action" value="undo">
                  <button type="submit" class="btn btn-ghost btn-small">Rückgängig</button>
                </form>
              <?php endif; ?>
              <form method="post" onsubmit="return confirm('<?= e($v['code']) ?> endgültig aus der Liste löschen?');">
                <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
                <input type="hidden" name="voucher_id" value="<?= (int)$v['id'] ?>">
                <input type="hidden" name="action" value="delete">
                <button type="submit" class="btn btn-ghost btn-small">Löschen</button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$vouchers): ?>
        <tr><td colspan="6"><?= $q !== '' ? 'Kein Gutschein mit dieser Nummer gefunden.' : 'Noch keine Gutscheine gespeichert.' ?></td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</main>
</body>
</html>
