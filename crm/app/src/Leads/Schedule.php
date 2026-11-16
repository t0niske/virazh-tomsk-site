<?php

declare(strict_types=1);

namespace App\Leads;

use DateTimeImmutable;

/**
 * Режим работы автошколы и всё, что от него зависит: когда звонить первый раз,
 * когда повторять после недозвона и как сказать клиенту, когда мы позвоним.
 * Класс не обращается к базе, поэтому легко проверяется тестами.
 */
final class Schedule
{
    public const SLOTS = ['morning', 'day', 'evening'];

    private const WEEKDAYS = [1 => 'в понедельник', 'во вторник', 'в среду', 'в четверг', 'в пятницу', 'в субботу', 'в воскресенье'];
    private const MONTHS = [1 => 'января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];

    /**
     * @param list<int> $workDays дни недели 1 (Пн) … 7 (Вс)
     * @param list<string> $holidays даты Y-m-d, в которые не работаем
     * @param array{morning: string, day: string, evening: string} $slots начало утра, дня и вечера
     */
    public function __construct(
        private readonly array $workDays = [1, 2, 3, 4, 5, 6],
        private readonly string $start = '10:00',
        private readonly string $end = '18:00',
        private readonly array $holidays = [],
        private readonly array $slots = ['morning' => '10:00', 'day' => '13:00', 'evening' => '17:00'],
        public readonly int $answerMinutes = 10,
        public readonly int $retryDelayMinutes = 60,
        public readonly int $giveUpHours = 24,
        private readonly string $promiseText = 'в ближайшее рабочее время',
        private readonly ?int $weekendFromDay = 5,
        private readonly string $weekendFromTime = '17:30',
    ) {
    }

    public static function fromSettings(): self
    {
        $holidays = [];

        foreach (Settings::lines('holidays') as $line) {
            if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})/', $line, $m)) {
                $holidays[] = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
            } elseif (preg_match('/^\d{4}-\d{2}-\d{2}/', $line, $m)) {
                $holidays[] = $m[0];
            }
        }

        return new self(
            self::parseDays(Settings::get('work_days')),
            Settings::get('work_start'),
            Settings::get('work_end'),
            $holidays,
            ['morning' => Settings::get('slot_morning'), 'day' => Settings::get('slot_day'), 'evening' => Settings::get('slot_evening')],
            max(1, Settings::int('answer_minutes')),
            max(15, Settings::int('retry_delay_minutes')),
            max(1, Settings::int('give_up_hours')),
            Settings::get('promise_text') ?: 'в ближайшее рабочее время',
            in_array(Settings::int('weekend_from_day'), range(1, 7), true) ? Settings::int('weekend_from_day') : null,
            Settings::get('weekend_from_time') ?: '17:30',
        );
    }

    /** «1-6» или «1,2,3,4,5» → список дней недели. */
    public static function parseDays(string $value): array
    {
        $days = [];

        foreach (explode(',', $value) as $part) {
            if (preg_match('/^\s*([1-7])\s*-\s*([1-7])\s*$/', $part, $m)) {
                $days = array_merge($days, range((int) $m[1], (int) $m[2]));
            } elseif (preg_match('/^\s*([1-7])\s*$/', $part, $m)) {
                $days[] = (int) $m[1];
            }
        }

        $days = array_values(array_unique($days));
        sort($days);

        return $days ?: [1, 2, 3, 4, 5, 6];
    }

    public function isWorkDay(DateTimeImmutable $t): bool
    {
        return in_array((int) $t->format('N'), $this->workDays, true)
            && !in_array($t->format('Y-m-d'), $this->holidays, true);
    }

    public function isOpen(DateTimeImmutable $t): bool
    {
        return $this->isWorkDay($t) && $t >= $this->at($t, $this->start) && $t < $this->at($t, $this->end);
    }

    /** Ближайший момент, когда автошкола работает (сам $t, если сейчас рабочее время). */
    public function nextOpen(DateTimeImmutable $t): DateTimeImmutable
    {
        if ($this->isOpen($t)) {
            return $t;
        }

        if ($this->isWorkDay($t) && $t < $this->at($t, $this->start)) {
            return $this->at($t, $this->start);
        }

        return $this->at($this->nextWorkDay($t), $this->start);
    }

    /** Следующий рабочий день строго после даты $t (время — полночь). */
    public function nextWorkDay(DateTimeImmutable $t): DateTimeImmutable
    {
        $day = $t->setTime(0, 0)->modify('+1 day');

        for ($i = 0; $i < 60 && !$this->isWorkDay($day); $i++) {
            $day = $day->modify('+1 day');
        }

        return $day;
    }

    /**
     * Когда звонить первый раз с учётом выбранного клиентом времени (now, morning, day, evening).
     * Заявки, пришедшие ночью и до открытия, — не с открытия офиса, а с начала «утра» (по умолчанию 10:00):
     * к 9 часам люди обычно ещё не на связи.
     */
    public function firstCallAt(DateTimeImmutable $now, string $contactTime): DateTimeImmutable
    {
        if (!in_array($contactTime, self::SLOTS, true)) {
            if ($this->isOpen($now)) {
                return $now;
            }

            $open = $this->nextOpen($now);
            $morning = $this->at($open, $this->slots['morning']);

            return $morning > $open && $morning < $this->at($open, $this->end) ? $morning : $open;
        }

        $day = $now->setTime(0, 0);

        for ($i = 0; $i < 60; $i++, $day = $day->modify('+1 day')) {
            if (!$this->isWorkDay($day)) {
                continue;
            }

            [$from, $to] = $this->slotWindow($day, $contactTime);

            if ($now >= $from && $now < $to) {
                return $now;
            }

            if ($from > $now) {
                return $from;
            }
        }

        return $this->nextOpen($now);
    }

    /**
     * Что делать после недозвона. Утром не ответил — звоним днём, днём — вечером. После вечернего
     * недозвона клиенту уходит SMS «не дозвонились» (сколько бы звонков ни было до этого), и если он
     * не перезвонит за give_up_hours — «Не дозвонились».
     *
     * @return array{action: string, at: DateTimeImmutable} action: call — позвонить ещё; give_up — звонки закончились
     */
    public function afterMiss(DateTimeImmutable $now): array
    {
        $slot = $this->slotOf($now);

        if ($slot === 'evening') {
            return ['action' => 'give_up', 'at' => $this->giveUpAt($now)];
        }

        $next = $slot === 'morning' ? 'day' : 'evening';
        $earliest = $now->modify("+{$this->retryDelayMinutes} minutes");

        if ($this->isWorkDay($now)) {
            [$from, $to] = $this->slotWindow($now, $next);
            $at = max($from, $earliest);

            if ($at < $to) {
                return ['action' => 'call', 'at' => $at];
            }

            // Перерыв не помещается — позвоним в начале этого же отрезка завтра.
            return ['action' => 'call', 'at' => $this->at($this->nextWorkDay($now), $this->slots[$next])];
        }

        // Нажали «Не дозвонился» в выходной — продолжаем с того же отрезка в рабочий день.
        return ['action' => 'call', 'at' => $this->at($this->nextWorkDay($now), $this->slots[$next])];
    }

    /** Утро, день или вечер по границам «Утром — с», «Днём — с», «Вечером — с». До открытия — утро, после закрытия — вечер. */
    public function slotOf(DateTimeImmutable $t): string
    {
        return match (true) {
            $t < $this->at($t, $this->slots['day']) => 'morning',
            $t < $this->at($t, $this->slots['evening']) => 'day',
            default => 'evening',
        };
    }

    /** Когда сдаться и поставить «Не дозвонились» после последнего сообщения. */
    public function giveUpAt(DateTimeImmutable $finalSentAt): DateTimeImmutable
    {
        return $this->nextOpen($finalSentAt->modify("+{$this->giveUpHours} hours"));
    }

    /** Время по кнопкам клиента: hour, morning, day, evening, tomorrow. */
    public function chosenTime(DateTimeImmutable $now, string $choice): DateTimeImmutable
    {
        return match ($choice) {
            'hour' => $this->nextOpen($now->modify('+1 hour')),
            'tomorrow' => $this->at($this->nextWorkDay($now), $this->slots['morning']),
            'morning', 'day', 'evening' => $this->firstCallAt($now, $choice),
            default => $this->nextOpen($now),
        };
    }

    /**
     * Как сказать клиенту, когда свяжемся: «в течение 10 минут», «сегодня после 17:00»,
     * «завтра после 10:00», «в понедельник после 10:00», «5 января после 10:00».
     */
    public function describe(DateTimeImmutable $at, DateTimeImmutable $now): string
    {
        if ($this->isOpen($now) && $at <= $now->modify("+{$this->answerMinutes} minutes")) {
            return "в течение {$this->answerMinutes} минут";
        }

        $time = 'после ' . $at->format('H:i');
        $days = (int) $now->setTime(0, 0)->diff($at->setTime(0, 0))->format('%r%a');

        return match (true) {
            $days <= 0 => "сегодня $time",
            $days === 1 && $this->isWorkDay($now) => "завтра $time",
            $days < 7 => self::WEEKDAYS[(int) $at->format('N')] . " $time",
            default => $at->format('j') . ' ' . self::MONTHS[(int) $at->format('n')] . " $time",
        };
    }

    /**
     * Что обещаем клиенту в ответе на заявку и в SMS: «в ближайшее рабочее время»;
     * с пятницы 17:30 до конца воскресенья — «в понедельник»; если клиент выбрал время
     * позже ближайшего рабочего — когда именно («сегодня после 17:00»).
     */
    public function promise(DateTimeImmutable $now, DateTimeImmutable $callAt, string $contactTime): string
    {
        if ($this->isWeekendPromise($now)) {
            // Первый рабочий день начиная с ближайшего понедельника (понедельник может быть праздником).
            $day = $now->setTime(0, 0)->modify('next monday');

            for ($i = 0; $i < 60 && !$this->isWorkDay($day); $i++) {
                $day = $day->modify('+1 day');
            }

            return self::WEEKDAYS[(int) $day->format('N')];
        }

        if (in_array($contactTime, self::SLOTS, true) && $callAt > $this->nextOpen($now)) {
            return $this->describe($callAt, $now);
        }

        return $this->promiseText;
    }

    /** С пятницы 17:30 (настраивается) и до конца воскресенья обещаем звонок в понедельник. */
    public function isWeekendPromise(DateTimeImmutable $now): bool
    {
        if ($this->weekendFromDay === null) {
            return false;
        }

        $weekday = (int) $now->format('N');

        return $weekday > $this->weekendFromDay
            || ($weekday === $this->weekendFromDay && $now >= $this->at($now, $this->weekendFromTime));
    }

    /** «Пн–Сб с 10:00 до 18:00» */
    public function workTimeText(): string
    {
        $names = [1 => 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
        $days = $this->workDays;
        $contiguous = $days === range($days[0], $days[count($days) - 1]);
        $daysText = $contiguous && count($days) > 2
            ? $names[$days[0]] . '–' . $names[$days[count($days) - 1]]
            : implode(', ', array_map(static fn (int $d): string => $names[$d], $days));

        $time = static fn (string $hhmm): string => ltrim(substr($hhmm, 0, 2), '0') . substr($hhmm, 2);

        return "$daysText с {$time($this->start)} до {$time($this->end)}";
    }

    public function opensAt(DateTimeImmutable $day): DateTimeImmutable
    {
        return $this->at($day, $this->start);
    }

    /** @return array{0: DateTimeImmutable, 1: DateTimeImmutable} */
    private function slotWindow(DateTimeImmutable $day, string $slot): array
    {
        $bounds = [
            'morning' => [$this->slots['morning'], $this->slots['day']],
            'day' => [$this->slots['day'], $this->slots['evening']],
            'evening' => [$this->slots['evening'], $this->end],
        ][$slot];

        return [$this->at($day, $bounds[0]), $this->at($day, $bounds[1])];
    }

    private function at(DateTimeImmutable $day, string $hhmm): DateTimeImmutable
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm) + [1 => '0']);

        return $day->setTime($h, $m);
    }
}
