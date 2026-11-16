<?php

declare(strict_types=1);

use App\Leads\Clock;
use App\Leads\Leads;
use App\Leads\Status;
use App\Max\Bot;
use App\Max\Max;
use App\Support\Db;

/** Нажатие кнопки в чате администраторов. */
function press(string $payload, string $userId = '1001', string $chatId = '-500', string $name = 'Ольга'): void
{
    Bot::handle([
        'update_type' => 'message_callback',
        'callback' => [
            'callback_id' => 'cb' . random_int(1, 99999),
            'payload' => $payload,
            'user' => ['user_id' => (int) $userId, 'first_name' => $name],
        ],
        'message' => ['recipient' => ['chat_id' => (int) $chatId, 'chat_type' => 'chat'], 'body' => ['mid' => 'mid.card']],
    ]);
}

function lastAnswer(): array
{
    $answers = Tests::maxCalls('answer');

    return end($answers) ?: [];
}

test('MAX: карточка заявки с кнопками «Беру», «Не дозвонился», «Записан», «Отказ»', function (): void {
    Clock::set('2026-11-03 14:32');
    $lead = Tests::lead(['name' => 'Иван', 'service' => 'Категория B (АКПП)', 'utm_source' => 'yandex']);
    $card = Tests::maxCalls()[0];

    eq(['chat_id' => '-500'], $card['to']);
    ok(str_contains($card['text'], "Новая заявка №{$lead['id']} · 14:32"), $card['text']);
    ok(str_contains($card['text'], 'Иван, +7 (913) 000-00-00'), 'имя и телефон');
    ok(str_contains($card['text'], 'Откуда: Яндекс Директ'), 'источник');
    eq(['Беру', 'Не дозвонился', 'Придёт на договор', 'Записан', 'Отказ'], array_column(array_merge(...$card['keyboard']), 'text'));
    eq($card['mid'], $lead['card_mid']);
});

test('MAX: «Беру» берёт заявку, повторное нажатие не перехватывает её', function (): void {
    Clock::set('2026-11-03 11:00');
    Db::pdo()->exec("INSERT INTO staff (login, name, role, password_hash, max_user_id, active, created_at) VALUES ('olga', 'Ольга', 'admin', 'x', '1001', 1, '2026-01-01 00:00:00')");
    $lead = Tests::lead();

    Clock::set('2026-11-03 11:03');
    press("a:take:{$lead['id']}");
    $lead = Leads::find((int) $lead['id']);
    eq(Status::IN_WORK, $lead['status']);
    eq('Ольга', Leads::staffName((int) $lead['assigned_to']));
    eq('2026-11-03 11:03:00', $lead['taken_at']);
    eq('Заявка ваша — звоните', lastAnswer()['notification']);
    ok(Tests::maxCalls('edit') !== [], 'карточка обновлена');

    press("a:take:{$lead['id']}", '2002', '-500', 'Елена');
    eq('Заявку уже взяли: Ольга', lastAnswer()['notification']);
    eq('Ольга', Leads::staffName((int) Leads::find((int) $lead['id'])['assigned_to']));
});

test('MAX: «Не дозвонился», «Думает», «Придёт на договор», «Записан» меняют статус', function (): void {
    Clock::set('2026-11-03 11:00');
    $lead = Tests::lead();

    press("a:miss:{$lead['id']}");
    $row = Leads::find((int) $lead['id']);
    eq(Status::NO_ANSWER, $row['status']);
    eq(1, (int) $row['attempt']);
    eq('Записали недозвон (1 из 4)', lastAnswer()['notification']);

    press("a:talked:{$lead['id']}");
    eq(Status::TALKED, Leads::find((int) $lead['id'])['status']);

    press("a:contract:{$lead['id']}");
    $row = Leads::find((int) $lead['id']);
    eq(Status::CONTRACT, $row['status']);
    eq('Статус: придёт на договор', lastAnswer()['notification']);
    eq(null, $row['closed_at'], '«Придёт на договор» — заявка ещё открыта');
    eq(null, $row['next_action_at'], 'звонить больше не нужно');
    ok(str_contains(json_encode(lastAnswer()['message']['keyboard'], JSON_UNESCAPED_UNICODE), 'Записан'), 'кнопка «Записан» осталась');

    press("a:enrolled:{$lead['id']}");
    $row = Leads::find((int) $lead['id']);
    eq(Status::ENROLLED, $row['status']);
    ok($row['contacted_at'] !== null && $row['closed_at'] !== null, 'отметки дозвона и закрытия');
    eq(null, lastAnswer()['message']['keyboard'] ?: null, 'у закрытой заявки кнопок нет');

    press("a:miss:{$lead['id']}");
    eq('Заявка уже закрыта: Записан', lastAnswer()['notification']);
});

test('MAX: «Отказ» спрашивает причину', function (): void {
    Clock::set('2026-11-03 11:00');
    $lead = Tests::lead();

    press("a:refuse:{$lead['id']}");
    $answer = lastAnswer();
    eq(Status::NEW, Leads::find((int) $lead['id'])['status'], 'пока причина не выбрана, статус прежний');
    eq('Дорого', $answer['message']['keyboard'][0][0]['text']);
    eq("a:refuse:{$lead['id']}:1", $answer['message']['keyboard'][1][0]['payload']);

    press("a:refuse:{$lead['id']}:1");
    $row = Leads::find((int) $lead['id']);
    eq(Status::REFUSED, $row['status']);
    eq('Далеко', $row['refusal_reason']);
});

test('MAX: чужие не могут нажимать кнопки администраторов', function (): void {
    Clock::set('2026-11-03 11:00');
    $lead = Tests::lead();

    press("a:enrolled:{$lead['id']}", '777', '999');
    eq('Нет доступа', lastAnswer()['notification']);
    eq(Status::NEW, Leads::find((int) $lead['id'])['status']);
});

test('MAX: клиент открыл бота по ссылке из заявки', function (): void {
    Clock::set('2026-11-07 21:00');
    $lead = Tests::lead(['name' => 'анна', 'service' => 'Категория A', 'channel' => 'max']);
    eq('Здравствуйте! Это автошкола «Авто-Вираж». Удобнее переписка? Напишите нам в MAX: max.ru/stodorog_bot', Tests::$sms[0]['text']);

    Bot::handle(['update_type' => 'bot_started', 'chat_id' => 55, 'user' => ['user_id' => 3003, 'first_name' => 'Анна'], 'payload' => Max::leadPayload((int) $lead['id'])]);
    $row = Leads::find((int) $lead['id']);
    eq('3003', $row['max_user_id']);

    $greeting = array_values(array_filter(Tests::maxCalls(), static fn ($c) => ($c['to']['user_id'] ?? null) === '3003'))[0];
    ok(str_starts_with($greeting['text'], 'Здравствуйте, Анна! Сейчас мы не на связи — работаем Пн–Сб с 10:00 до 18:00. Ответим в понедельник после 10:00.'), $greeting['text']);
    eq('Позвоните утром', $greeting['keyboard'][0][0]['text']);

    // Подделанная подпись не привязывает чужую заявку.
    Bot::handle(['update_type' => 'bot_started', 'chat_id' => 56, 'user' => ['user_id' => 4004], 'payload' => 'L' . $lead['id'] . '_0000000000']);
    eq('3003', Leads::find((int) $lead['id'])['max_user_id']);
});

test('MAX: клиент выбирает время, отвечает «актуально» и пишет сообщение', function (): void {
    Clock::set('2026-11-03 11:00');
    $lead = Tests::lead();
    Bot::handle(['update_type' => 'bot_started', 'chat_id' => 55, 'user' => ['user_id' => 3003], 'payload' => Max::leadPayload((int) $lead['id'])]);

    Bot::handle(['update_type' => 'message_callback', 'callback' => ['callback_id' => 'x', 'payload' => "c:time:{$lead['id']}:evening", 'user' => ['user_id' => 3003]]]);
    $row = Leads::find((int) $lead['id']);
    eq('2026-11-03 17:00:00', $row['next_action_at']);
    eq('evening', $row['contact_time']);
    $sent = Tests::maxCalls();
    eq('Хорошо, позвоним сегодня после 17:00.', end($sent)['text']);

    // Чужой пользователь не может управлять заявкой.
    Bot::handle(['update_type' => 'message_callback', 'callback' => ['callback_id' => 'y', 'payload' => "c:actual:{$lead['id']}:no", 'user' => ['user_id' => 9999]]]);
    eq(Status::NEW, Leads::find((int) $lead['id'])['status']);

    Bot::handle(['update_type' => 'message_created', 'message' => [
        'sender' => ['user_id' => 3003, 'first_name' => 'Иван'],
        'recipient' => ['chat_id' => 55, 'chat_type' => 'dialog'],
        'body' => ['mid' => 'm1', 'text' => 'Есть группа на вечер?'],
    ]]);
    eq('Есть группа на вечер?', Leads::events((int) $lead['id'], ['max_in'])[0]['text']);
    $forward = Tests::maxCalls();
    $forward = end($forward);
    ok(str_contains($forward['text'], 'Есть группа на вечер?') && $forward['to'] === ['chat_id' => '-500'], 'сообщение переслано в чат');

    // Администратор отвечает на пересланное сообщение — ответ уходит клиенту.
    Bot::handle(['update_type' => 'message_created', 'message' => [
        'sender' => ['user_id' => 1001, 'first_name' => 'Ольга'],
        'recipient' => ['chat_id' => -500, 'chat_type' => 'chat'],
        'body' => ['mid' => 'm2', 'text' => 'Да, с 18:30 на Мира, 50'],
        'link' => ['type' => 'reply', 'message' => ['mid' => $forward['mid'], 'text' => $forward['text']]],
    ]]);
    $sent = Tests::maxCalls();
    eq(['user_id' => '3003'], end($sent)['to']);
    $row = Leads::find((int) $lead['id']);
    eq(Status::CHAT, $row['status']);
    eq('max', $row['contacted_via']);
});

test('MAX: «Уже не нужно» закрывает заявку отказом', function (): void {
    Clock::set('2026-11-03 11:00');
    $lead = Tests::lead();
    Bot::handle(['update_type' => 'bot_started', 'user' => ['user_id' => 3003], 'payload' => Max::leadPayload((int) $lead['id'])]);
    Bot::handle(['update_type' => 'message_callback', 'callback' => ['callback_id' => 'z', 'payload' => "c:actual:{$lead['id']}:no", 'user' => ['user_id' => 3003]]]);

    $row = Leads::find((int) $lead['id']);
    eq(Status::REFUSED, $row['status']);
    eq('Уже не нужно (ответ в MAX)', $row['refusal_reason']);
});

test('MAX: стартовый параметр подписан', function (): void {
    $payload = Max::leadPayload(148);
    ok(strlen($payload) <= 128, 'не длиннее 128 символов');
    eq(['type' => 'lead', 'id' => 148], Max::parsePayload($payload));
    eq(null, Max::parsePayload('L149' . substr($payload, 4)));
    eq(['type' => 'staff', 'id' => 5], Max::parsePayload(Max::staffPayload(5)));
});

test('MAX: заявка без имени — приветствие без обращения, имя берём из профиля MAX', function (): void {
    Clock::set('2026-11-03 11:00');
    $lead = Tests::lead(['name' => '']);

    eq('Здравствуйте! Это автошкола «Авто-Вираж».', mb_substr(App\Leads\Texts::render(App\Leads\Settings::get('bot_greeting_open'), ['name' => '', 'service_part' => '']), 0, 41));
    eq('Мы звонили вам по заявке, но не застали. Когда удобно поговорить?', App\Leads\Texts::render(App\Leads\Settings::get('bot_missed'), ['name' => '']));

    Bot::handle(['update_type' => 'bot_started', 'user' => ['user_id' => 3003, 'first_name' => 'Ольга', 'last_name' => 'Петрова'], 'payload' => Max::leadPayload((int) $lead['id'])]);
    eq('Ольга Петрова', Leads::find((int) $lead['id'])['name']);
    $greeting = Tests::maxCalls();
    ok(str_starts_with(end($greeting)['text'], 'Здравствуйте, Ольга!'), end($greeting)['text']);
});
