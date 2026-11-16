<?php

declare(strict_types=1);

namespace App\Leads;

/**
 * Проверка сертификатов для запросов к SMS-сервисам, MAX и капче.
 * Если задан файл сертификатов — берём его; на Windows (локальная разработка) PHP часто не знает,
 * где сертификаты, поэтому там используем хранилище Windows. На хостинге обычно ничего не нужно.
 */
final class Tls
{
    /** @return array<int, mixed> опции curl */
    public static function options(string $caFile = ''): array
    {
        if ($caFile !== '') {
            return [CURLOPT_CAINFO => $caFile];
        }

        if (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')) {
            return [CURLOPT_SSL_OPTIONS => CURLSSLOPT_NATIVE_CA];
        }

        return [];
    }
}
