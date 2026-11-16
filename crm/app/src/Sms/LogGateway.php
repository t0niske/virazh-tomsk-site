<?php

declare(strict_types=1);

namespace App\Sms;

use App\Leads\Log;

/** Для разработки: SMS никуда не уходят, а пишутся в storage/logs/sms.log. */
final class LogGateway implements SmsGateway
{
    public function send(string $phone, string $text): array
    {
        $id = 'log-' . bin2hex(random_bytes(4));
        Log::write('sms.log', "$id → $phone (" . mb_strlen($text) . " зн.): $text");

        return ['ok' => true, 'id' => $id, 'error' => null];
    }
}
