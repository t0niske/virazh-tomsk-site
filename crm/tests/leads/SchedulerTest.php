<?php

declare(strict_types=1);

use App\Leads\Actor;
use App\Leads\Clock;
use App\Leads\Leads;
use App\Leads\LeadService;
use App\Leads\Report;
use App\Leads\Scheduler;
use App\Leads\Status;
use App\Support\Db;

test('Планировщик: утренняя очередь в 10:00 по ночным заявкам', function (): void {
    Clock::set('2026-11-07 20:00');
    Tests::lead(['name' => 'Анна', 'service' => 'B (МКПП)', 'contact_time' => 'morning']);
    Clock::set('2026-11-08 12:00');
    Tests::lead(['name' => 'Мария', 'phone' => '+79130000002', 'channel' => 'max']);

    App\Leads\Settings::set('cron.weekly', '2026-46');
    App\Leads\Settings::set('cron.daily', '2026-11-09');
    Clock::set('2026-11-09 09:59');
    eq([], Scheduler::run(), 'до открытия ничего');

    Clock::set('2026-11-09 10:00');
    eq(['Утренняя очередь: 2'], Scheduler::run());
    $queue = Tests::maxCalls();
    $queue = end($queue)['text'];
    ok(str_contains($queue, '1. №') && str_contains($queue, 'Анна — B (МКПП), удобно утром'), $queue);
    ok(str_contains($queue, 'Мария — услуга не указана, лучше MAX'), $queue);

    Clock::set('2026-11-09 10:01');
    eq([], Scheduler::run(), 'очередь не повторяется');
});

test('Планировщик: напоминания через 15 минут в чат и через 30 руководителю', function (): void {
    Db::pdo()->exec("INSERT INTO staff (login, name, role, password_hash, max_user_id, active, created_at) VALUES ('boss', 'Руководитель', 'manager', 'x', '7007', 1, '2026-01-01 00:00:00')");
    Settings_set_morning_done('2026-11-03');
    Clock::set('2026-11-03 11:00');
    $lead = Tests::lead();

    Clock::set('2026-11-03 11:14');
    eq([], Scheduler::run());

    Clock::set('2026-11-03 11:15');
    eq(["Напоминание о №{$lead['id']}"], Scheduler::run());
    $calls = Tests::maxCalls();
    eq("⏰ Заявку №{$lead['id']} никто не взял уже 15 минут.", end($calls)['text']);

    Clock::set('2026-11-03 11:30');
    eq(["Руководителю о №{$lead['id']}"], Scheduler::run());
    $calls = Tests::maxCalls();
    eq(['user_id' => '7007'], end($calls)['to']);

    Clock::set('2026-11-03 11:45');
    eq([], Scheduler::run(), 'больше не напоминаем');
});

test('Планировщик: взятую заявку не напоминаем', function (): void {
    Settings_set_morning_done('2026-11-03');
    Clock::set('2026-11-03 11:00');
    $lead = Tests::lead();
    LeadService::take($lead, new Actor(null, 'Ольга'));

    Clock::set('2026-11-03 11:40');
    eq([], Scheduler::run());
});

test('Планировщик: повторный звонок, сообщение на 3-й день и «не дозвонились»', function (): void {
    Settings_set_morning_done('2026-11-03');
    Clock::set('2026-11-03 10:00');
    $lead = Tests::lead();
    $actor = new Actor(null, 'Ольга');

    Clock::set('2026-11-03 10:05');
    $lead = LeadService::miss($lead, $actor);

    Clock::set('2026-11-03 11:35');
    eq(["Напоминание о звонке №{$lead['id']}"], Scheduler::run());
    $calls = Tests::maxCalls();
    ok(str_contains(end($calls)['text'], 'Пора звонить') && str_contains(end($calls)['text'], 'попытка 2 из 4'), end($calls)['text']);

    $lead = LeadService::miss(Leads::find((int) $lead['id']), $actor);
    $lead = LeadService::miss($lead, $actor);
    $lead = LeadService::miss($lead, $actor);
    eq('final_message', $lead['next_action']);

    Clock::set($lead['next_action_at']);
    Settings_set_morning_done(substr($lead['next_action_at'], 0, 10));
    eq(["Последнее сообщение по №{$lead['id']}"], Scheduler::run());
    eq('Здравствуйте! Это автошкола «Авто-Вираж». Ваша заявка на обучение ещё актуальна? Позвоните нам: +7 (983) 234-56-56.', end(Tests::$sms)['text']);

    $lead = Leads::find((int) $lead['id']);
    Clock::set($lead['next_action_at']);
    Settings_set_morning_done(substr($lead['next_action_at'], 0, 10));
    eq(["№{$lead['id']} — не дозвонились"], Scheduler::run());
    eq(Status::UNREACHABLE, Leads::find((int) $lead['id'])['status']);
});

test('Планировщик: звонок в выбранное клиентом время', function (): void {
    Settings_set_morning_done('2026-11-03');
    Clock::set('2026-11-03 11:00');
    $lead = Tests::lead(['contact_time' => 'evening']);
    eq(null, $lead['notified_at']);

    Clock::set('2026-11-03 16:59');
    eq([], Scheduler::run());
    Clock::set('2026-11-03 17:00');
    eq(["Напоминание о звонке №{$lead['id']}"], Scheduler::run());
});

test('Планировщик: еженедельный отчёт по понедельникам и автоудаление через 12 месяцев', function (): void {
    Clock::set('2025-10-01 12:00');
    $old = Tests::lead(['phone' => '+79130000001']);
    $oldEnrolled = Tests::lead(['phone' => '+79130000002']);
    LeadService::setStatus($oldEnrolled, Status::ENROLLED, Actor::system());

    Clock::set('2026-10-27 11:00');
    $lead = Tests::lead(['phone' => '+79130000003']);
    Clock::set('2026-10-27 11:06');
    LeadService::setStatus(LeadService::take($lead, Actor::system()), Status::ENROLLED, Actor::system());

    Clock::set('2026-11-02 10:00');
    $done = Scheduler::run();
    ok(in_array('Еженедельный отчёт отправлен', $done, true), implode('; ', $done));
    ok(in_array('Удалено старых заявок: 1', $done, true), implode('; ', $done));
    eq(null, Leads::find((int) $old['id']));
    ok(Leads::find((int) $oldEnrolled['id']) !== null, 'записанных клиентов не удаляем');

    $report = array_values(array_filter(Tests::maxCalls(), static fn ($c) => str_starts_with($c['text'], 'Руководителю: 📊')))[0]['text'];
    ok(str_contains($report, 'Заявки за 26 октября – 1 ноября'), $report);
    ok(str_contains($report, 'Среднее время до звонка: 6 мин'), $report);
    ok(str_contains($report, 'Записались: 1'), $report);
});

test('Отчёт: показатели за период', function (): void {
    Clock::set('2026-11-03 11:00');
    $a = Tests::lead(['phone' => '+79130000001', 'utm_source' => 'vk']);
    Clock::set('2026-11-03 11:10');
    LeadService::setStatus(LeadService::take($a, Actor::system()), Status::REFUSED, Actor::system(), 'Дорого');
    $b = Tests::lead(['phone' => '+79130000002', 'channel' => 'max']);
    LeadService::setStatus($b, Status::JUNK, Actor::system());

    $r = Report::build(new DateTimeImmutable('2026-11-02'), new DateTimeImmutable('2026-11-09'));
    eq(2, $r['total']);
    eq(1, $r['junk']);
    eq(1, $r['contacted']);
    eq(['Дорого' => 1], $r['reasons']);
    eq(10, $r['avg_response_min']);
    eq(1, $r['sources']['ВКонтакте']['total']);
});

/** Утренняя очередь за день уже прошла — чтобы тест проверял только нужную задачу. */
function Settings_set_morning_done(string $date): void
{
    App\Leads\Settings::set('cron.morning', $date);
    App\Leads\Settings::set('cron.daily', $date);
    App\Leads\Settings::set('cron.weekly', (new DateTimeImmutable($date))->format('o-W'));
}
