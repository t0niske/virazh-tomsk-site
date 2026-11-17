<?php

declare(strict_types=1);

namespace App\Chat;

use App\Leads\Http\LeadController;
use App\Leads\Log;
use App\Visits\Collector;

/**
 * POST /api/chat {chat_id, message} → {reply, operator[, live]}; live — в переписке администратор, ИИ промолчал.
 * GET /api/chat?chat_id=…&after=N → {messages, live, waiting} — ответы администратора для окна чата на сайте.
 */
final class ChatController
{
    public static function handle(string $method): void
    {
        if ($method === 'GET') {
            if (!Collector::sameSite($_SERVER)) {
                LeadController::json(403, ['messages' => [], 'live' => false, 'waiting' => false]);

                return;
            }

            try {
                LeadController::json(200, Chat::poll((string) ($_GET['chat_id'] ?? ''), (int) ($_GET['after'] ?? 0)));
            } catch (\Throwable $e) {
                Log::error('Чат: ответы администратора', $e);
                LeadController::json(500, ['messages' => [], 'live' => false, 'waiting' => false]);
            }

            return;
        }

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
                ['vid' => $_COOKIE['sd_vid'] ?? null, 'sid' => $_COOKIE['sd_sid'] ?? null],   // путь посетителя для /crm/chat/N
            );
        } catch (\Throwable $e) {
            Log::error('Ошибка чат-бота', $e);
            [$code, $body] = [500, ['reply' => 'Не получилось ответить. Позвоните нам: +7 (3822) 34-52-52.', 'operator' => true, 'fallback' => true]];
        }

        LeadController::json($code, $body);
    }
}
