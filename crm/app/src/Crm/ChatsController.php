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

        $now = Clock::now();
        View::render('chat', [
            'title' => 'Чат №' . $id,
            'active' => 'chats',
            'chat' => $chat,
            'messages' => Chat::messages($id),
            'live' => Chat::live($chat, $now),
            'waiting' => Chat::waiting($chat, $now),
            // свежая переписка обновляется на странице сама, пока администратор её открыл
            'fresh' => $chat['last_at'] >= Clock::sql($now->modify('-2 hours')),
            'staffNames' => array_column(Staff::all(), 'name', 'id'),
        ]);
    }

    /** Ответ администратора посетителю: появится у него в окне чата на сайте; бот замолкает на 30 минут. */
    public static function reply(int $id): void
    {
        $chat = Chat::find($id);

        if ($chat === null) {
            View::render('error', ['title' => 'Не найдено', 'message' => 'Такой переписки нет.'], 404);

            return;
        }

        $text = trim((string) ($_POST['text'] ?? ''));

        if ($text === '') {
            View::flash('Напишите ответ посетителю.', 'error');
            View::redirect("/crm/chat/$id");

            return;
        }

        Chat::operatorReply($chat, Auth::user(), mb_substr($text, 0, 2000));
        Audit::log(Auth::actor(), 'chat_reply', $chat['lead_id'] !== null ? (int) $chat['lead_id'] : null, "Ответил в чате №$id");
        View::redirect("/crm/chat/$id#reply");
    }

    /** «Вернуть боту»: дальше на вопросы посетителя снова отвечает ИИ. */
    public static function release(int $id): void
    {
        $chat = Chat::find($id);

        if ($chat !== null) {
            Chat::release($chat);
            Audit::log(Auth::actor(), 'chat_release', $chat['lead_id'] !== null ? (int) $chat['lead_id'] : null, "Вернул чат №$id боту");
            View::flash('Переписка возвращена боту — посетитель увидел, что вы вышли из чата.');
        }

        View::redirect("/crm/chat/$id");
    }
}
