<?php

declare(strict_types=1);

namespace App\Crm;

use App\Leads\Clock;
use App\Leads\Leads;
use App\Support\Db;
use DateTimeImmutable;

/**
 * GET /crm/pulse?since=… — что нового для уведомлений в браузере: заявки (новые и повторные) и чаты, где бот позвал
 * оператора. Открытая вкладка админки спрашивает раз в 20 секунд (crm.js). Без имени и телефона: уведомление
 * видно на экране посторонним, как и в Telegram.
 */
final class PulseController
{
    /** Не дальше 15 минут назад: после сна ноутбука — без лавины старых уведомлений. */
    private const LOOKBACK = 900;

    public static function handle(): void
    {
        session_write_close();
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(self::data((string) ($_GET['since'] ?? ''), Clock::now()), JSON_UNESCAPED_UNICODE);
    }

    /** @return array{now: string, queue: int, items: list<array{key: string, title: string, text: string, url: string}>} */
    public static function data(string $since, DateTimeImmutable $now): array
    {
        $out = ['now' => Clock::sql($now), 'queue' => count(Leads::dueNow($now)), 'items' => []];

        // Первый запрос вкладки — без since: только отметка времени, чтобы не показывать старое.
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $since)) {
            return $out;
        }

        $from = max($since, Clock::sql($now->modify('-' . self::LOOKBACK . ' seconds')));
        $pdo = Db::pdo();

        // «>=» и ключ id@время: заявка в ту же секунду, что и прошлый ответ, не потеряется и не придёт дважды.
        $leads = $pdo->prepare("SELECT id, service, request_count, last_request_at, suspect FROM leads
            WHERE last_request_at >= ? AND status <> 'junk' ORDER BY last_request_at, id LIMIT 20");
        $leads->execute([$from]);

        foreach ($leads as $lead) {
            $out['items'][] = [
                'key' => 'l' . $lead['id'] . '@' . str_replace(' ', 'T', (string) $lead['last_request_at']),
                'title' => ((int) $lead['request_count'] > 1 ? 'Повторная заявка №' : 'Новая заявка №') . $lead['id'],
                'text' => ($lead['service'] ?: 'услуга не указана') . ($lead['suspect'] !== null ? ' — похоже на робота' : ''),
                'url' => '/crm/lead/' . $lead['id'],
            ];
        }

        // Ответ бота с просьбой позвать оператора — одна строка в chat_messages, поэтому уведомление одно на просьбу.
        $chats = $pdo->prepare("SELECT c.id, c.first_text, m.created_at FROM chat_messages m JOIN chats c ON c.id = m.chat_ref
            WHERE m.operator = 1 AND m.created_at >= ? ORDER BY m.created_at, m.id LIMIT 20");
        $chats->execute([$from]);

        foreach ($chats as $chat) {
            $out['items'][] = [
                'key' => 'c' . $chat['id'] . '@' . str_replace(' ', 'T', (string) $chat['created_at']),
                'title' => 'В чате на сайте нужен оператор',
                'text' => mb_strimwidth((string) ($chat['first_text'] ?? ''), 0, 90, '…'),
                'url' => '/crm/chat/' . $chat['id'],
            ];
        }

        return $out;
    }
}
