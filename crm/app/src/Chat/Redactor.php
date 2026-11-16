<?php

declare(strict_types=1);

namespace App\Chat;

/**
 * Вырезает из сообщения персональные данные до отправки в DeepSeek и до записи в базу:
 * ссылки, почту, телефоны, длинные номера документов (docs/chat-api.md).
 */
final class Redactor
{
    public static function clean(string $text): string
    {
        // Сначала почта (в ней тоже есть «домен.ru»), потом ссылки: с протоколом, с www. или вида site.ru/путь.
        $text = preg_replace('/[\p{L}\p{N}._%+-]+@[\p{L}\p{N}.-]+\.\p{L}{2,}/u', '[почта]', $text) ?? $text;

        // Ссылки.
        $text = preg_replace('~\b(?:https?://|www\.)\S+|\b[a-z0-9-]+(?:\.[a-z0-9-]+)*\.(?:ru|рф|com|net|org|su|info|me|io|pro)(?:/\S*)?~iu', '[ссылка]', $text) ?? $text;

        // Последовательности цифр с пробелами, скобками, дефисами: 7–15 цифр — телефон,
        // 9+ цифр подряд без разделителей и не похоже на телефон — номер документа.
        $text = preg_replace_callback('/(?<![\p{N}])\+?\d(?:[\s()\-]{0,3}\d){6,}(?![\p{N}])/u', static function (array $m): string {
            $digits = preg_replace('/\D/', '', $m[0]);
            $plain = ctype_digit(ltrim($m[0], '+'));
            $phoneLike = str_starts_with($m[0], '+') || (strlen($digits) === 11 && in_array($digits[0], ['7', '8'], true))
                || (strlen($digits) === 10 && $digits[0] === '9');

            if (strlen($digits) > 15 || ($plain && strlen($digits) >= 9 && !$phoneLike)) {
                return '[номер]';
            }

            return '[телефон]';
        }, $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
