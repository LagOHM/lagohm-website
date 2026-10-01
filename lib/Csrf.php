<?php
declare(strict_types=1);

final class Csrf
{
    private const SESSION_KEY = 'csrf_token';

    public static function token(): string
    {
        self::ensureSession();
        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::SESSION_KEY];
    }

    public static function verify(?string $submitted): bool
    {
        self::ensureSession();
        if (!is_string($submitted) || empty($_SESSION[self::SESSION_KEY])) {
            return false;
        }
        return hash_equals($_SESSION[self::SESSION_KEY], $submitted);
    }

    private static function ensureSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_set_cookie_params([
                'httponly' => true,
                'secure' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }
    }
}
