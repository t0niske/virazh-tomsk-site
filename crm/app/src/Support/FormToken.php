<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Метка времени формы заявки с подписью (защита от роботов, 16.11.2026; перенесено с «Сто дорог»): сайт Виража
 * статический, поэтому метку при открытии страницы берёт site.js с GET /api/form-ts и отправляет с заявкой
 * (поле form_ts), приём заявок проверяет, сколько секунд прошло. Робот-«звонковый бомбер» открывал страницу и
 * отправлял форму в ту же секунду — человек так быстро имя и телефон не заполнит. Подпись не даёт подставить
 * старую метку руками. Ключ — из секретов .env (отдельного ключа не заводим): FORM_SECRET, иначе пароль базы.
 */
final class FormToken
{
    public static function make(?int $now = null): string
    {
        $ts = $now ?? time();

        return $ts . '.' . self::sign($ts);
    }

    /** Сколько секунд назад выдана метка; null — метки нет или подпись не сходится. */
    public static function age(string $token, ?int $now = null): ?int
    {
        if (!preg_match('/^(\d{9,11})\.([a-f0-9]{16})$/', trim($token), $m)) {
            return null;
        }

        if (!hash_equals(self::sign((int) $m[1]), $m[2])) {
            return null;
        }

        return ($now ?? time()) - (int) $m[1];
    }

    private static function sign(int $ts): string
    {
        // у Виража база SQLite без пароля — подпись по LEADS_SECRET (случайный, его создаёт deploy.sh в .env на сервере)
        $secret = (string) (Env::get('FORM_SECRET') ?: Env::get('LEADS_SECRET') ?: Env::get('DB_PASS') ?: Env::get('MAX_WEBHOOK_SECRET') ?: 'virazh');

        return substr(hash_hmac('sha256', 'form-ts:' . $ts, $secret), 0, 16);
    }
}
