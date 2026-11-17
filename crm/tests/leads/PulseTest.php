<?php

declare(strict_types=1);

use App\Crm\PulseController;
use App\Leads\Clock;
use App\Leads\Leads;
use App\Leads\LeadService;
use App\Leads\Actor;
use App\Support\Db;

test('Уведомления в браузере: новые и повторные заявки, чат с оператором, без имени и телефона', function (): void {
    Clock::set('2026-11-17 11:00:00');
    $first = PulseController::data('', Clock::now());
    eq([], $first['items'], 'первый запрос — только отметка времени');
    eq('2026-11-17 11:00:00', $first['now']);

    Clock::set('2026-11-17 11:00:10');
    $lead = Tests::lead(['name' => 'Анна', 'phone' => '+7 913 816-27-35', 'service' => 'Категория B на автомате']);
    $pulse = PulseController::data($first['now'], Clock::now());
    eq(1, count($pulse['items']));
    eq('Новая заявка №' . $lead['id'], $pulse['items'][0]['title']);
    eq('Категория B на автомате', $pulse['items'][0]['text']);
    eq('/crm/lead/' . $lead['id'], $pulse['items'][0]['url']);
    ok(!str_contains(json_encode($pulse, JSON_UNESCAPED_UNICODE), 'Анна'), 'имени нет');
    ok(!str_contains(json_encode($pulse), '816'), 'телефона нет');
    eq(1, $pulse['queue'], 'в очереди одна заявка');

    // та же секунда, что в прошлом ответе, — снова в выдаче (ключ тот же, вкладка покажет один раз)
    eq($pulse['items'][0]['key'], PulseController::data('2026-11-17 11:00:10', Clock::now())['items'][0]['key']);

    // повторная заявка с того же номера
    Clock::set('2026-11-17 11:05:00');
    Tests::lead(['name' => 'Анна', 'phone' => '+7 913 816-27-35']);
    $repeat = PulseController::data('2026-11-17 11:04:00', Clock::now());
    eq('Повторная заявка №' . $lead['id'], $repeat['items'][0]['title']);
    ok($repeat['items'][0]['key'] !== $pulse['items'][0]['key'], 'у повторной свой ключ');

    // мусор не показываем
    LeadService::setStatus(Leads::find((int) $lead['id']), 'junk', Actor::system());
    eq([], PulseController::data('2026-11-17 11:04:00', Clock::now())['items']);

    // чат: бот позвал оператора
    $pdo = Db::pdo();
    $pdo->exec("INSERT INTO chats (chat_id, created_at, last_at, messages, operator, first_text) VALUES ('pulsechat000000000001', '2026-11-17 11:06:00', '2026-11-17 11:06:20', 2, 1, 'Есть ли обучение на категорию C?')");
    $chat = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO chat_messages (chat_ref, created_at, role, text, operator) VALUES ($chat, '2026-11-17 11:06:00', 'user', 'Есть ли обучение на категорию C?', 0), ($chat, '2026-11-17 11:06:20', 'bot', 'Позову администратора', 1)");
    Clock::set('2026-11-17 11:06:30');
    $items = PulseController::data('2026-11-17 11:06:00', Clock::now())['items'];
    eq(1, count($items));
    eq('В чате на сайте нужен оператор', $items[0]['title']);
    eq('Есть ли обучение на категорию C?', $items[0]['text']);
    eq('/crm/chat/' . $chat, $items[0]['url']);

    // после долгого перерыва — не старше 15 минут
    Clock::set('2026-11-17 13:00:00');
    eq([], PulseController::data('2026-11-17 11:00:00', Clock::now())['items']);
});
