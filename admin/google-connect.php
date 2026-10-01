<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/GoogleCalendar.php';

lagohm_config();
Auth::requireLogin();

if (!GoogleCalendar::isConfigured()) {
    header('Location: calendar.php');
    exit;
}

$state = bin2hex(random_bytes(16));
$_SESSION['google_oauth_state'] = $state;

header('Location: ' . GoogleCalendar::authUrl($state));
exit;
