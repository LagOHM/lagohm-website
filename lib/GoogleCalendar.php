<?php
declare(strict_types=1);

require_once __DIR__ . '/Crypto.php';

final class GoogleCalendarException extends RuntimeException
{
}

/**
 * Minimal Google Calendar client (plain REST over cURL, no SDK).
 *
 * - OAuth: one connected Google account, refresh token encrypted at rest in oauth_tokens.
 * - Free/busy: the primary calendar plus the bookings calendar block booking slots.
 * - Events: each booking becomes an event in the bookings calendar (or primary if none chosen).
 *
 * Failure policy: if Google is connected but unreachable or the token was revoked, callers
 * fail closed (no slots / no new bookings) so a private appointment is never double-booked.
 * If Google was never connected, the booking system runs on its own.
 */
final class GoogleCalendar
{
    private const ACCOUNT_LABEL = 'main';
    private const SCOPES = 'https://www.googleapis.com/auth/calendar.readonly https://www.googleapis.com/auth/calendar.events';
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';
    private const API_BASE = 'https://www.googleapis.com/calendar/v3';
    private const ERROR_NOTIFY_INTERVAL_SECONDS = 6 * 3600;

    /** Client ID/secret are filled in (admin/calendar.php or config.php). */
    public static function isConfigured(): bool
    {
        $g = lagohm_config()['google'] ?? [];
        foreach (['client_id', 'client_secret', 'redirect_uri'] as $k) {
            $v = (string)($g[$k] ?? '');
            if ($v === '' || $v === 'CHANGE_ME') {
                return false;
            }
        }
        return true;
    }

    /** A refresh token is stored, i.e. availability must take Google into account. */
    public static function isConnected(): bool
    {
        $row = self::getRow();
        return $row !== null && !empty($row['refresh_token_encrypted']);
    }

    public static function getRow(): ?array
    {
        $stmt = lagohm_db()->prepare('SELECT * FROM oauth_tokens WHERE account_label = ?');
        $stmt->execute([self::ACCOUNT_LABEL]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function authUrl(string $state): string
    {
        $g = lagohm_config()['google'];
        return self::AUTH_URL . '?' . http_build_query([
            'client_id' => $g['client_id'],
            'redirect_uri' => $g['redirect_uri'],
            'response_type' => 'code',
            'scope' => self::SCOPES,
            'access_type' => 'offline',
            'prompt' => 'consent', // always return a refresh token, also on reconnect
            'include_granted_scopes' => 'true',
            'state' => $state,
        ]);
    }

    public static function handleCallback(string $code): void
    {
        $g = lagohm_config()['google'];
        $data = self::tokenRequest([
            'code' => $code,
            'client_id' => $g['client_id'],
            'client_secret' => $g['client_secret'],
            'redirect_uri' => $g['redirect_uri'],
            'grant_type' => 'authorization_code',
        ]);
        if (empty($data['refresh_token'])) {
            throw new GoogleCalendarException('Google hat kein Refresh-Token geliefert. Bitte unter myaccount.google.com/permissions den Zugriff für LagOHM entfernen und erneut verbinden.');
        }

        $expiresAt = gmdate('Y-m-d H:i:s', time() + (int)($data['expires_in'] ?? 3600));
        $stmt = lagohm_db()->prepare('INSERT INTO oauth_tokens
                (account_label, refresh_token_encrypted, access_token, access_token_expires_at, scope, calendar_id_primary)
            VALUES (?, ?, ?, ?, ?, "primary")
            ON DUPLICATE KEY UPDATE
                refresh_token_encrypted = VALUES(refresh_token_encrypted),
                access_token = VALUES(access_token),
                access_token_expires_at = VALUES(access_token_expires_at),
                scope = VALUES(scope),
                calendar_id_primary = "primary"');
        $stmt->execute([
            self::ACCOUNT_LABEL,
            Crypto::encrypt($data['refresh_token']),
            $data['access_token'] ?? null,
            $expiresAt,
            substr((string)($data['scope'] ?? self::SCOPES), 0, 255),
        ]);
        lagohm_db()->prepare('DELETE FROM app_settings WHERE setting_key = "google_error_notified_at"')->execute();
    }

    public static function disconnect(): void
    {
        $row = self::getRow();
        if ($row && !empty($row['refresh_token_encrypted'])) {
            try {
                self::http('POST', self::REVOKE_URL, ['token' => Crypto::decrypt($row['refresh_token_encrypted'])], null, false);
            } catch (Throwable $e) {
                // Revoking is best effort; we forget the token locally either way.
            }
        }
        $stmt = lagohm_db()->prepare('UPDATE oauth_tokens SET refresh_token_encrypted = NULL, access_token = NULL,
            access_token_expires_at = NULL WHERE account_label = ?');
        $stmt->execute([self::ACCOUNT_LABEL]);
    }

    /**
     * @return array<int, array{id:string, summary:string, primary:bool, accessRole:string}>
     */
    public static function listCalendars(): array
    {
        $data = self::api('GET', '/users/me/calendarList?minAccessRole=reader');
        $out = [];
        foreach ($data['items'] ?? [] as $item) {
            $out[] = [
                'id' => (string)$item['id'],
                'summary' => (string)($item['summaryOverride'] ?? $item['summary'] ?? $item['id']),
                'primary' => !empty($item['primary']),
                'accessRole' => (string)($item['accessRole'] ?? ''),
            ];
        }
        return $out;
    }

    public static function setBookingsCalendar(?string $calendarId): void
    {
        $stmt = lagohm_db()->prepare('UPDATE oauth_tokens SET calendar_id_bookings = ? WHERE account_label = ?');
        $stmt->execute([$calendarId, self::ACCOUNT_LABEL]);
    }

    /**
     * Busy intervals across the primary and bookings calendars.
     *
     * @return array<int, array{0: DateTime, 1: DateTime}> in UTC
     */
    public static function busyIntervals(DateTime $from, DateTime $to): array
    {
        static $cache = [];
        $utc = new DateTimeZone('UTC');
        $timeMin = (clone $from)->setTimezone($utc)->format('Y-m-d\TH:i:s\Z');
        $timeMax = (clone $to)->setTimezone($utc)->format('Y-m-d\TH:i:s\Z');
        $cacheKey = "$timeMin|$timeMax";
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        $calendarIds = self::busyCalendarIds();
        $data = self::api('POST', '/freeBusy', [
            'timeMin' => $timeMin,
            'timeMax' => $timeMax,
            'items' => array_map(static fn(string $id): array => ['id' => $id], $calendarIds),
        ]);

        $intervals = [];
        foreach ($data['calendars'] ?? [] as $id => $cal) {
            if (!empty($cal['errors'])) {
                $reason = $cal['errors'][0]['reason'] ?? 'unknown';
                throw new GoogleCalendarException("freeBusy error for calendar {$id}: {$reason}");
            }
            foreach ($cal['busy'] ?? [] as $b) {
                $intervals[] = [new DateTime($b['start'], $utc), new DateTime($b['end'], $utc)];
            }
        }
        return $cache[$cacheKey] = $intervals;
    }

    /**
     * Creates the calendar event for a booking. Returns the Google event ID.
     */
    public static function createBookingEvent(array $booking, array $service, DateTime $start, DateTime $end): string
    {
        $tzName = lagohm_config()['app']['timezone'] ?? 'Europe/Berlin';
        $adminUrl = rtrim(lagohm_config()['app']['base_url'], '/') . '/admin/';

        $lines = [
            'Kontakt: ' . $booking['customer_email'],
        ];
        if (!empty($booking['customer_phone'])) {
            $lines[] = 'Telefon: ' . $booking['customer_phone'];
        }
        if (!empty($booking['customer_note'])) {
            $lines[] = '';
            $lines[] = 'Notiz:';
            $lines[] = $booking['customer_note'];
        }
        $lines[] = '';
        $lines[] = 'Gebucht über lagohm.de (Buchung #' . (int)$booking['id'] . ').';
        $lines[] = 'Stornieren bitte nur im Admin-Bereich: ' . $adminUrl;

        $data = self::api('POST', '/calendars/' . rawurlencode(self::bookingsCalendarId()) . '/events', [
            'summary' => $service['name'] . ' – ' . $booking['customer_name'],
            'description' => implode("\n", $lines),
            'location' => str_replace("\n", ', ', (string)(lagohm_config()['business']['address'] ?? '')),
            'start' => ['dateTime' => $start->format(DateTime::RFC3339), 'timeZone' => $tzName],
            'end' => ['dateTime' => $end->format(DateTime::RFC3339), 'timeZone' => $tzName],
            'transparency' => 'opaque',
            'extendedProperties' => ['private' => ['lagohm_booking_id' => (string)(int)$booking['id']]],
        ]);
        if (empty($data['id'])) {
            throw new GoogleCalendarException('Event created without ID');
        }
        return (string)$data['id'];
    }

    public static function deleteEvent(string $eventId): void
    {
        $path = '/calendars/' . rawurlencode(self::bookingsCalendarId()) . '/events/' . rawurlencode($eventId);
        self::api('DELETE', $path, null, [404, 410]); // already gone = fine
    }

    /**
     * Removes a cancelled booking's event, if it has one. Best effort: failures are reported, not thrown.
     */
    public static function removeBookingEvent(int $bookingId): void
    {
        $stmt = lagohm_db()->prepare('SELECT google_event_id FROM bookings WHERE id = ?');
        $stmt->execute([$bookingId]);
        $eventId = $stmt->fetchColumn();
        if (!$eventId || !self::isConnected()) {
            return;
        }
        try {
            self::deleteEvent((string)$eventId);
            lagohm_db()->prepare('UPDATE bookings SET google_event_id = NULL WHERE id = ?')->execute([$bookingId]);
        } catch (Throwable $e) {
            self::reportFailure('delete event', $e, $bookingId);
        }
    }

    /**
     * Logs a Google failure and emails the admin (at most every few hours).
     */
    public static function reportFailure(string $context, Throwable $e, ?int $bookingId = null): void
    {
        $detail = substr($context . ': ' . $e->getMessage(), 0, 490);
        try {
            $pdo = lagohm_db();
            $pdo->prepare('INSERT INTO booking_audit_log (booking_id, action, detail) VALUES (?, "calendar_sync_failed", ?)')
                ->execute([$bookingId, $detail]);

            $last = (int)(lagohm_setting('google_error_notified_at', '0'));
            if (time() - $last < self::ERROR_NOTIFY_INTERVAL_SECONDS) {
                return;
            }
            $pdo->prepare('INSERT INTO app_settings (setting_key, setting_value) VALUES ("google_error_notified_at", ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')->execute([(string)time()]);

            $to = $pdo->query('SELECT email FROM admin_users ORDER BY id LIMIT 1')->fetchColumn();
            if (!$to) {
                return;
            }
            require_once __DIR__ . '/Mailer.php';
            $base = rtrim(lagohm_config()['app']['base_url'], '/');
            Mailer::send((string)$to, 'Helena', 'LagOHM: Problem mit Google Kalender',
                "Hallo Helena,\n\n"
                . "die Website konnte gerade nicht mit deinem Google Kalender sprechen:\n\n{$detail}\n\n"
                . "Solange das so ist, zeigt die Website zur Sicherheit keine freien Termine an "
                . "(damit nichts mit deinen privaten Terminen kollidiert).\n\n"
                . "Bitte unter {$base}/admin/calendar.php nachsehen und ggf. neu verbinden. "
                . "Ist es nur eine kurze Google-Störung, löst es sich von selbst.\n\n"
                . "Diese Mail kommt höchstens alle 6 Stunden.");
        } catch (Throwable $ignored) {
            // Reporting must never break the caller.
        }
    }

    // ---------------------------------------------------------------- internals

    private static function bookingsCalendarId(): string
    {
        $row = self::getRow();
        return (string)($row['calendar_id_bookings'] ?: ($row['calendar_id_primary'] ?: 'primary'));
    }

    /** @return string[] */
    private static function busyCalendarIds(): array
    {
        $row = self::getRow();
        $ids = [$row['calendar_id_primary'] ?: 'primary'];
        if (!empty($row['calendar_id_bookings'])) {
            $ids[] = $row['calendar_id_bookings'];
        }
        return array_values(array_unique($ids));
    }

    private static function accessToken(): string
    {
        $row = self::getRow();
        if (!$row || empty($row['refresh_token_encrypted'])) {
            throw new GoogleCalendarException('Google Kalender ist nicht verbunden');
        }
        if (!empty($row['access_token']) && !empty($row['access_token_expires_at'])
            && strtotime($row['access_token_expires_at'] . ' UTC') > time() + 60) {
            return (string)$row['access_token'];
        }

        $g = lagohm_config()['google'];
        $data = self::tokenRequest([
            'refresh_token' => Crypto::decrypt($row['refresh_token_encrypted']),
            'client_id' => $g['client_id'],
            'client_secret' => $g['client_secret'],
            'grant_type' => 'refresh_token',
        ]);
        $expiresAt = gmdate('Y-m-d H:i:s', time() + (int)($data['expires_in'] ?? 3600));
        lagohm_db()->prepare('UPDATE oauth_tokens SET access_token = ?, access_token_expires_at = ? WHERE account_label = ?')
            ->execute([$data['access_token'], $expiresAt, self::ACCOUNT_LABEL]);
        return (string)$data['access_token'];
    }

    private static function tokenRequest(array $form): array
    {
        [$status, $data] = self::http('POST', self::TOKEN_URL, $form, null, false);
        if ($status !== 200 || empty($data['access_token'])) {
            $err = $data['error'] ?? "HTTP {$status}";
            if ($err === 'invalid_grant') {
                throw new GoogleCalendarException('Zugriff auf Google Kalender wurde widerrufen oder ist abgelaufen (invalid_grant) — bitte neu verbinden.');
            }
            throw new GoogleCalendarException('Google token request failed: ' . $err);
        }
        return $data;
    }

    private static function api(string $method, string $path, ?array $json = null, array $okStatuses = []): array
    {
        [$status, $data] = self::http($method, self::API_BASE . $path, null, $json, true);
        if (($status >= 200 && $status < 300) || in_array($status, $okStatuses, true)) {
            return $data;
        }
        $msg = $data['error']['message'] ?? "HTTP {$status}";
        throw new GoogleCalendarException("Google API {$method} {$path} failed: {$msg}");
    }

    /**
     * @return array{0:int, 1:array}
     */
    private static function http(string $method, string $url, ?array $form, ?array $json, bool $auth): array
    {
        $headers = ['Accept: application/json'];
        if ($auth) {
            $headers[] = 'Authorization: Bearer ' . self::accessToken();
        }

        $payload = null;
        if ($form !== null) {
            $payload = http_build_query($form);
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        } elseif ($json !== null) {
            $payload = json_encode($json, JSON_UNESCAPED_UNICODE);
            $headers[] = 'Content-Type: application/json';
        }

        // cURL first; PHP's built-in HTTPS streams as fallback in case the host's libcurl
        // is missing or broken (the Netcup image already has a broken libcurl for git).
        $curlError = 'cURL extension not available';
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_HTTPHEADER => $headers,
            ]);
            if ($payload !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            }
            $body = curl_exec($ch);
            if ($body !== false) {
                return self::decodeResponse((int)curl_getinfo($ch, CURLINFO_HTTP_CODE), (string)$body);
            }
            $curlError = curl_error($ch);
            if (curl_errno($ch) === 28 /* CURLE_OPERATION_TIMEDOUT */) {
                // Request may already have reached Google; retrying could create a duplicate event.
                throw new GoogleCalendarException('Timeout talking to Google: ' . $curlError);
            }
        }

        return self::httpViaStreams($method, $url, $headers, $payload, $curlError);
    }

    /**
     * @return array{0:int, 1:array}
     */
    private static function httpViaStreams(string $method, string $url, array $headers, ?string $payload, string $curlError): array
    {
        if ($payload === null && $method !== 'GET') {
            $headers[] = 'Content-Length: 0';
        }
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $payload ?? '',
                'timeout' => 10,
                'ignore_errors' => true, // return body on 4xx/5xx too
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            $err = error_get_last()['message'] ?? 'unknown error';
            throw new GoogleCalendarException("Network error talking to Google (cURL: {$curlError}; streams: {$err})");
        }

        // $http_response_header is set by file_get_contents in this scope; take the last status line (after redirects).
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int)$m[1];
            }
        }
        return self::decodeResponse($status, $body);
    }

    /**
     * @return array{0:int, 1:array}
     */
    private static function decodeResponse(int $status, string $body): array
    {
        $data = $body === '' ? [] : json_decode($body, true);
        return [$status, is_array($data) ? $data : []];
    }
}
