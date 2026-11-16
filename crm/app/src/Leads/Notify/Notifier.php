<?php

declare(strict_types=1);

namespace App\Leads\Notify;

use App\Leads\Log;

/**
 * Письмо и Telegram о каждой новой и повторной заявке (вместе с карточкой в чате MAX).
 * Заявка к этому моменту уже сохранена: сбой канала пишем в errors.log и идём дальше.
 */
final class Notifier
{
    /** @param ?array $request поля этой отправки формы (из LeadForm) */
    public static function leadAccepted(array $lead, bool $created, ?array $request = null): void
    {
        try {
            LeadMail::send($lead, !$created, $request['ip'] ?? null, $request);
        } catch (\Throwable $e) {
            Log::error("Письмо о заявке №{$lead['id']}", $e);
        }

        try {
            Telegram::send(Telegram::text($lead, !$created));
        } catch (\Throwable $e) {
            Log::error("Telegram о заявке №{$lead['id']}", $e);
        }
    }
}
