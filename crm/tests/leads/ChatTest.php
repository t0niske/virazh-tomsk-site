<?php

declare(strict_types=1);

use App\Chat\Chat;
use App\Chat\DeepSeek;
use App\Chat\Knowledge;
use App\Chat\Redactor;
use App\Leads\Clock;
use App\Leads\Leads;
use App\Support\Db;

const CHAT_ID = 'chatWindowAAAAAAAAAAAA';

// Справка для ИИ — из базы помощника на сайте (assets/js/bot-kb.json); в тестах — своя маленькая база.
$kb = sys_get_temp_dir() . '/virazh-chat-kb-' . getmypid() . '.json';
file_put_contents($kb, json_encode(['special' => [], 'chips' => [], 'items' => [
    ['q' => 'Сколько стоит обучение?', 'a' => '<p>Категория B на автомате — <b>66 000&nbsp;₽</b>.</p><p><a href="/tseny/tseny-tomsk">Все цены</a> <button class="btn" data-signup>Записаться</button></p>', 'k' => '', 'w' => 1],
    ['q' => 'Есть ли «Авто-Вираж» в Новосибирске?', 'a' => '<p>Да, только категория B — 65 100 ₽.<br>Телефон +7 (383) 205-24-34.</p>', 'k' => '', 'w' => 1],
    ['q' => 'Есть ли демо-урок?', 'a' => '<p>Да: <a href="https://lk.virazh-tomsk.ru/demolesson" target="_blank" rel="noopener">открыть демо-урок</a>.</p>', 'k' => '', 'w' => 1],
]], JSON_UNESCAPED_UNICODE));
Knowledge::useKb($kb);
register_shutdown_function(static fn () => @unlink($kb));

test('Чат: справка — правила Виража и факты с сайта без разметки', function (): void {
    $prompt = Chat::systemPrompt();
    ok(str_contains($prompt, 'помощник автошколы «Авто-Вираж»'), 'правила Виража');
    ok(str_contains($prompt, 'Новосибирск') && str_contains($prompt, 'никогда их не смешивай'), 'правило про два города');
    ok(str_contains($prompt, "В: Сколько стоит обучение?
О: Категория B на автомате — 66 000 ₽.
[Все цены](/tseny/tseny-tomsk)"), $prompt);
    ok(str_contains($prompt, "Да, только категория B — 65 100 ₽.
Телефон +7 (383) 205-24-34."), 'перенос строки вместо <br>');
    ok(!str_contains($prompt, '<') && !str_contains($prompt, 'Записаться'), 'без HTML и кнопок');
    ok(str_contains($prompt, 'Да: открыть демо-урок (lk.virazh-tomsk.ru/demolesson).'), 'адрес внешней ссылки остаётся текстом');
    ok(!str_contains($prompt, 'Сто дорог'), 'ни слова о другой школе');
});

test('Чат: без справки с сайта ИИ не отвечает (503), сайт показывает готовые ответы', function () use ($kb): void {
    fakeModel();
    Knowledge::useKb(sys_get_temp_dir() . '/net-takogo-fajla.json');
    try {
        [$code, $body] = Chat::handle(CHAT_ID, 'Сколько стоит?', '10.9.9.9');
        eq(503, $code);
        eq(true, $body['fallback']);
    } finally {
        Knowledge::useKb($kb);
    }
});

/** Заглушка DeepSeek: запоминает, что ей отправили, отвечает заданным текстом или ошибкой. */
final class FakeModel
{
    /** @var list<list<array{role: string, content: string}>> */
    public array $requests = [];

    public function __construct(public string $reply = 'Обучение на категорию B (АКПП) стоит 61 200 ₽.', public int $status = 200)
    {
    }

    public function complete(array $messages): array
    {
        $this->requests[] = $messages;

        return $this->status === 200
            ? ['ok' => true, 'text' => $this->reply, 'status' => 200, 'error' => null]
            : ['ok' => false, 'text' => '', 'status' => $this->status, 'error' => 'Insufficient Balance'];
    }
}

function fakeModel(string $reply = 'Обучение на категорию B (АКПП) стоит 61 200 ₽.', int $status = 200): FakeModel
{
    $model = new FakeModel($reply, $status);
    Chat::useModel($model);

    return $model;
}

test('Чат: телефоны, почта, ссылки и номера документов вырезаются', function (): void {
    eq('Мой номер [телефон], перезвоните', Redactor::clean('Мой номер 8 (913) 123-45-67, перезвоните'));
    eq('пишите на [почта] или [телефон]', Redactor::clean('пишите на ivan.petrov@mail.ru или +7 913 1234567'));
    eq('сайт [ссылка] и [ссылка]', Redactor::clean('сайт https://evil.example/x?y=1 и vk.com/id1'));
    eq('снилс [номер]', Redactor::clean('снилс 12345678901'));
    eq('[телефон] перезвоните', Redactor::clean('9131234567 перезвоните'));
    eq('цена 61 200 ₽ и 63100, 13.11.2026 в 18:00, группа на 15 человек', Redactor::clean('цена 61 200 ₽ и 63100, 13.11.2026 в 18:00, группа на 15 человек'));
});

test('Чат: ответ ИИ, в DeepSeek не уходят телефон, IP и номер чата', function (): void {
    $model = fakeModel();
    Clock::set('2026-11-14 12:00:00');

    [$code, $body] = Chat::handle(CHAT_ID, 'Сколько стоит категория B? Мой телефон +7 913 111-22-33', '10.1.1.1');
    eq(200, $code);
    eq(['reply' => 'Обучение на категорию B (АКПП) стоит 61 200 ₽.', 'operator' => false], $body);

    $sent = json_encode($model->requests[0], JSON_UNESCAPED_UNICODE);
    // Во всём запросе нет номера клиента; в справке школы могут быть её собственные номера (913…), поэтому
    // «913» ищем только в репликах посетителя.
    $userText = implode(' ', array_column(array_filter($model->requests[0], static fn (array $m): bool => $m['role'] === 'user'), 'content'));
    ok(!str_contains($sent, '111-22-33') && !str_contains($sent, '9131112233'), 'телефон не ушёл');
    ok(!str_contains($userText, '913'), 'в репликах посетителя нет цифр телефона');
    ok(!str_contains($sent, '10.1.1.1') && !str_contains($sent, CHAT_ID), 'IP и номер чата не ушли');
    eq('system', $model->requests[0][0]['role']);
    eq('Сколько стоит категория B? Мой телефон [телефон]', end($model->requests[0])['content']);

    // В базе — тоже уже без телефона.
    $stored = Db::pdo()->query("SELECT text FROM chat_messages WHERE role = 'user'")->fetchColumn();
    eq('Сколько стоит категория B? Мой телефон [телефон]', $stored);

    // Второе сообщение уходит с историей.
    Chat::handle(CHAT_ID, 'А на механике?', '10.1.1.1');
    eq(['system', 'user', 'assistant', 'user'], array_column($model->requests[1], 'role'));
});

test('Чат: [ОПЕРАТОР] убирается из ответа и включает кнопку «Позвоните мне»', function (): void {
    fakeModel("Это лучше уточнить у администратора.\n[ОПЕРАТОР]");
    $dir = getenv('LEADS_LOG_DIR');
    @unlink("$dir/telegram.log");

    [, $body] = Chat::handle(CHAT_ID, 'Можно записаться на завтра?', '10.1.1.2');
    eq(['reply' => 'Это лучше уточнить у администратора.', 'operator' => true], $body);
    eq(1, (int) Chat::findByChatId(CHAT_ID)['operator']);

    $telegram = (string) file_get_contents("$dir/telegram.log");
    ok(str_contains($telegram, 'В чате на сайте нужен оператор') && !str_contains($telegram, 'записаться'), 'оповещение без текста чата');

    Chat::handle(CHAT_ID, 'Ещё вопрос', '10.1.1.2');
    eq(1, substr_count((string) file_get_contents("$dir/telegram.log"), 'нужен оператор'), 'оповещение одно на чат');
});

test('Чат: лимиты — 20 сообщений в чате, 10 за 5 минут с IP, сутки на сайт', function (): void {
    fakeModel();
    Clock::set('2026-11-14 12:00:00');

    for ($i = 1; $i <= 10; $i++) {
        eq(200, Chat::handle(CHAT_ID, "Вопрос $i", '10.2.2.2')[0]);
    }
    [$code, $body] = Chat::handle(CHAT_ID, 'Вопрос 11', '10.2.2.2');
    eq(429, $code, '11-е сообщение за 5 минут');
    ok(str_contains($body['reply'], 'Позвоните нам: +7 (983) 234-56-56') && $body['operator'], $body['reply']);

    Clock::set('2026-11-14 12:06:00');
    for ($i = 11; $i <= 20; $i++) {
        Chat::handle(CHAT_ID, "Вопрос $i", '10.2.2.2');
    }
    eq(429, Chat::handle(CHAT_ID, 'Вопрос 21', '10.2.2.2')[0], 'больше 20 в одном чате');

    putenv('CHAT_DAILY_LIMIT=2');
    try {
        Chat::handle('chatWindowBBBBBBBBBBBB', 'Привет', '10.3.3.3');
        eq(429, Chat::handle('chatWindowBBBBBBBBBBBB', 'Ещё', '10.3.3.3')[0], 'лимит ответов на весь сайт');
    } finally {
        putenv('CHAT_DAILY_LIMIT=500');
    }
});

test('Чат: нет денег (402) — вежливый ответ с телефоном и одно оповещение в сутки', function (): void {
    fakeModel(status: 402);
    $dir = getenv('LEADS_LOG_DIR');
    @unlink("$dir/telegram.log");
    Clock::set('2026-11-14 12:00:00');

    [$code, $body] = Chat::handle(CHAT_ID, 'Сколько стоит?', '10.4.4.4');
    eq(200, $code);
    ok(str_contains($body['reply'], 'Не получилось ответить. Позвоните нам: +7 (983) 234-56-56') && $body['operator'] === true, $body['reply']);
    eq(true, $body['fallback'], 'сайт покажет свой готовый ответ');

    Chat::handle(CHAT_ID, 'Ау?', '10.4.4.4');
    $telegram = (string) file_get_contents("$dir/telegram.log");
    eq(1, substr_count($telegram, 'закончились деньги'), 'одно оповещение в сутки');
    ok(!str_contains($telegram, 'Сколько стоит'), 'без текстов чата');
});

test('Чат: без ключа выключен, неверные данные — 422', function (): void {
    eq(503, Chat::handle(CHAT_ID, 'Привет', '10.5.5.5')[0], 'ключа нет — 503');

    fakeModel();
    eq(422, Chat::handle('short', 'Привет', '10.5.5.5')[0]);
    eq(422, Chat::handle(CHAT_ID, '   ', '10.5.5.5')[0]);
});

test('Чат: «Позвоните мне» — заявка связывается с перепиской', function (): void {
    fakeModel("Позову администратора.\n[ОПЕРАТОР]");
    Clock::set('2026-11-14 12:00:00');
    Chat::handle(CHAT_ID, 'Хочу записаться', '10.6.6.6');

    $lead = Tests::lead(['service' => 'Вопрос из чата', 'chat_id' => CHAT_ID]);
    eq(CHAT_ID, $lead['chat_id']);
    eq((int) $lead['id'], (int) Chat::findByChatId(CHAT_ID)['lead_id']);

    // Очистка: переписка с заявкой хранится, без заявки старше 3 месяцев — удаляется.
    Clock::set('2026-07-01 12:00:00');
    Chat::handle('chatWindowOLDOLDOLDOLD', 'Старый вопрос', '10.7.7.7');
    eq(1, Chat::cleanup(new DateTimeImmutable('2026-11-14 12:00:00')));
    ok(Chat::findByChatId(CHAT_ID) !== null, 'чат с заявкой на месте');
    eq(null, Chat::findByChatId('chatWindowOLDOLDOLDOLD'));
});

test('Чат: запрос к DeepSeek по контракту и разбор ответа', function (): void {
    $payload = (new DeepSeek('key', 'deepseek-flash'))->payload([['role' => 'user', 'content' => 'Привет']]);
    eq(['model' => 'deepseek-flash', 'thinking' => ['type' => 'disabled'], 'messages' => [['role' => 'user', 'content' => 'Привет']],
        'max_tokens' => 400, 'temperature' => 0.3, 'stream' => false], $payload);

    eq(['ok' => true, 'text' => 'Здравствуйте!', 'status' => 200, 'error' => null],
        DeepSeek::parse(200, '{"choices":[{"message":{"role":"assistant","content":" Здравствуйте! "}}]}'));
    eq(402, DeepSeek::parse(402, '{"error":{"message":"Insufficient Balance"}}')['status']);
    eq('Insufficient Balance', DeepSeek::parse(402, '{"error":{"message":"Insufficient Balance"}}')['error']);
});
