<?php

declare(strict_types=1);

namespace App\Leads;

/**
 * Российские номера: приведение любого ввода к +7XXXXXXXXXX и обратно к виду для людей.
 * Принимаем и городские (3822…): у Виража заявки приходят и с них, а SMS — только на мобильные (isMobile).
 */
final class Phone
{
    /** Возвращает +7XXXXXXXXXX или null, если это не российский номер (код начинается с 3, 4, 8 или 9). */
    public static function normalize(?string $input): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $input);

        if (strlen($digits) === 11 && ($digits[0] === '7' || $digits[0] === '8')) {
            $digits = substr($digits, 1);
        } elseif (strlen($digits) === 12 && str_starts_with($digits, '78')) {
            // «+7 8913…» — лишняя восьмёрка после кода страны.
            $digits = substr($digits, 2);
        }

        // Коды России: 3, 4, 8 — городские и межгород, 9 — мобильные.
        if (strlen($digits) !== 10 || !in_array($digits[0], ['3', '4', '8', '9'], true)) {
            return null;
        }

        return '+7' . $digits;
    }

    /** Мобильный ли номер +7XXXXXXXXXX: только на такие уходят SMS. */
    public static function isMobile(string $phone): bool
    {
        return (bool) preg_match('/^\+79\d{9}$/', $phone);
    }

    /** +73822345252 → +7 (382) 234-52-52 */
    public static function format(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);

        if (strlen($digits) !== 11) {
            return $phone;
        }

        return sprintf('+7 (%s) %s-%s-%s',
            substr($digits, 1, 3), substr($digits, 4, 3), substr($digits, 7, 2), substr($digits, 9, 2));
    }

    /** Любой ввод → +73822345252 без проверки (для номера автошколы из настроек). */
    public static function compact(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);

        if (strlen($digits) === 11 && $digits[0] === '8') {
            $digits = '7' . substr($digits, 1);
        }

        return '+' . $digits;
    }
}
