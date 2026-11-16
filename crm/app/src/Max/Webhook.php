<?php

declare(strict_types=1);

namespace App\Max;

use App\Leads\Log;
use App\Support\Env;

/** POST /max/webhook — события от MAX. Принимаются только с секретом из MAX_WEBHOOK_SECRET. */
final class Webhook
{
    public static function handle(string $method): void
    {
        $secret = (string) Env::get('MAX_WEBHOOK_SECRET', '');
        $given = (string) ($_SERVER['HTTP_X_MAX_BOT_API_SECRET'] ?? '');

        if ($method !== 'POST' || $secret === '' || !hash_equals($secret, $given)) {
            http_response_code(403);

            return;
        }

        $update = json_decode((string) file_get_contents('php://input'), true, 64, JSON_INVALID_UTF8_SUBSTITUTE);

        // MAX ждёт быстрый ответ 200, иначе повторяет событие. Ошибки обработки только пишем в лог.
        http_response_code(200);
        header('Content-Type: application/json');
        echo '{"ok":true}';

        if (!is_array($update)) {
            return;
        }

        try {
            Bot::handle($update);
        } catch (\Throwable $e) {
            Log::error('Ошибка обработки события MAX ' . ($update['update_type'] ?? '?'), $e);
        }
    }
}
