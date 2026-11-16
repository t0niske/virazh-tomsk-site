<?php

declare(strict_types=1);

namespace App\Leads;

use App\Support\Db;

/** Лимит частоты: не больше $limit событий за $seconds в одной «корзине» (IP, номер, логин). */
final class RateLimiter
{
    /** Учитывает попытку. false — лимит уже исчерпан, попытка не засчитана. */
    public static function hit(string $bucket, int $limit, int $seconds): bool
    {
        if (self::count($bucket, $seconds) >= $limit) {
            return false;
        }

        Db::pdo()->prepare('INSERT INTO rate_hits (bucket, created_at) VALUES (?, ?)')->execute([$bucket, Clock::sql()]);

        return true;
    }

    public static function count(string $bucket, int $seconds): int
    {
        $stmt = Db::pdo()->prepare('SELECT COUNT(*) FROM rate_hits WHERE bucket = ? AND created_at >= ?');
        $stmt->execute([$bucket, Clock::sql(Clock::now()->modify("-$seconds seconds"))]);

        return (int) $stmt->fetchColumn();
    }

    public static function clear(string $bucket): void
    {
        Db::pdo()->prepare('DELETE FROM rate_hits WHERE bucket = ?')->execute([$bucket]);
    }

    /** Удаляет записи старше суток (вызывает планировщик). */
    public static function cleanup(): void
    {
        Db::pdo()->prepare('DELETE FROM rate_hits WHERE created_at < ?')->execute([Clock::sql(Clock::now()->modify('-1 day'))]);
    }
}
