<?php

declare(strict_types=1);

namespace App\Leads;

/** Текстовые журналы в storage/logs: sms.log, max.log, cron.log, errors.log. */
final class Log
{
    public static function write(string $file, string $message): void
    {
        $dir = \App\Support\Env::get('LEADS_LOG_DIR') ?: APP_ROOT . '/storage/logs';

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        @file_put_contents($dir . '/' . $file, '[' . Clock::sql() . '] ' . $message . "\n", FILE_APPEND | LOCK_EX);
    }

    public static function error(string $message, ?\Throwable $e = null): void
    {
        self::write('errors.log', $message . ($e ? ': ' . $e::class . ' ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() : ''));
    }
}
