<?php
declare(strict_types=1);

function lagohm_config(): array
{
    static $config = null;
    if ($config === null) {
        $path = __DIR__ . '/config.php';
        if (!is_file($path)) {
            http_response_code(500);
            die('config.php missing. See lib/config.sample.php and SETUP.md.');
        }
        $config = require $path;
        date_default_timezone_set($config['app']['timezone'] ?? 'Europe/Berlin');
    }
    return $config;
}

function lagohm_db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $cfg = lagohm_config()['db'];
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $cfg['host'], $cfg['name']);
        $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        lagohm_migrate($pdo);
    }
    return $pdo;
}

/**
 * Small schema upgrades applied automatically (there is no working mysql CLI on the host).
 * Each step checks first, so this is a cheap no-op once applied.
 */
function lagohm_migrate(PDO $pdo): void
{
    try {
        if (!$pdo->query("SHOW COLUMNS FROM bookings LIKE 'language'")->fetch()) {
            $pdo->exec("ALTER TABLE bookings ADD COLUMN language CHAR(2) NOT NULL DEFAULT 'de' AFTER customer_note");
        }
    } catch (Throwable $e) {
        // Tables not created yet (fresh install) — sql/schema.sql has the column.
    }
}

/** Normalizes a language code to the two the site supports. */
function lagohm_lang(?string $lang): string
{
    return $lang === 'en' ? 'en' : 'de';
}

function lagohm_setting(string $key, ?string $default = null): ?string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        $stmt = lagohm_db()->query('SELECT setting_key, setting_value FROM app_settings');
        foreach ($stmt->fetchAll() as $row) {
            $cache[$row['setting_key']] = $row['setting_value'];
        }
    }
    return $cache[$key] ?? $default;
}

/**
 * Extra line printed under the address in customer emails and the calendar file
 * (e.g. which doorbell to ring). Editable under Admin → Einstellungen; empty = no hint.
 */
function lagohm_address_hint(string $lang = 'de'): string
{
    return $lang === 'en'
        ? trim((string)lagohm_setting('address_hint_en', 'Please ring the bell marked “Gillerblad”.'))
        : trim((string)lagohm_setting('address_hint', 'Bitte bei „Gillerblad“ klingeln.'));
}
