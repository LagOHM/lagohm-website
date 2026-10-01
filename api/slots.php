<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/Availability.php';

header('Content-Type: application/json; charset=utf-8');

$serviceSlug = isset($_GET['service']) ? preg_replace('/[^a-z]/', '', (string)$_GET['service']) : '';
$date = isset($_GET['date']) ? (string)$_GET['date'] : '';

if ($serviceSlug === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    echo json_encode(['error' => 'service and date (YYYY-MM-DD) are required']);
    exit;
}

$pdo = lagohm_db();
$service = Availability::getService($pdo, $serviceSlug);
if (!$service) {
    http_response_code(404);
    echo json_encode(['error' => 'unknown service']);
    exit;
}

try {
    $slots = Availability::getAvailableSlots($pdo, $service, $date);
} catch (GoogleCalendarException $e) {
    // Can't see the private calendar right now: show nothing rather than risk a clash.
    GoogleCalendar::reportFailure('slots', $e);
    http_response_code(503);
    echo json_encode(['error' => 'calendar temporarily unavailable']);
    exit;
}
echo json_encode([
    'service' => $serviceSlug,
    'date' => $date,
    'duration_minutes' => (int)$service['duration_minutes'],
    'slots' => $slots,
]);
