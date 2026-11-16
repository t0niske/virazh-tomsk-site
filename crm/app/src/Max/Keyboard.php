<?php

declare(strict_types=1);

namespace App\Max;

/** Кнопки сообщений MAX. Клавиатура — массив рядов, ряд — массив кнопок. */
final class Keyboard
{
    public static function callback(string $text, string $payload): array
    {
        return ['type' => 'callback', 'text' => $text, 'payload' => $payload];
    }

    public static function link(string $text, string $url): array
    {
        return ['type' => 'link', 'text' => $text, 'url' => $url];
    }

    public static function contact(string $text): array
    {
        return ['type' => 'request_contact', 'text' => $text];
    }

    /** Вложение inline_keyboard для тела сообщения. */
    public static function attachment(array $rows): array
    {
        return ['type' => 'inline_keyboard', 'payload' => ['buttons' => $rows]];
    }

    /** Ряды «Цены и рассрочка», «Адреса классов», «Ближайшие группы». */
    public static function faq(): array
    {
        return [
            [self::callback('Цены и рассрочка', 'c:faq:prices'), self::callback('Адреса классов', 'c:faq:addresses')],
            [self::callback('Ближайшие группы', 'c:faq:groups')],
        ];
    }
}
