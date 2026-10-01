<?php
declare(strict_types=1);

final class Auth
{
    private const MAX_FAILED_ATTEMPTS = 5;
    private const LOCKOUT_MINUTES = 15;
    private const SESSION_IDLE_SECONDS = 7200; // 2h

    public static function attempt(string $email, string $password): bool
    {
        $pdo = lagohm_db();
        $stmt = $pdo->prepare('SELECT * FROM admin_users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user) {
            return false;
        }

        if ($user['locked_until'] !== null && strtotime($user['locked_until']) > time()) {
            return false;
        }

        if (!password_verify($password, $user['password_hash'])) {
            $failed = (int)$user['failed_login_count'] + 1;
            $lockUntil = null;
            if ($failed >= self::MAX_FAILED_ATTEMPTS) {
                $lockUntil = date('Y-m-d H:i:s', time() + self::LOCKOUT_MINUTES * 60);
                $failed = 0;
            }
            $upd = $pdo->prepare('UPDATE admin_users SET failed_login_count = ?, locked_until = ? WHERE id = ?');
            $upd->execute([$failed, $lockUntil, $user['id']]);
            return false;
        }

        $upd = $pdo->prepare('UPDATE admin_users SET failed_login_count = 0, locked_until = NULL, last_login_at = NOW() WHERE id = ?');
        $upd->execute([$user['id']]);

        self::startSession();
        session_regenerate_id(true);
        $_SESSION['admin_user_id'] = (int)$user['id'];
        $_SESSION['admin_email'] = $user['email'];
        $_SESSION['last_activity'] = time();

        return true;
    }

    public static function check(): bool
    {
        self::startSession();
        if (empty($_SESSION['admin_user_id'])) {
            return false;
        }
        if (time() - ($_SESSION['last_activity'] ?? 0) > self::SESSION_IDLE_SECONDS) {
            self::logout();
            return false;
        }
        $_SESSION['last_activity'] = time();
        return true;
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            header('Location: login.php');
            exit;
        }
    }

    public static function logout(): void
    {
        self::startSession();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    public static function hasAnyUser(): bool
    {
        $stmt = lagohm_db()->query('SELECT COUNT(*) AS c FROM admin_users');
        return (int)$stmt->fetch()['c'] > 0;
    }

    public static function createFirstUser(string $email, string $password): void
    {
        if (self::hasAnyUser()) {
            throw new RuntimeException('An admin user already exists.');
        }
        $stmt = lagohm_db()->prepare('INSERT INTO admin_users (email, password_hash) VALUES (?, ?)');
        $stmt->execute([$email, password_hash($password, PASSWORD_DEFAULT)]);
    }

    private static function startSession(): void
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
