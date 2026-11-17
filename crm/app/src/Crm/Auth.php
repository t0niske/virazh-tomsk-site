<?php

declare(strict_types=1);

namespace App\Crm;

use App\Leads\Actor;
use App\Leads\Audit;
use App\Leads\Clock;
use App\Leads\RateLimiter;

/**
 * Вход в админку: сессия PHP, хеши паролей, защита от подбора и CSRF.
 * Подбор: 5 неудачных попыток на логин и 20 с одного IP за 15 минут — дальше пауза.
 */
final class Auth
{
    /** Вход держится 10 дней с последнего действия (решение владельца 17.11.2026), в том числе после закрытия браузера. */
    private const IDLE_SECONDS = 10 * 86400;
    private const WINDOW = 900;
    private const LOGIN_LIMIT = 5;
    private const IP_LIMIT = 20;

    private static ?array $user = null;

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $https = ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off'
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

        // Сессии — в своей папке: общая папка хостинга чистится по его сроку (минуты), а не по нашему.
        $dir = APP_ROOT . '/storage/sessions';
        if (is_dir($dir) || @mkdir($dir, 0700, true)) {
            session_save_path($dir);
            ini_set('session.gc_probability', '1');
            ini_set('session.gc_divisor', '100');
        }

        $cookie = ['path' => '/crm', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax'];
        session_name('sto_crm');
        session_set_cookie_params(['lifetime' => self::IDLE_SECONDS] + $cookie);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.gc_maxlifetime', (string) self::IDLE_SECONDS);
        session_start();

        if (isset($_SESSION['seen']) && time() - $_SESSION['seen'] > self::IDLE_SECONDS) {
            $_SESSION = [];
            session_regenerate_id(true);
        }

        $_SESSION['seen'] = time();

        // PHP присылает cookie только при создании сессии — продлеваем её срок на каждом заходе,
        // чтобы 10 дней считались от последнего действия, а не от входа.
        if (!headers_sent()) {
            setcookie(session_name(), session_id(), ['expires' => time() + self::IDLE_SECONDS] + $cookie);
        }
        $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    public static function user(): ?array
    {
        if (self::$user !== null || !isset($_SESSION['staff_id'])) {
            return self::$user;
        }

        $staff = Staff::find((int) $_SESSION['staff_id']);

        // Отключённый сотрудник или сменённый пароль — выходим из всех сессий.
        if ($staff === null || (int) $staff['active'] !== 1 || ($_SESSION['pw'] ?? '') !== self::passwordMark($staff)) {
            $_SESSION = [];

            return null;
        }

        return self::$user = $staff;
    }

    public static function actor(): Actor
    {
        return Actor::staff(self::user());
    }

    public static function isManager(): bool
    {
        return (self::user()['role'] ?? '') === 'manager';
    }

    /** null при успехе, иначе текст ошибки. */
    public static function attempt(string $login, string $password, string $ip): ?string
    {
        $login = mb_strtolower(trim($login));

        if (RateLimiter::count('login_ip:' . $ip, self::WINDOW) >= self::IP_LIMIT
            || RateLimiter::count('login_user:' . $login, self::WINDOW) >= self::LOGIN_LIMIT) {
            return 'Слишком много неудачных попыток. Подождите 15 минут.';
        }

        $staff = Staff::findByLogin($login);
        // Проверяем хеш даже для несуществующего логина, чтобы по времени ответа нельзя было угадать логины.
        $hash = $staff['password_hash'] ?? '$2y$12$n.SAeguP6tMC6fRZ1bvabOhMaZyJ3H5STxZZzchuOSqcAUBtERwp6';
        $valid = password_verify($password, $hash) && $staff !== null && (int) $staff['active'] === 1;

        if (!$valid) {
            RateLimiter::hit('login_ip:' . $ip, PHP_INT_MAX, self::WINDOW);
            RateLimiter::hit('login_user:' . $login, PHP_INT_MAX, self::WINDOW);
            Audit::log(new Actor($staff !== null ? (int) $staff['id'] : null, $login), 'login_failed', null, 'Неверный логин или пароль');

            return 'Неверный логин или пароль.';
        }

        if (password_needs_rehash($staff['password_hash'], PASSWORD_DEFAULT)) {
            Staff::update((int) $staff['id'], ['password' => $password]);
            $staff = Staff::find((int) $staff['id']);
        }

        RateLimiter::clear('login_user:' . $login);
        session_regenerate_id(true);
        $_SESSION['staff_id'] = (int) $staff['id'];
        $_SESSION['pw'] = self::passwordMark($staff);
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        Staff::update((int) $staff['id'], ['last_login_at' => Clock::sql()]);
        self::$user = $staff;
        Audit::log(Actor::staff($staff), 'login');

        return null;
    }

    public static function logout(): void
    {
        if (self::user() !== null) {
            Audit::log(self::actor(), 'logout');
        }

        $_SESSION = [];
        session_regenerate_id(true);
        self::$user = null;
    }

    /** Обновляет отметку пароля в своей сессии после смены пароля. */
    public static function refreshPasswordMark(): void
    {
        self::$user = null;
        $staff = Staff::find((int) $_SESSION['staff_id']);
        $_SESSION['pw'] = self::passwordMark($staff);
    }

    public static function csrf(): string
    {
        return $_SESSION['csrf'] ?? '';
    }

    public static function checkCsrf(): bool
    {
        $token = (string) ($_POST['_csrf'] ?? '');

        return $token !== '' && hash_equals(self::csrf(), $token);
    }

    private static function passwordMark(array $staff): string
    {
        return substr(hash('sha256', (string) $staff['password_hash']), 0, 16);
    }
}
