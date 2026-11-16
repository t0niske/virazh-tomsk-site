<?php

declare(strict_types=1);

namespace App\Max;

use App\Leads\Log;

/** Для разработки и тестов: запросы к MAX не уходят, а пишутся в storage/logs/max.log и в память. */
final class LogMaxApi implements MaxApi
{
    /** @var list<array<string, mixed>> */
    public array $calls = [];

    public function send(array $to, string $text, ?array $keyboard = null, bool $notify = true): ?string
    {
        $mid = 'mid.log' . bin2hex(random_bytes(6));
        $this->record(['method' => 'send', 'to' => $to, 'text' => $text, 'keyboard' => $keyboard, 'notify' => $notify, 'mid' => $mid]);

        return $mid;
    }

    public function edit(string $mid, string $text, ?array $keyboard = null): bool
    {
        $this->record(['method' => 'edit', 'mid' => $mid, 'text' => $text, 'keyboard' => $keyboard]);

        return true;
    }

    public function answer(string $callbackId, ?string $notification = null, ?array $message = null): bool
    {
        $this->record(['method' => 'answer', 'callback_id' => $callbackId, 'notification' => $notification, 'message' => $message]);

        return true;
    }

    private function record(array $call): void
    {
        $this->calls[] = $call;
        Log::write('max.log', json_encode($call, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
