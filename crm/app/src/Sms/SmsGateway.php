<?php

declare(strict_types=1);

namespace App\Sms;

/** Провайдер SMS. Номер — в виде +79XXXXXXXXX. */
interface SmsGateway
{
    /** @return array{ok: bool, id: ?string, error: ?string} */
    public function send(string $phone, string $text): array;
}
