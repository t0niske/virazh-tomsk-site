<?php

declare(strict_types=1);

namespace App\Leads;

use App\Chat\Chat;
use App\Max\AdminChat;
use App\Visits\Visits;
use App\Max\Keyboard;
use App\Support\Db;
use DateTimeImmutable;

/**
 * Задачи, которые cron/run.php выполняет раз в минуту. Каждая задача сама решает,
 * пора ли ей работать, и отмечает сделанное, поэтому лишний запуск ничего не повторит.
 */
final class Scheduler
{
    /** @return list<string> что было сделано (для лога) */
    public static function run(): array
    {
        $now = Clock::now();
        $schedule = Schedule::fromSettings();
        $done = [];

        if ($schedule->isWorkDay($now) && $now >= $schedule->opensAt($now) && Settings::get('cron.morning') !== $now->format('Y-m-d')) {
            Settings::set('cron.morning', $now->format('Y-m-d'));
            $done[] = self::morningQueue($now);
        }

        Visits::finalize($now);

        if ($schedule->isOpen($now)) {
            $done = [...$done, ...self::dueActions($now, $schedule), ...self::reminders($now, $schedule)];
        }

        if ((int) $now->format('N') === 1 && $now >= $schedule->opensAt($now) && Settings::get('cron.weekly') !== $now->format('o-W')) {
            Settings::set('cron.weekly', $now->format('o-W'));
            $done[] = self::weeklyReport($now);
        }

        if (Settings::get('cron.daily') !== $now->format('Y-m-d') && (int) $now->format('G') >= 3) {
            Settings::set('cron.daily', $now->format('Y-m-d'));
            $done[] = self::cleanup($now);
        }

        return array_values(array_filter($done));
    }

    /** В начале рабочего дня — список всех, кому пора звонить: ночные заявки и перенесённые звонки. */
    public static function morningQueue(DateTimeImmutable $now): ?string
    {
        $leads = self::dueLeads($now);

        if ($leads === []) {
            return null;
        }

        $lines = ['☀️ Утренняя очередь, ' . $now->format('H:i'), '', 'Кому звонить в первую очередь: ' . count($leads)];

        foreach ($leads as $i => $lead) {
            $what = $lead['next_action'] === 'message' ? 'лучше MAX' : 'удобно ' . (Source::CONTACT_TIMES[$lead['contact_time']] ?? 'сейчас');
            $attempt = (int) $lead['attempt'] > 0 ? ', попытка ' . ((int) $lead['attempt'] + 1) : '';
            $lines[] = ($i + 1) . ". №{$lead['id']} {$lead['name']} — " . ($lead['service'] ?: 'услуга не указана') . ", $what$attempt";
            Leads::update((int) $lead['id'], ['notified_at' => Clock::sql($now)]);
            Leads::event((int) $lead['id'], 'queue', 'Попала в утреннюю очередь', Actor::system());
        }

        AdminChat::post(implode("\n", $lines));

        return 'Утренняя очередь: ' . count($leads);
    }

    /** @return list<string> */
    private static function dueActions(DateTimeImmutable $now, Schedule $schedule): array
    {
        $done = [];

        foreach (self::dueLeads($now) as $lead) {
            $what = $lead['next_action'] === 'message' ? '✉️ Пора написать в MAX' : '📞 Пора звонить';
            $attempt = (int) $lead['attempt'] > 0 ? ' · попытка ' . ((int) $lead['attempt'] + 1) . ' из ' . $schedule->maxAttempts : '';
            AdminChat::post(
                "$what: №{$lead['id']} {$lead['name']}, " . Phone::format($lead['phone']) . $attempt
                    . (($lead['service'] ?? '') !== '' ? "\n" . $lead['service'] : ''),
                (int) $lead['id'],
                AdminChat::keyboard($lead),
            );
            Leads::update((int) $lead['id'], ['notified_at' => Clock::sql($now)]);
            $done[] = "Напоминание о звонке №{$lead['id']}";
        }

        $stmt = Db::pdo()->prepare("SELECT * FROM leads WHERE next_action IN ('final_message', 'give_up') AND next_action_at <= ?");
        $stmt->execute([Clock::sql($now)]);

        foreach ($stmt->fetchAll() as $lead) {
            if (Status::isClosed($lead['status'])) {
                continue;
            }

            if ($lead['next_action'] === 'final_message') {
                LeadService::sendFinal($lead);
                $done[] = "Последнее сообщение по №{$lead['id']}";
            } else {
                LeadService::setStatus($lead, Status::UNREACHABLE, Actor::system());
                $done[] = "№{$lead['id']} — не дозвонились";
            }
        }

        return $done;
    }

    /** Напоминания: через 15 минут без «Беру» — в чат, через 30 — руководителю. */
    private static function reminders(DateTimeImmutable $now, Schedule $schedule): array
    {
        $chatAfter = max(1, Settings::int('remind_chat_minutes'));
        $managerAfter = max($chatAfter + 1, Settings::int('remind_manager_minutes'));
        $stmt = Db::pdo()->prepare('SELECT * FROM leads WHERE status = ? AND reminded_level < 2 AND due_at <= ?');
        $stmt->execute([Status::NEW, Clock::sql($now->modify("-$chatAfter minutes"))]);
        $done = [];

        foreach ($stmt->fetchAll() as $lead) {
            // Ночные заявки считаем с начала рабочего дня, а не с момента поступления.
            $since = max(new DateTimeImmutable($lead['due_at']), $schedule->opensAt($now));
            $waited = intdiv($now->getTimestamp() - $since->getTimestamp(), 60);
            $level = (int) $lead['reminded_level'];

            if ($level < 2 && $waited >= $managerAfter) {
                AdminChat::toManagers("⚠️ Заявку №{$lead['id']} ({$lead['name']}, " . Phone::format($lead['phone'])
                    . ") никто не взял уже $waited мин.");
                Leads::update((int) $lead['id'], ['reminded_level' => 2]);
                Leads::event((int) $lead['id'], 'reminder', "Никто не взял за $waited мин — сообщили руководителю", Actor::system());
                $done[] = "Руководителю о №{$lead['id']}";
            } elseif ($level < 1 && $waited >= $chatAfter) {
                AdminChat::post("⏰ Заявку №{$lead['id']} никто не взял уже $waited минут.", (int) $lead['id'], [
                    [Keyboard::callback('Беру', "a:take:{$lead['id']}")],
                ]);
                Leads::update((int) $lead['id'], ['reminded_level' => 1]);
                Leads::event((int) $lead['id'], 'reminder', "Никто не взял за $waited мин — напомнили в чат", Actor::system());
                $done[] = "Напоминание о №{$lead['id']}";
            }
        }

        return $done;
    }

    /** Отчёт за прошлую неделю (Пн–Вс) руководителю. */
    public static function weeklyReport(DateTimeImmutable $now): string
    {
        $to = $now->setTime(0, 0)->modify('monday this week');
        $from = $to->modify('-7 days');
        AdminChat::toManagers(Report::weeklyText(Report::build($from, $to)));

        return 'Еженедельный отчёт отправлен';
    }

    /** Раз в сутки: удаление старых заявок без записи (152-ФЗ) и служебных записей. */
    public static function cleanup(DateTimeImmutable $now): ?string
    {
        RateLimiter::cleanup();
        $visits = Visits::cleanup($now);
        $chats = Chat::cleanup($now);
        if ($chats > 0) {
            Audit::log(Actor::system(), 'retention', null, "Удалено переписок чат-бота без заявки: $chats");
        }
        if ($visits > 0) {
            Audit::log(Actor::system(), 'retention', null, "Удалено заходов на сайт без заявки: $visits");
        }

        $months = max(1, Settings::int('retention_months'));
        $border = Clock::sql($now->modify("-$months months"));
        $pdo = Db::pdo();

        $stmt = $pdo->prepare('SELECT id FROM leads WHERE status <> ? AND created_at < ?');
        $stmt->execute([Status::ENROLLED, $border]);
        $ids = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        foreach ($ids as $id) {
            Leads::delete((int) $id);
        }

        // SMS хранят номер телефона — удаляем по тому же сроку.
        $pdo->prepare('DELETE FROM sms_messages WHERE created_at < ? AND (lead_id IS NULL OR lead_id NOT IN (SELECT id FROM leads))')
            ->execute([$border]);

        if ($ids === []) {
            return null;
        }

        Audit::log(Actor::system(), 'retention', null, 'Удалено заявок старше ' . $months . ' мес. без записи: ' . count($ids));

        return 'Удалено старых заявок: ' . count($ids);
    }

    /** Заявки, по которым подошло время звонка или сообщения и о которых ещё не напоминали. */
    public static function dueLeads(DateTimeImmutable $now): array
    {
        $closed = implode(',', array_map(static fn (string $s): string => "'$s'", Status::CLOSED));
        $stmt = Db::pdo()->prepare(
            "SELECT * FROM leads WHERE next_action IN ('call', 'message') AND next_action_at <= ?
               AND status NOT IN ($closed) AND (notified_at IS NULL OR notified_at < next_action_at)
             ORDER BY next_action_at, id"
        );
        $stmt->execute([Clock::sql($now)]);

        return $stmt->fetchAll();
    }
}
