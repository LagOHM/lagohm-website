<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/Csrf.php';

lagohm_config();

if (Auth::hasAnyUser()) {
    http_response_code(403);
    echo 'Setup wurde bereits durchgeführt. <a href="login.php">Zum Login</a>';
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        $error = 'Sitzung abgelaufen, bitte Seite neu laden.';
    } else {
        $email = trim((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $confirm = (string)($_POST['password_confirm'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Bitte eine gültige E-Mail-Adresse angeben.';
        } elseif (mb_strlen($password) < 10) {
            $error = 'Das Passwort muss mindestens 10 Zeichen lang sein.';
        } elseif ($password !== $confirm) {
            $error = 'Die Passwörter stimmen nicht überein.';
        } else {
            Auth::createFirstUser($email, $password);
            header('Location: login.php?setup=done');
            exit;
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
<title>Admin-Einrichtung – LagOHM</title>
<link rel="stylesheet" href="../css/style.css?v=20261004-2">
<link rel="stylesheet" href="admin.css?v=20261004-2">
</head>
<body>
<main class="admin-auth">
  <h1>Admin-Konto einrichten</h1>
  <p>Dieses Formular funktioniert nur einmal, solange noch kein Admin-Konto existiert.</p>
  <?php if ($error): ?><p class="admin-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES, 'UTF-8') ?>">
    <label>E-Mail<br><input type="email" name="email" required></label>
    <label>Passwort (mind. 10 Zeichen)<br><input type="password" name="password" required minlength="10"></label>
    <label>Passwort bestätigen<br><input type="password" name="password_confirm" required minlength="10"></label>
    <button type="submit" class="btn btn-primary">Konto erstellen</button>
  </form>
</main>
</body>
</html>
