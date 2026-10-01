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
    }
    return $pdo;
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
