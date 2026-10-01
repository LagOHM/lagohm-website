<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/GoogleCalendar.php';

lagohm_config();
Auth::requireLogin();

$expectedState = (string)($_SESSION['google_oauth_state'] ?? '');
unset($_SESSION['google_oauth_state']);

$state = (string)($_GET['state'] ?? '');
if ($expectedState === '' || !hash_equals($expectedState, $state)) {
    $_SESSION['calendar_flash'] = ['error', 'Verbindung abgebrochen (ungültiger Status). Bitte nochmal versuchen.'];
} elseif (isset($_GET['error'])) {
    $_SESSION['calendar_flash'] = ['error', 'Google hat die Verbindung nicht erlaubt (' . substr((string)$_GET['error'], 0, 60) . ').'];
} else {
    try {
        GoogleCalendar::handleCallback((string)($_GET['code'] ?? ''));
        $_SESSION['calendar_flash'] = ['success', 'Google Kalender ist verbunden.'];
    } catch (Throwable $e) {
        $_SESSION['calendar_flash'] = ['error', 'Verbinden fehlgeschlagen: ' . $e->getMessage()];
    }
}

header('Location: calendar.php');
exit;
