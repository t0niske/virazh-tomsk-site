<?php

declare(strict_types=1);

namespace App\Max;

use App\Leads\Actor;
use App\Leads\Clock;
use App\Leads\Leads;
use App\Leads\Schedule;
use App\Leads\Settings;
use App\Leads\Texts;

/** Сообщения бота клиенту. Только служебные тексты, без акций и скидок. */
final class ClientMessages
{
    /** Приветствие после «Написать в MAX»: в рабочее время — FAQ, в нерабочее — выбор времени. */
    public static function greeting(array $lead): void
    {
        $schedule = Schedule::fromSettings();
        $now = Clock::now();
        $id = $lead['id'];
        $vars = [
            'name' => Texts::firstName($lead['name']),
            'service_part' => ($lead['service'] ?? '') !== '' ? ' на «' . $lead['service'] . '»' : '',
            'work_time' => $schedule->workTimeText(),
            'when' => $schedule->describe($schedule->nextOpen($now), $now),
        ];

        if ($schedule->isOpen($now)) {
            self::send($lead, Texts::render(Settings::get('bot_greeting_open'), $vars), Keyboard::faq());

            return;
        }

        self::send($lead, Texts::render(Settings::get('bot_greeting_closed'), $vars), [
            [Keyboard::callback('Позвоните утром', "c:time:$id:morning"), Keyboard::callback('Днём', "c:time:$id:day"), Keyboard::callback('Вечером', "c:time:$id:evening")],
            [Keyboard::callback('Лучше пишите сюда', "c:time:$id:chat")],
            ...Keyboard::faq(),
        ]);
    }

    /** «Мы звонили, но не застали. Когда удобно?» */
    public static function missedCall(array $lead): void
    {
        $id = $lead['id'];
        self::send($lead, Texts::render(Settings::get('bot_missed'), ['name' => Texts::firstName($lead['name'])]), [
            [Keyboard::callback('Через час', "c:time:$id:hour"), Keyboard::callback('Вечером', "c:time:$id:evening"), Keyboard::callback('Завтра', "c:time:$id:tomorrow")],
            [Keyboard::callback('Напишите здесь, без звонка', "c:time:$id:chat")],
        ]);
    }

    /** «Заявка ещё актуальна?» на третий день. */
    public static function finalQuestion(array $lead): void
    {
        $id = $lead['id'];
        self::send($lead, Texts::render(Settings::get('bot_final'), ['name' => Texts::firstName($lead['name'])]), [
            [Keyboard::callback('Да, актуально', "c:actual:$id:yes"), Keyboard::callback('Уже не нужно', "c:actual:$id:no")],
        ]);
    }

    public static function send(array $lead, string $text, ?array $keyboard = null): ?string
    {
        $mid = Max::api()->send(['user_id' => $lead['max_user_id']], $text, $keyboard);

        if ($mid !== null) {
            Leads::event((int) $lead['id'], 'max_bot', $text, Actor::system());
        }

        return $mid;
    }
}
