<?php

declare(strict_types=1);

namespace App\Leads;

use DateTimeImmutable;

/**
 * Текущее время в часовом поясе автошколы. В тестах и при ручном прогоне планировщика
 * время можно подменить: Clock::set('2026-11-02 10:00').
 */
final class Clock
{
    private static ?DateTimeImmutable $fixed = null;

    public static function now(): DateTimeImmutable
    {
        return self::$fixed ?? new DateTimeImmutable();
    }

    public static function set(?string $time): void
    {
        self::$fixed = $time === null ? null : new DateTimeImmutable($time);
    }

    public static function sql(?DateTimeImmutable $time = null): string
    {
        return ($time ?? self::now())->format('Y-m-d H:i:s');
    }

    public static function parse(?string $value): ?DateTimeImmutable
    {
        return $value === null || $value === '' ? null : new DateTimeImmutable($value);
    }
}
