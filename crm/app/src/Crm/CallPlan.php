<?php

declare(strict_types=1);

namespace App\Crm;

use App\Leads\Schedule;
use App\Leads\Status;
use App\Sms\Sms;
use DateTimeImmutable;

/**
 * Блок «Звонки» в карточке заявки: какие звонки уже были, что делать сейчас и что будет дальше
 * (утро → день → вечер → сообщение «не дозвонились»). Класс без базы — строки собираются из заявки и её истории.
 */
final class CallPlan
{
    public const SLOT_LABELS = ['morning' => 'Утро', 'day' => 'День', 'evening' => 'Вечер'];

    private const SLOT_WHEN = ['morning' => 'утром', 'day' => 'днём', 'evening' => 'вечером'];

    private const WEEKDAYS = [1 => 'пн', 'вт', 'ср', 'чт', 'пт', 'сб', 'вс'];

    /**
     * @param list<array> $events история заявки (нужны call_miss и sms)
     * @return array{rows: list<array{state: string, label: string, when: string, text: string, hint?: string}>, actions: string}
     *   state: miss — не дозвонились; now — текущий шаг с кнопками; later — что будет дальше; done — уже сделано; result — итог.
     *   actions: какие кнопки у текущего шага — call (с «Не ответил»), result (без него), none.
     */
    public static function build(array $lead, array $events, Schedule $schedule, DateTimeImmutable $now): array
    {
        $rows = [];

        foreach ($events as $e) {
            if ($e['type'] === 'call_miss') {
                $at = new DateTimeImmutable($e['created_at']);
                $rows[] = [
                    'state' => 'miss',
                    'label' => self::SLOT_LABELS[$schedule->slotOf($at)],
                    'when' => self::day($at, $now) . ' ' . $at->format('H:i'),
                    'text' => 'Не ответил' . ($e['actor'] ? ' · ' . $e['actor'] : ''),
                ];
            }
        }

        $status = $lead['status'];
        // чем сообщаем клиенту «не дозвонились»: MAX, если он там, иначе SMS; у Виража SMS пока не подключены — тогда ничем
        $message = ($lead['max_user_id'] ?? '') !== '' ? 'Сообщение в MAX' : (Sms::enabled() ? 'SMS' : null);

        if (Status::isClosed($status)) {
            $rows[] = ['state' => 'result', 'label' => 'Итог', 'when' => '', 'text' => self::resultText($lead)];

            return ['rows' => $rows, 'actions' => 'none'];
        }

        if (($lead['next_action'] ?? null) === 'give_up') {
            $until = new DateTimeImmutable($lead['next_action_at']);
            if ($message !== null) {
                $rows[] = ['state' => 'done', 'label' => $message, 'when' => '', 'text' => '«Мы звонили, но не дозвонились» — отправлено'];
            }
            $rows[] = [
                'state' => 'now', 'label' => 'Ждём звонка', 'when' => 'до ' . self::day($until, $now) . ' ' . $until->format('H:i'),
                'text' => 'Клиент перезвонил? Отметьте, чем закончился разговор',
                'hint' => 'Не перезвонит — заявка уйдёт в «Не дозвонились»',
            ];

            return ['rows' => $rows, 'actions' => 'result'];
        }

        $callAt = ($lead['next_action'] ?? null) === 'call' && $lead['next_action_at'] !== null
            ? new DateTimeImmutable($lead['next_action_at'])
            : ($status === Status::NEW || $status === Status::IN_WORK || $status === Status::NO_ANSWER ? $now : null);

        if ($callAt === null) {
            // Думает, придёт на договор, переписка: звонок не запланирован — показываем итог и даём сменить его.
            $rows[] = ['state' => 'now', 'label' => 'Сейчас', 'when' => '', 'text' => self::resultText($lead),
                'hint' => 'Нужно перезвонить — укажите время в блоке «Запланировать»'];

            return ['rows' => $rows, 'actions' => 'result'];
        }

        $due = $callAt <= $now;
        $at = $due ? $now : $callAt;
        $next = $schedule->afterMiss($at);
        $rows[] = [
            'state' => 'now',
            'label' => self::SLOT_LABELS[$schedule->slotOf($at)],
            'when' => $due ? 'сейчас' : self::day($callAt, $now) . ' в ' . $callAt->format('H:i'),
            'text' => $due ? 'Позвоните сейчас' : 'Следующий звонок',
            'hint' => $next['action'] === 'give_up'
                ? ($message === null
                    ? 'Не ответит — сутки ждём, что перезвонит сам, потом заявка уйдёт в «Не дозвонились»'
                    : 'Не ответит — клиенту уйдёт ' . ($message === 'SMS' ? 'SMS' : 'сообщение в MAX') . ' «не дозвонились»')
                : 'Не ответит — звонок ' . self::SLOT_WHEN[$schedule->slotOf($next['at'])] . ', ' . self::day($next['at'], $now) . ' с ' . $next['at']->format('H:i'),
        ];

        for ($i = 0; $i < 4 && $next['action'] === 'call'; $i++) {
            $rows[] = [
                'state' => 'later',
                'label' => self::SLOT_LABELS[$schedule->slotOf($next['at'])],
                'when' => self::day($next['at'], $now) . ' с ' . $next['at']->format('H:i'),
                'text' => 'если раньше не ответит',
            ];
            $next = $schedule->afterMiss($next['at']);
        }

        $rows[] = ['state' => 'later', 'label' => $message ?? 'Ждём звонка', 'when' => 'после вечера',
            'text' => ($message !== null ? '«Мы звонили, но не дозвонились» · ' : '') . 'сутки ждём звонка, потом «Не дозвонились»'];

        return ['rows' => $rows, 'actions' => 'call'];
    }

    private static function resultText(array $lead): string
    {
        return match ($lead['status']) {
            Status::TALKED => 'Клиент думает',
            Status::CONTRACT => 'Придёт на договор' . (($lead['contract_note'] ?? '') !== '' ? ': ' . $lead['contract_note'] : ''),
            Status::CHAT => 'Переписка в MAX',
            Status::REFUSED => 'Отказ: ' . ($lead['refusal_reason'] ?: 'не указана'),
            default => Status::label($lead['status']),
        };
    }

    /** «сегодня», «завтра», «пт 21.11». */
    private static function day(DateTimeImmutable $at, DateTimeImmutable $now): string
    {
        $days = (int) $now->setTime(0, 0)->diff($at->setTime(0, 0))->format('%r%a');

        return match ($days) {
            0 => 'сегодня',
            1 => 'завтра',
            -1 => 'вчера',
            default => self::WEEKDAYS[(int) $at->format('N')] . ' ' . $at->format('d.m'),
        };
    }
}
