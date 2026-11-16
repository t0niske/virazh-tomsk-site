<?php

declare(strict_types=1);

use App\Leads\Actor;
use App\Leads\Clock;
use App\Leads\Leads;
use App\Leads\LeadService;
use App\Leads\Schedule;
use App\Leads\Status;

test('Дубли: повторная заявка с того же номера дописывается к первой', function (): void {
    Clock::set('2026-11-03 11:00');
    $first = Tests::lead(['service' => 'Категория B (АКПП)']);

    Clock::set('2026-11-03 11:20');
    $second = Tests::lead(['phone' => '8 (913) 000-00-00', 'service' => 'Категория A']);

    eq($first['id'], $second['id']);
    eq(2, (int) $second['request_count']);
    eq('Категория A', $second['service']);
    eq(1, count(Tests::$sms), 'одно SMS «заявка принята» в сутки');
    eq(['created', 'sms', 'repeat'], array_column(Leads::events((int) $first['id']), 'type'));
});

test('Дубли: через 30 дней — новая заявка', function (): void {
    Clock::set('2026-10-01 11:00');
    $first = Tests::lead();

    Clock::set('2026-11-03 11:00');
    $second = Tests::lead();

    ok($first['id'] !== $second['id'], 'должна появиться новая заявка');
    eq(2, count(Tests::$sms));
});

test('Дубли: закрытая отказом заявка открывается снова', function (): void {
    Clock::set('2026-11-03 11:00');
    $lead = Tests::lead();
    LeadService::setStatus($lead, Status::REFUSED, Actor::system(), 'Дорого');

    Clock::set('2026-11-05 12:00');
    $again = Tests::lead();

    eq($lead['id'], $again['id']);
    eq(Status::NEW, $again['status']);
    eq(null, $again['refusal_reason']);
    eq(2, count(Tests::$sms), 'на следующий день SMS снова уходит');
    eq(2, count(array_filter(Tests::maxCalls(), static fn ($c) => str_contains($c['text'], 'Новая заявка'))), 'новая карточка в чате');
});

test('Приём: ночная заявка ждёт утра и не будит чат', function (): void {
    Clock::set('2026-11-07 22:00');
    $lead = Tests::lead(['service' => 'Категория B (МКПП)']);

    eq('2026-11-09 10:00:00', $lead['due_at']);
    eq(1, (int) $lead['off_hours']);
    eq(null, $lead['notified_at']);
    eq('Здравствуйте! Это автошкола «Авто-Вираж». Спасибо за заявку — позвоним в понедельник с номера +7 (983) 234-56-56.', Tests::$sms[0]['text']);
    eq(false, Tests::maxCalls()[0]['notify'], 'карточка без звука ночью');
});

test('График попыток: утром не дозвонились — днём, днём — вечером, вечером — сообщение', function (): void {
    $s = new Schedule();

    $step = $s->afterMiss(new DateTimeImmutable('2026-11-03 10:05'));
    eq(['call', '2026-11-03 13:00'], [$step['action'], $step['at']->format('Y-m-d H:i')], 'утром не ответил — звоним днём');

    $step = $s->afterMiss(new DateTimeImmutable('2026-11-03 13:10'));
    eq(['call', '2026-11-03 17:00'], [$step['action'], $step['at']->format('Y-m-d H:i')], 'днём не ответил — звоним вечером');

    $step = $s->afterMiss(new DateTimeImmutable('2026-11-03 17:05'));
    eq(['give_up', '2026-11-04 17:05'], [$step['action'], $step['at']->format('Y-m-d H:i')], 'вечером не ответил — сообщение, «Не дозвонились» через сутки');

    $step = $s->afterMiss(new DateTimeImmutable('2026-11-03 12:40'));
    eq('2026-11-03 13:40', $step['at']->format('Y-m-d H:i'), 'между звонками не меньше часа');
});

test('График попыток: SMS — после вечернего недозвона, сколько бы звонков ни было', function (): void {
    $s = new Schedule();

    eq('give_up', $s->afterMiss(new DateTimeImmutable('2026-11-03 17:30'))['action'], 'заявка на вечер: один вечерний недозвон — уже сообщение');
    eq('give_up', $s->afterMiss(new DateTimeImmutable('2026-11-03 19:00'))['action'], 'после закрытия — как вечер');

    $step = $s->afterMiss(new DateTimeImmutable('2026-11-03 16:50'));
    eq(['call', '2026-11-03 17:50'], [$step['action'], $step['at']->format('Y-m-d H:i')], 'заявка на день: днём не ответил — вечером, через час');

    $late = new Schedule(end: '17:30');
    $step = $late->afterMiss(new DateTimeImmutable('2026-11-03 16:50'));
    eq('2026-11-04 17:00', $step['at']->format('Y-m-d H:i'), 'час до вечернего звонка не помещается — завтра вечером');

    $step = $s->afterMiss(new DateTimeImmutable('2026-11-08 12:00'));
    eq('2026-11-09 13:00', $step['at']->format('Y-m-d H:i'), 'нажали в воскресенье в 12:00 (утро) — днём в рабочий день');
});

test('График попыток: SMS «не дозвонились» только после последнего звонка', function (): void {
    Clock::set('2026-11-03 10:00');
    $lead = Tests::lead();
    $sent = count(Tests::$sms);
    $actor = new Actor(null, 'Ольга');

    Clock::set('2026-11-03 10:05');
    $lead = LeadService::miss($lead, $actor);
    eq(Status::NO_ANSWER, $lead['status']);
    eq(1, (int) $lead['attempt']);
    eq('2026-11-03 13:00:00', $lead['next_action_at']);
    eq($sent, count(Tests::$sms), 'после первого недозвона SMS не шлём');

    Clock::set('2026-11-03 13:05');
    $lead = LeadService::miss($lead, $actor);
    eq('2026-11-03 17:00:00', $lead['next_action_at']);
    eq($sent, count(Tests::$sms), 'после второго тоже');

    Clock::set('2026-11-03 17:05');
    $lead = LeadService::miss($lead, $actor);
    eq('give_up', $lead['next_action']);
    eq('2026-11-04 17:05:00', $lead['next_action_at']);
    eq('Здравствуйте! Это автошкола «Авто-Вираж». Мы звонили вам по заявке, но не дозвонились. Перезвоните, пожалуйста: +7 (983) 234-56-56.', end(Tests::$sms)['text']);
});

test('Без имени: заявка только с телефоном, имя дополняется позже', function (): void {
    Clock::set('2026-11-03 11:00');
    $lead = Tests::lead(['name' => '']);
    eq(Leads::NO_NAME, $lead['name']);

    // Повторная заявка с именем дописывает его.
    $lead = Tests::lead(['name' => 'Анна']);
    eq('Анна', $lead['name']);
    ok(str_contains($lead['search'], 'анна'), 'поиск по новому имени');

    // А имя уже известной заявки «Без имени» не затирает.
    $lead = Tests::lead(['name' => 'Без имени']);
    eq('Анна', $lead['name']);
});

test('Карточка: блок «Звонки» — утро не ответил, сейчас день, дальше вечер и сообщение', function (): void {
    Clock::set('2026-11-03 10:00');
    $lead = Tests::lead();
    Clock::set('2026-11-03 10:05');
    $lead = LeadService::miss($lead, new Actor(null, 'Ольга'));

    Clock::set('2026-11-03 11:00');
    $plan = App\Crm\CallPlan::build($lead, Leads::events((int) $lead['id']), new Schedule(), Clock::now());
    eq('call', $plan['actions']);
    eq(['miss', 'now', 'later', 'later'], array_column($plan['rows'], 'state'));
    eq(['Утро', 'День', 'Вечер', 'SMS'], array_column($plan['rows'], 'label'));
    eq('сегодня 10:05', $plan['rows'][0]['when']);
    eq('Не ответил · Ольга', $plan['rows'][0]['text']);
    eq('сегодня в 13:00', $plan['rows'][1]['when']);
    eq('Не ответит — звонок вечером, сегодня с 17:00', $plan['rows'][1]['hint']);

    Clock::set('2026-11-03 17:05');
    $plan = App\Crm\CallPlan::build($lead, Leads::events((int) $lead['id']), new Schedule(), Clock::now());
    eq(['miss', 'now', 'later'], array_column($plan['rows'], 'state'), 'звонок просрочен — «сейчас», и он вечерний');
    eq('Позвоните сейчас', $plan['rows'][1]['text']);
    eq('Не ответит — клиенту уйдёт SMS «не дозвонились»', $plan['rows'][1]['hint']);
});

test('Карточка: после SMS — «ждём звонка», «Придёт на договор» с текстом «когда ждать»', function (): void {
    Clock::set('2026-11-03 17:00');
    $lead = LeadService::miss(Tests::lead(), new Actor(null, 'Ольга'));
    $plan = App\Crm\CallPlan::build($lead, Leads::events((int) $lead['id']), new Schedule(), Clock::now());
    eq('result', $plan['actions'], 'после SMS кнопки «Не ответил» нет');
    eq(['miss', 'done', 'now'], array_column($plan['rows'], 'state'));
    eq('до завтра 17:00', $plan['rows'][2]['when']);

    $lead = LeadService::setStatus($lead, Status::CONTRACT, new Actor(null, 'Ольга'), null, '  во вторник после 15:00, Мира 50 ');
    eq('во вторник после 15:00, Мира 50', $lead['contract_note']);
    ok(str_contains(App\Max\AdminChat::statusLine($lead), 'ждём: во вторник после 15:00'), App\Max\AdminChat::statusLine($lead));
    $events = Leads::events((int) $lead['id']);
    eq('Придёт на договор: во вторник после 15:00, Мира 50', end($events)['text']);
    $plan = App\Crm\CallPlan::build($lead, $events, new Schedule(), Clock::now());
    eq('Придёт на договор: во вторник после 15:00, Мира 50', end($plan['rows'])['text']);

    $lead = LeadService::setStatus($lead, Status::ENROLLED, new Actor(null, 'Ольга'));
    eq(null, $lead['contract_note'], 'после «Записан» текст не нужен');
    eq('none', App\Crm\CallPlan::build($lead, Leads::events((int) $lead['id']), new Schedule(), Clock::now())['actions']);
});

test('Роботы: форма быстрее 3 секунд — не заявка; без скриптов или без метки — заявка с пометкой', function (): void {
    $now = 1_800_000_000;
    $token = App\Support\FormToken::make($now - 20);
    $check = static fn (array $in): array => App\Leads\Http\LeadController::robotCheck($in, $now);

    eq(['block' => null, 'suspect' => null], $check(['form_ts' => $token, 'js' => '1']), 'человек: метка 20 с назад и скрипты');
    eq('Форма отправлена за 1 с после открытия страницы', $check(['form_ts' => App\Support\FormToken::make($now - 1), 'js' => '1'])['block']);
    eq('Форма отправлена за 0 с после открытия страницы', $check(['form_ts' => App\Support\FormToken::make($now), 'js' => ''])['block'], 'бомбер: GET и POST в одну секунду');
    eq(['block' => null, 'suspect' => 'форма без скриптов'], $check(['form_ts' => $token, 'js' => '']));
    eq(['block' => null, 'suspect' => 'нет метки формы'], $check(['js' => '1']));
    eq(['block' => null, 'suspect' => 'форма без скриптов, нет метки формы'], $check([]), 'старая страница или робот без скриптов');
    eq('нет метки формы', $check(['form_ts' => ($now - 60) . '.0123456789abcdef', 'js' => '1'])['suspect'], 'поддельная подпись');
});

test('Роботы: заявка «похоже на робота» — без SMS, с пометкой в истории, в MAX и в Telegram', function (): void {
    Clock::set('2026-11-16 11:00');
    $sms = count(Tests::$sms);
    $data = App\Leads\LeadForm::validate(['name' => 'Робот', 'phone' => '+7 913 000-00-55', 'consent' => '1'])['data'];
    $lead = LeadService::accept($data + ['suspect' => 'форма без скриптов'])['lead'];
    eq('форма без скриптов', $lead['suspect']);
    eq($sms, count(Tests::$sms), 'SMS не отправлено');

    $card = App\Max\AdminChat::cardText($lead, '🔔 Заявка');
    ok(str_contains($card, '⚠️ Проверьте, не робот ли: форма без скриптов. SMS клиенту не отправлено.'), $card);
    ok(str_contains(App\Leads\Notify\Telegram::text($lead, false), '⚠️ Похоже на робота: форма без скриптов'), 'Telegram');
    $events = Leads::events((int) $lead['id']);
    ok(str_contains($events[0]['text'], 'Похоже на робота: форма без скриптов — SMS не отправлено.'), $events[0]['text']);

    $normal = Tests::lead(['phone' => '+79130000077']);
    eq(null, $normal['suspect']);
    eq($sms + 1, count(Tests::$sms), 'обычной заявке SMS уходит');
});

test('Роботы: метка формы подписана секретом Виража (LEADS_SECRET), чужую или исправленную не принимаем', function (): void {
    $token = App\Support\FormToken::make(1_800_000_000);
    eq(5, App\Support\FormToken::age($token, 1_800_000_005));
    eq(null, App\Support\FormToken::age('1800000000.' . str_repeat('0', 16), 1_800_000_005), 'подпись не сходится');
    eq(null, App\Support\FormToken::age('1799999000' . substr($token, 10), 1_800_000_005), 'время исправлено руками');

    putenv('LEADS_SECRET=другой-ключ');
    try {
        eq(null, App\Support\FormToken::age($token, 1_800_000_005), 'с другим секретом метка недействительна');
    } finally {
        putenv('LEADS_SECRET=test-key');
    }
});
