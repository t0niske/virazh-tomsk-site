<?php

declare(strict_types=1);

namespace App\Crm;

use App\Chat\Chat;
use App\Leads\Audit;
use App\Leads\Clock;

/** Переписка чат-бота сайта: список и страница чата. */
final class ChatsController
{
    public static function index(): void
    {
        $period = (string) ($_GET['period'] ?? 'week');
        [$from, $to] = LeadsController::period($period, (string) ($_GET['from'] ?? ''), (string) ($_GET['to'] ?? ''), Clock::now());
        $operator = isset($_GET['operator']) && $_GET['operator'] !== '';
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $result = Chat::search($from, $to, $operator, $page);

        View::render('chats', [
            'title' => 'Чат на сайте',
            'active' => 'chats',
            'rows' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / 50)),
            'period' => $period,
            'from' => (string) ($_GET['from'] ?? ''),
            'to' => (string) ($_GET['to'] ?? ''),
            'operator' => $operator,
        ]);
    }

    public static function show(int $id): void
    {
        $chat = Chat::find($id);

        if ($chat === null) {
            View::render('error', ['title' => 'Не найдено', 'message' => 'Такой переписки нет — возможно, она удалена (без заявки хранится 3 месяца).'], 404);

            return;
        }

        $seen = $_SESSION['chat_viewed'][$id] ?? 0;
        if (time() - $seen > 600) {
            Audit::log(Auth::actor(), 'chat_view', $chat['lead_id'] !== null ? (int) $chat['lead_id'] : null, "Открыл переписку чата №$id");
            $_SESSION['chat_viewed'][$id] = time();
        }

        View::render('chat', ['title' => 'Чат №' . $id, 'active' => 'chats', 'chat' => $chat, 'messages' => Chat::messages($id)]);
    }
}
