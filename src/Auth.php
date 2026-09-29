<?php
declare(strict_types=1);

namespace SevillaMatrix;

final class Auth
{
    public static function start(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_name('sevilla_matrix_session');
            session_start([
                'cookie_httponly' => true,
                'cookie_samesite' => 'Lax',
                'cookie_secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            ]);
        }
    }

    public static function check(): bool
    {
        self::start();
        return ($_SESSION['admin'] ?? false) === true;
    }

    public static function attempt(string $password): bool
    {
        self::start();
        if (self::retryAfter() > 0) {
            self::audit('login_blocked');
            return false;
        }
        $hash = Env::get('ADMIN_PASSWORD_HASH', '');
        if (!$hash || !password_verify($password, $hash)) {
            self::recordFailure();
            self::audit('login_failed');
            return false;
        }
        self::clearFailures();
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
        self::audit('login_success');
        return true;
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];
        session_destroy();
    }

    public static function csrf(): string
    {
        self::start();
        return $_SESSION['csrf'] ??= bin2hex(random_bytes(24));
    }

    public static function verifyCsrf(?string $token): bool
    {
        self::start();
        return is_string($token) && hash_equals($_SESSION['csrf'] ?? '', $token);
    }

    public static function retryAfter(): int
    {
        $attempts = self::attempts();
        $key = self::clientKey();
        $times = array_values(array_filter($attempts[$key] ?? [], static fn(mixed $time): bool => (int)$time >= time() - 900));
        if (count($times) < 5) return 0;
        return max(1, 900 - (time() - (int)min($times)));
    }

    private static function recordFailure(): void
    {
        $attempts = self::attempts();
        $key = self::clientKey();
        $attempts[$key] = array_values(array_filter($attempts[$key] ?? [], static fn(mixed $time): bool => (int)$time >= time() - 900));
        $attempts[$key][] = time();
        self::saveAttempts($attempts);
    }

    private static function clearFailures(): void
    {
        $attempts = self::attempts();
        unset($attempts[self::clientKey()]);
        self::saveAttempts($attempts);
    }

    /** @return array<string,list<int>> */
    private static function attempts(): array
    {
        $file = PROJECT_ROOT . '/storage/cache/login-attempts.json';
        $decoded = is_file($file) ? json_decode((string)file_get_contents($file), true) : [];
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,list<int>> $attempts */
    private static function saveAttempts(array $attempts): void
    {
        $directory = PROJECT_ROOT . '/storage/cache';
        if (!is_dir($directory)) @mkdir($directory, 0770, true);
        file_put_contents($directory . '/login-attempts.json', json_encode($attempts), LOCK_EX);
    }

    private static function clientKey(): string
    {
        return hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? 'cli'));
    }

    private static function audit(string $event): void
    {
        $directory = PROJECT_ROOT . '/storage/logs';
        if (!is_dir($directory)) @mkdir($directory, 0770, true);
        @file_put_contents($directory . '/access.log', json_encode([
            'at' => date(DATE_ATOM), 'event' => $event,
            'client' => substr(self::clientKey(), 0, 16),
            'agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? 'cli'), 0, 180),
        ], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
    }
}
