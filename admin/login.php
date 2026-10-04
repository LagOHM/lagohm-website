<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/Csrf.php';

lagohm_config();

if (Auth::check()) {
    header('Location: index.php');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        $error = 'Sitzung abgelaufen, bitte Seite neu laden.';
    } else {
        $email = trim((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        if (Auth::attempt($email, $password)) {
            header('Location: index.php');
            exit;
        }
        $error = 'E-Mail oder Passwort falsch.';
    }
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin-Login – LagOHM</title>
<link rel="stylesheet" href="../css/style.css?v=20261004-3">
<link rel="stylesheet" href="admin.css?v=20261004-3">
</head>
<body>
<main class="admin-auth">
  <h1>Admin-Login</h1>
  <?php if (!empty($_GET['setup'])): ?><p class="admin-success">Konto erstellt, bitte einloggen.</p><?php endif; ?>
  <?php if ($error): ?><p class="admin-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES, 'UTF-8') ?>">
    <label>E-Mail<br><input type="email" name="email" required autofocus></label>
    <label>Passwort<br><input type="password" name="password" required></label>
    <button type="submit" class="btn btn-primary">Einloggen</button>
  </form>
</main>
</body>
</html>
