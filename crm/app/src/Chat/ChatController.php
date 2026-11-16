<?php

declare(strict_types=1);

namespace App\Chat;

use App\Leads\Http\LeadController;
use App\Leads\Log;
use App\Visits\Collector;

/** POST /api/chat {chat_id, message} → {reply, operator} (docs/chat-api.md). */
final class ChatController
{
    public static function handle(string $method): void
    {
        if ($method !== 'POST') {
            LeadController::json(405, ['reply' => 'Отправьте сообщение методом POST.', 'operator' => false]);

            return;
        }

        if (!Collector::sameSite($_SERVER)) {
            LeadController::json(403, ['reply' => 'Чат работает только на сайте автошколы.', 'operator' => false]);

            return;
        }

        $input = json_decode((string) file_get_contents('php://input', false, null, 0, 4096), true);
        $input = is_array($input) ? $input : $_POST;

        try {
            [$code, $body] = Chat::handle(
                is_string($input['chat_id'] ?? null) ? $input['chat_id'] : '',
                is_string($input['message'] ?? null) ? $input['message'] : '',
                (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            );
        } catch (\Throwable $e) {
            Log::error('Ошибка чат-бота', $e);
            [$code, $body] = [500, ['reply' => 'Не получилось ответить. Позвоните нам: +7 (983) 234-56-56.', 'operator' => true]];
        }

        LeadController::json($code, $body);
    }
}
