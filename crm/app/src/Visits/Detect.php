<?php

declare(strict_types=1);

namespace App\Visits;

/**
 * Что сервер понимает о заходе по данным счётчика: источник, устройство, человек это или бот.
 * Правила — из docs/visits-api.md. Класс без базы, проверяется тестами.
 */
final class Detect
{
    /** Подписи браузеров поисковых роботов, сервисов проверки и скриптов. */
    private const BOT_PATTERN = '/bot|crawl|spider|slurp|yandex(?!browser|searchbrowser)|googlebot|google-inspectiontool|bingbot|headless|lighthouse|pagespeed|curl|wget|python|httpclient|java\/|go-http|okhttp|axios|node-fetch|scrapy|phantom|selenium|puppeteer|playwright|monitor|uptime|preview|facebookexternalhit|telegrambot|whatsapp|vkshare/i';

    /**
     * Группы источников для графика «Заходы по дням» — как «Источники, сводка» в Яндекс Метрике.
     * Порядок и цвета постоянные: одна группа — один цвет везде (палитра проверена на цветовую слепоту).
     */
    public const GROUPS = [
        'direct' => ['label' => 'Прямые заходы', 'color' => '#2a78d6'],
        'yandex' => ['label' => 'Поиск Яндекса', 'color' => '#eb6834'],
        'google' => ['label' => 'Поиск Google', 'color' => '#1baf7a'],
        'ads' => ['label' => 'Реклама', 'color' => '#eda100'],
        'maps' => ['label' => 'Карты', 'color' => '#e87ba4'],
        'social' => ['label' => 'Соцсети и мессенджеры', 'color' => '#008300'],
        'sites' => ['label' => 'Другие сайты', 'color' => '#6250d6'],
    ];

    /** Группа по подписи источника из source(): реклама — всё с метками utm, yclid, gclid. */
    public static function group(string $source): string
    {
        return match (true) {
            $source === 'Прямой заход' => 'direct',
            $source === 'Поиск Яндекса' => 'yandex',
            $source === 'Поиск Google' => 'google',
            in_array($source, ['Яндекс Директ', 'Google Реклама'], true) || str_starts_with($source, 'Реклама') => 'ads',
            in_array($source, ['Яндекс Карты', '2ГИС'], true) => 'maps',
            in_array($source, ['ВКонтакте', 'MAX', 'Telegram'], true) => 'social',
            default => 'sites',
        };
    }

    /** @param array<string, string> $utm метки из первого просмотра */
    public static function source(array $utm, string $ref): string
    {
        if (($utm['yclid'] ?? '') !== '') {
            return 'Яндекс Директ';
        }

        if (($utm['gclid'] ?? '') !== '') {
            return 'Google Реклама';
        }

        if (($utm['utm_source'] ?? '') !== '') {
            return self::tagged($utm['utm_source'], $utm['utm_medium'] ?? '');
        }

        $host = strtolower((string) parse_url($ref, PHP_URL_HOST));
        $host = preg_replace('/^(www\.|m\.)/', '', $host) ?? $host;
        $path = (string) parse_url($ref, PHP_URL_PATH);

        if ($host === '') {
            return 'Прямой заход';
        }

        return match (true) {
            (bool) preg_match('/(^|\.)yandex\.[a-z.]+$/', $host) && str_starts_with($path, '/maps') => 'Яндекс Карты',
            (bool) preg_match('/(^|\.)(yandex\.[a-z.]+|ya\.ru)$/', $host) => 'Поиск Яндекса',
            (bool) preg_match('/(^|\.)google\.[a-z.]+$/', $host) => 'Поиск Google',
            (bool) preg_match('/(^|\.)2gis\.[a-z.]+$/', $host) => '2ГИС',
            in_array($host, ['vk.com', 'vk.ru'], true) || str_ends_with($host, '.vk.com') => 'ВКонтакте',
            $host === 'max.ru' || str_ends_with($host, '.max.ru') => 'MAX',
            $host === 't.me' => 'Telegram',
            default => 'Сайт: ' . mb_substr($host, 0, 100),
        };
    }

    /** @return array{device: string, browser: string, os: string} */
    public static function device(?string $ua, bool $touch = false, ?string $screen = null): array
    {
        $ua = (string) $ua;

        $os = match (true) {
            (bool) preg_match('/iPhone|iPad|iPod/', $ua) => 'iOS',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS X') || str_contains($ua, 'Macintosh') => 'macOS',
            str_contains($ua, 'CrOS') => 'ChromeOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => '—',
        };

        $browser = match (true) {
            str_contains($ua, 'YaBrowser') || str_contains($ua, 'YaSearchBrowser') => 'Яндекс Браузер',
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') || str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'SamsungBrowser') => 'Samsung',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Chrome/') || str_contains($ua, 'CriOS') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            default => '—',
        };

        $width = $screen !== null && preg_match('/^(\d+)x(\d+)$/', $screen, $m) ? min((int) $m[1], (int) $m[2]) : 0;
        $device = match (true) {
            str_contains($ua, 'iPad') || (str_contains($ua, 'Android') && !str_contains($ua, 'Mobile')) => 'планшет',
            (bool) preg_match('/Mobile|iPhone|iPod|Android/', $ua) => 'телефон',
            str_contains($ua, 'Macintosh') && $touch => 'планшет',
            $touch && $width > 0 && $width < 600 => 'телефон',
            default => 'компьютер',
        };

        return ['device' => $device, 'browser' => $browser, 'os' => $os];
    }

    /**
     * Источник по utm-меткам. Метки ставит не только реклама: 2ГИС, Яндекс Карты, соцсети добавляют
     * utm_source к ссылке на сайт из карточки. Реклама — только платный канал (utm_medium cpc, cpm, …)
     * или незнакомая метка без признаков бесплатного перехода.
     */
    private static function tagged(string $source, string $medium): string
    {
        $s = mb_strtolower(trim($source));
        $m = mb_strtolower(trim($medium));
        $paid = preg_match('/cpc|cpm|cpa|cpv|ppc|paid|banner|display|retarget|promo|(^|[_-])ads?($|[_-])/', $m) === 1
            || preg_match('/(^|[_-])ads?($|[_-])|direct|adwords|mytarget|vk_?ads|target/', $s) === 1;

        if (!$paid) {
            $known = match (true) {
                (bool) preg_match('/^(2gis|2гис|dublgis|doublegis)/u', $s) => '2ГИС',
                (bool) preg_match('/yandex[_.-]?maps|ya[_.-]?maps|^ymaps|maps\.yandex|yandex[_.-]?karty/', $s) => 'Яндекс Карты',
                (bool) preg_match('/^(vk|vkontakte|vk\.com|вк)$/u', $s) => 'ВКонтакте',
                (bool) preg_match('/^(max|max\.ru)$/', $s) => 'MAX',
                (bool) preg_match('/^(telegram|tg|t\.me)$/', $s) => 'Telegram',
                in_array($s, ['yandex', 'ya.ru', 'yandex.ru'], true) && in_array($m, ['organic', 'search'], true) => 'Поиск Яндекса',
                $s === 'google' && in_array($m, ['organic', 'search'], true) => 'Поиск Google',
                default => null,
            };

            if ($known !== null) {
                return $known;
            }

            if (in_array($m, ['organic', 'referral', 'social', 'maps', 'listing', 'card', 'profile', 'link', 'bio', 'post'], true)) {
                return 'Сайт: ' . mb_substr($source, 0, 100);
            }
        }

        return 'Реклама: ' . mb_substr($source, 0, 100);
    }

    public static function isBotAgent(?string $ua): bool
    {
        return $ua === null || trim($ua) === '' || preg_match(self::BOT_PATTERN, $ua) === 1;
    }

    /**
     * Человек, подозрительно или бот, и почему.
     *
     * @param array{user_agent: ?string, webdriver: int|bool, pages: int, events_count: int, active_seconds: int,
     *              has_leave: int|bool, last_ping_at: ?string, started_at: string, last_at: string} $visit
     * @param int $pagesFirst5Min сколько страниц открыто за первые 5 минут захода
     * @param bool $finished заход закончился (давно не было данных) — можно судить по отсутствию ping и leave
     * @return array{kind: string, reasons: list<string>}
     */
    public static function classify(array $visit, int $pagesFirst5Min, bool $finished): array
    {
        $bot = [];
        $suspect = [];

        if (self::isBotAgent($visit['user_agent'] ?? null)) {
            $bot[] = 'подпись робота или скрипта';
        }

        if ((bool) ($visit['webdriver'] ?? false)) {
            $bot[] = 'браузер управляется программой';
        }

        if ($pagesFirst5Min > 30) {
            $suspect[] = "$pagesFirst5Min страниц за 5 минут";
        }

        if ($finished) {
            if (($visit['last_ping_at'] ?? null) === null && !(bool) ($visit['has_leave'] ?? false)) {
                $suspect[] = 'страница не была видна на экране';
            } elseif ((int) $visit['active_seconds'] < 2 && (int) $visit['events_count'] === 0) {
                $suspect[] = 'меньше 2 секунд без единого действия';
            }
        }

        if ($bot !== []) {
            return ['kind' => 'bot', 'reasons' => [...$bot, ...$suspect]];
        }

        return ['kind' => $suspect !== [] ? 'suspect' : 'human', 'reasons' => $suspect];
    }
}
