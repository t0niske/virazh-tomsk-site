<?php

declare(strict_types=1);

namespace App\Leads;

use App\Max\Max;
use App\Sms\Sms;
use DateTimeImmutable;

/**
 * Тексты для клиентов: ответ формы, SMS, сообщения бота. Шаблоны лежат в настройках,
 * подстановки — в фигурных скобках: {name}, {when}, {phone}, {phone_pretty} и т. д.
 */
final class Texts
{
    public static function render(string $template, array $vars = []): string
    {
        // Имени нет — убираем обращение: «Здравствуйте, {name}!» → «Здравствуйте!», «{name}, мы…» → «Мы…».
        if (($vars['name'] ?? null) === '') {
            $template = str_replace([', {name}', '{name}, ', '{name}'], '', $template);
            $template = mb_strtoupper(mb_substr($template, 0, 1)) . mb_substr($template, 1);
        }

        $phone = Settings::get('school_phone');
        $vars += [
            'phone' => $phone,
            'phone_pretty' => Phone::format($phone),
            'minutes' => Settings::get('answer_minutes'),
        ];

        return trim(strtr($template, array_combine(
            array_map(static fn (string $k): string => '{' . $k . '}', array_keys($vars)),
            array_map('strval', array_values($vars)),
        )));
    }

    /** Текст ответа формы: когда и с какого номера позвоним. */
    public static function formMessage(Schedule $schedule, DateTimeImmutable $callAt, DateTimeImmutable $now, string $channel, string $contactTime = 'now'): string
    {
        $template = $channel === 'max' && Max::enabled() ? 'form_message_max' : 'form_message_call';

        return self::render(Settings::get($template), ['when' => $schedule->promise($now, $callAt, $contactTime)]);
    }

    /**
     * SMS о приёме заявки, не длиннее двух частей (134 знака). Выбранное клиентом время в SMS не пишем: тексты постоянные
     * («в ближайшее рабочее время» / «в понедельник»), чтобы совпадать с одобренными шаблонами P1SMS
     * и уходить без ручной модерации. Время клиент видит на сайте после отправки формы.
     */
    public static function smsAccepted(Schedule $schedule, DateTimeImmutable $callAt, DateTimeImmutable $now): string
    {
        $when = $schedule->promise($now, $callAt, 'now');
        $text = self::render(Settings::get('sms_accepted_later'), ['when' => $when]);

        if (mb_strlen($text) > Sms::TWO_PARTS) {
            $text = self::render(Settings::get('sms_accepted_later_short'), ['when' => $when]);
        }

        return $text;
    }

    public static function firstName(string $name): string
    {
        $name = trim($name);

        return !Leads::hasName($name) ? '' : mb_convert_case(explode(' ', $name)[0], MB_CASE_TITLE);
    }
}
