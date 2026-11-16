<?php

declare(strict_types=1);

namespace App\Leads;

/** Откуда пришла заявка — понятное название по меткам рекламы. */
final class Source
{
    /** @param array<string, ?string> $lead поля utm_*, yclid, page_url */
    public static function label(array $lead): string
    {
        $source = mb_strtolower(trim((string) ($lead['utm_source'] ?? '')));
        $medium = mb_strtolower(trim((string) ($lead['utm_medium'] ?? '')));

        if (($lead['yclid'] ?? '') !== '' && ($lead['yclid'] ?? null) !== null) {
            return 'Яндекс Директ';
        }

        return match (true) {
            $source === '' => 'Сайт',
            in_array($source, ['yandex', 'ya', 'direct', 'yandex.direct', 'yandex_direct', 'yd'], true)
                => in_array($medium, ['organic', 'search'], true) ? 'Яндекс поиск' : 'Яндекс Директ',
            str_starts_with($source, 'vk') || $source === 'vkontakte' => 'ВКонтакте',
            str_starts_with($source, 'google') => 'Google',
            in_array($source, ['2gis', '2гис'], true) => '2ГИС',
            in_array($source, ['avito', 'авито'], true) => 'Авито',
            $source === 'max' => 'MAX',
            $source === 'office' => 'Звонок или визит',
            default => mb_substr($source, 0, 60),
        };
    }

    public const CONTACT_TIMES = [
        'now' => 'сейчас',
        'morning' => 'утром',
        'day' => 'днём',
        'evening' => 'вечером',
    ];

    public const CHANNELS = [
        'call' => 'звонок',
        'max' => 'MAX',
    ];
}
