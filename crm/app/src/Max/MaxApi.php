<?php

declare(strict_types=1);

namespace App\Max;

/**
 * Bot API мессенджера MAX (https://dev.max.ru/docs-api).
 * $to — ['chat_id' => …] для чата или ['user_id' => …] для личного диалога.
 * $keyboard — ряды кнопок из Keyboard, null — без кнопок.
 */
interface MaxApi
{
    /** @return ?string mid отправленного сообщения или null при ошибке */
    public function send(array $to, string $text, ?array $keyboard = null, bool $notify = true): ?string;

    public function edit(string $mid, string $text, ?array $keyboard = null): bool;

    /** Ответ на нажатие кнопки: короткое уведомление и/или новое содержимое сообщения с кнопкой. */
    public function answer(string $callbackId, ?string $notification = null, ?array $message = null): bool;
}
