<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

/**
 * Одно подключение к базе на запрос.
 * Локально — SQLite (storage/database.sqlite), на хостинге — MySQL.
 * Код пишем так, чтобы он работал на обеих: без специфичных функций одной из СУБД.
 */
final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        // Пустой DB_DSN= в .env (как в .env.example) — тоже «по умолчанию»: SQLite в storage/.
        $dsn = Env::get('DB_DSN') ?: 'sqlite:' . APP_ROOT . '/storage/database.sqlite';

        self::$pdo = new PDO($dsn, Env::get('DB_USER'), Env::get('DB_PASS'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        if (self::driver() === 'sqlite') {
            self::$pdo->exec('PRAGMA foreign_keys = ON');
        }

        return self::$pdo;
    }

    public static function driver(): string
    {
        return self::pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
    }
}
