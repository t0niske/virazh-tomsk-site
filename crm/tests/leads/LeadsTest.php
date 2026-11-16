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

test('График попыток: 1–2 часа, другое время суток, следующий день, сообщение на 3-й день', function (): void {
    $s = new Schedule();
    $created = new DateTimeImmutable('2026-11-03 10:00');

    $step = $s->afterMiss(1, new DateTimeImmutable('2026-11-03 10:05'), $created);
    eq(['call', '2026-11-03 11:35'], [$step['action'], $step['at']->format('Y-m-d H:i')], 'повтор через полтора часа');

    $step = $s->afterMiss(2, new DateTimeImmutable('2026-11-03 11:35'), $created);
    eq(['call', '2026-11-03 17:00'], [$step['action'], $step['at']->format('Y-m-d H:i')], 'утром не ответил — звоним ближе к вечеру');

    $step = $s->afterMiss(3, new DateTimeImmutable('2026-11-03 17:00'), $created);
    eq(['call', '2026-11-04 13:00'], [$step['action'], $step['at']->format('Y-m-d H:i')], 'на следующий день, днём');

    $step = $s->afterMiss(4, new DateTimeImmutable('2026-11-04 13:00'), $created);
    eq(['final_message', '2026-11-05 13:00'], [$step['action'], $step['at']->format('Y-m-d H:i')], 'на третий день — «ещё актуальна?»');

    eq('2026-11-06 13:00', $s->giveUpAt(new DateTimeImmutable('2026-11-05 13:00'))->format('Y-m-d H:i'));
});

test('График попыток: поздние звонки переносятся на рабочее время', function (): void {
    $s = new Schedule();
    $created = new DateTimeImmutable('2026-11-07 16:00');

    $step = $s->afterMiss(1, new DateTimeImmutable('2026-11-07 17:30'), $created);
    eq('2026-11-09 10:00', $step['at']->format('Y-m-d H:i'), 'суббота 17:30 + 1,5 часа → понедельник утром');

    $step = $s->afterMiss(2, new DateTimeImmutable('2026-11-09 15:00'), $created);
    eq('2026-11-10 10:00', $step['at']->format('Y-m-d H:i'), 'днём не ответил — следующим утром');

    $step = $s->afterMiss(4, new DateTimeImmutable('2026-11-10 16:00'), $created);
    eq('2026-11-11 10:00', $step['at']->format('Y-m-d H:i'), 'третий день уже прошёл — ближайшее рабочее время');
});

test('График попыток: недозвоны через сервис, сообщение клиенту только после первого', function (): void {
    Clock::set('2026-11-03 10:00');
    $lead = Tests::lead();
    $actor = new Actor(null, 'Ольга');

    Clock::set('2026-11-03 10:05');
    $lead = LeadService::miss($lead, $actor);
    eq(Status::NO_ANSWER, $lead['status']);
    eq(1, (int) $lead['attempt']);
    eq('2026-11-03 11:35:00', $lead['next_action_at']);
    eq('Здравствуйте! Это автошкола «Авто-Вираж». Мы звонили вам по заявке, но не дозвонились. Перезвоните, пожалуйста: +7 (983) 234-56-56.', end(Tests::$sms)['text']);

    Clock::set('2026-11-03 11:35');
    $lead = LeadService::miss($lead, $actor);
    eq(2, count(Tests::$sms), 'после второго недозвона SMS не шлём');
    eq('2026-11-03 17:00:00', $lead['next_action_at']);

    $lead = LeadService::miss(LeadService::miss($lead, $actor), $actor);
    eq('final_message', $lead['next_action']);
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
