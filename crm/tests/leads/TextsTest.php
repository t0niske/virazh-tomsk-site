<?php

declare(strict_types=1);

use App\Leads\Schedule;
use App\Leads\Settings;
use App\Leads\Texts;
use App\Sms\Sms;

// Ноябрь 2026: 2-е — понедельник, 3-е — вторник, 7-е — суббота, 8-е — воскресенье.

function when(string $now, string $contactTime = 'now', array $holidays = []): string
{
    $schedule = new Schedule(holidays: $holidays);
    $now = new DateTimeImmutable($now);

    return $schedule->describe($schedule->firstCallAt($now, $contactTime), $now);
}

test('Тексты: рабочее время — «в течение 10 минут»', function (): void {
    eq('в течение 10 минут', when('2026-11-03 10:00'));
    eq('в течение 10 минут', when('2026-11-03 14:30'));
    eq('в течение 10 минут', when('2026-11-07 17:59'), 'суббота днём — тоже рабочее время');
});

test('Тексты: вечер и ночь — «завтра после 10:00»', function (): void {
    eq('завтра после 10:00', when('2026-11-03 18:00'));
    eq('завтра после 10:00', when('2026-11-03 23:40'));
    eq('завтра после 10:00', when('2026-11-06 21:00'), 'пятница вечером — завтра суббота, рабочий день');
    eq('сегодня после 10:00', when('2026-11-04 02:15'), 'после полуночи звоним в тот же день');
});

test('Тексты: суббота вечером и воскресенье — «в понедельник после 10:00»', function (): void {
    eq('в понедельник после 10:00', when('2026-11-07 18:05'));
    eq('в понедельник после 10:00', when('2026-11-08 00:30'));
    eq('в понедельник после 10:00', when('2026-11-08 15:00'));
});

test('Тексты: праздник и выбранное клиентом время', function (): void {
    eq('в четверг после 10:00', when('2026-11-03 19:00', holidays: ['2026-11-04']), 'среда — праздник');
    eq('8 января после 10:00', when('2026-12-31 19:00', holidays: ['2027-01-01', '2027-01-02', '2027-01-04', '2027-01-05', '2027-01-06', '2027-01-07']));
    eq('сегодня после 17:00', when('2026-11-03 11:00', 'evening'));
    eq('в течение 10 минут', when('2026-11-03 11:00', 'morning'));
    eq('в течение 10 минут', when('2026-11-03 16:00', 'day'), 'днём — это до 17:00');
    eq('завтра после 13:00', when('2026-11-03 17:30', 'day'));
    eq('в понедельник после 17:00', when('2026-11-07 18:30', 'evening'));
});

test('Тексты: SMS о приёме заявки не длиннее двух частей (134 знака)', function (): void {
    $schedule = new Schedule(holidays: ['2027-01-01', '2027-01-02', '2027-01-04', '2027-01-05', '2027-01-06', '2027-01-07']);
    $expected = [
        '2026-11-03 11:00' => 'Здравствуйте! Это автошкола «Авто-Вираж». Спасибо за заявку — позвоним в ближайшее рабочее время с номера +7 (983) 234-56-56.',
        '2026-11-03 20:00' => 'Здравствуйте! Это автошкола «Авто-Вираж». Спасибо за заявку — позвоним в ближайшее рабочее время с номера +7 (983) 234-56-56.',
        '2026-11-06 17:45' => 'Здравствуйте! Это автошкола «Авто-Вираж». Спасибо за заявку — позвоним в понедельник с номера +7 (983) 234-56-56.',
        '2026-11-07 12:00' => 'Здравствуйте! Это автошкола «Авто-Вираж». Спасибо за заявку — позвоним в понедельник с номера +7 (983) 234-56-56.',
        '2026-11-08 12:00' => 'Здравствуйте! Это автошкола «Авто-Вираж». Спасибо за заявку — позвоним в понедельник с номера +7 (983) 234-56-56.',
    ];

    foreach ($expected as $now => $text) {
        $now = new DateTimeImmutable($now);
        $sms = Texts::smsAccepted($schedule, $schedule->firstCallAt($now, 'now'), $now);
        eq($text, $sms);
        ok(mb_strlen($sms) <= Sms::TWO_PARTS, "Длина «{$sms}» — " . mb_strlen($sms));
    }

    foreach (['sms_missed', 'sms_final'] as $key) {
        $sms = Texts::render(Settings::get($key));
        ok(mb_strlen($sms) <= Sms::TWO_PARTS, "$key: " . mb_strlen($sms));
    }

    // Имя бота MAX задаётся по ИНН: id + 12 цифр для ИП — самый длинный вариант.
    ok(mb_strlen(Texts::render(Settings::get('sms_max_invite'), ['max_link' => 'max.ru/id701712345678_bot'])) <= Sms::TWO_PARTS, 'sms_max_invite');

    // Клиент выбрал время — в SMS всё равно постоянный текст (под шаблон P1SMS без модерации).
    $now = new DateTimeImmutable('2026-11-03 11:00');
    eq('Здравствуйте! Это автошкола «Авто-Вираж». Спасибо за заявку — позвоним в ближайшее рабочее время с номера +7 (983) 234-56-56.',
        Texts::smsAccepted($schedule, $schedule->firstCallAt($now, 'evening'), $now));
});

test('Тексты: ответ формы с номером автошколы', function (): void {
    $schedule = new Schedule();
    $now = new DateTimeImmutable('2026-11-07 20:00');
    eq('Позвоним в понедельник с номера +7 (983) 234-56-56 — сохраните его, чтобы не пропустить звонок.',
        Texts::formMessage($schedule, $schedule->firstCallAt($now, 'now'), $now, 'call'));

    $now = new DateTimeImmutable('2026-11-03 11:00');
    eq('Позвоним в ближайшее рабочее время с номера +7 (983) 234-56-56 — сохраните его, чтобы не пропустить звонок.',
        Texts::formMessage($schedule, $schedule->firstCallAt($now, 'now'), $now, 'call'));
});

function promise(string $now, string $contactTime = 'now', array $holidays = []): string
{
    $schedule = new Schedule(holidays: $holidays);
    $now = new DateTimeImmutable($now);

    return $schedule->promise($now, $schedule->firstCallAt($now, $contactTime), $contactTime);
}

test('Обещание: «в ближайшее рабочее время», с пятницы 17:30 до воскресенья — «в понедельник»', function (): void {
    eq('в ближайшее рабочее время', promise('2026-11-03 11:00'), 'вторник днём');
    eq('в ближайшее рабочее время', promise('2026-11-03 23:00'), 'вторник ночью');
    eq('в ближайшее рабочее время', promise('2026-11-06 17:29'), 'пятница до 17:30');
    eq('в понедельник', promise('2026-11-06 17:30'), 'пятница с 17:30');
    eq('в понедельник', promise('2026-11-07 11:00'), 'суббота — тоже «в понедельник», как просил заказчик');
    eq('в понедельник', promise('2026-11-08 23:59'), 'воскресенье');
    eq('в ближайшее рабочее время', promise('2026-11-09 00:10'), 'в ночь на понедельник');
    eq('во вторник', promise('2026-11-07 11:00', holidays: ['2026-11-09']), 'понедельник — праздник');
    eq('сегодня после 17:00', promise('2026-11-03 11:00', 'evening'), 'клиент выбрал вечер');
    eq('в ближайшее рабочее время', promise('2026-11-03 11:00', 'morning'), 'выбрал утро, а сейчас утро');

    $off = new Schedule(weekendFromDay: null);
    $sat = new DateTimeImmutable('2026-11-07 11:00');
    eq('в ближайшее рабочее время', $off->promise($sat, $sat, 'now'), 'правило «в понедельник» отключено');
});

test('Тексты: режим работы для бота', function (): void {
    eq('Пн–Сб с 10:00 до 18:00', (new Schedule())->workTimeText());
    eq([1, 2, 3, 4, 5], Schedule::parseDays('1-5'));
    eq([1, 3, 5], Schedule::parseDays('1,3, 5'));
});

test('Режим Пн–Пт 9:00–18:00: тексты, первый звонок и обещание', function (): void {
    $s = new Schedule([1, 2, 3, 4, 5], '09:00', '18:00', slots: ['morning' => '09:00', 'day' => '13:00', 'evening' => '17:00']);
    eq('Пн–Пт с 9:00 до 18:00', $s->workTimeText());

    $sat = new DateTimeImmutable('2026-11-14 11:00');
    eq('2026-11-16 09:00', $s->firstCallAt($sat, 'now')->format('Y-m-d H:i'), 'суббота — выходной, звоним в понедельник с 9:00');
    eq('в понедельник', $s->promise($sat, $s->firstCallAt($sat, 'now'), 'now'));

    $fri = new DateTimeImmutable('2026-11-13 18:30');
    eq('в понедельник', $s->promise($fri, $s->firstCallAt($fri, 'now'), 'now'));

    $tue = new DateTimeImmutable('2026-11-10 08:30');
    eq('2026-11-10 09:00', $s->firstCallAt($tue, 'now')->format('Y-m-d H:i'), 'утром до открытия — звоним с 9:00');
    eq('в ближайшее рабочее время', $s->promise($tue, $s->firstCallAt($tue, 'now'), 'now'));
});

test('Кнопка «Адреса классов» показывает режим работы из настроек', function (): void {
    putenv('WORK_DAYS=1-5');
    putenv('WORK_HOURS=09:00-18:00');

    try {
        Settings::reset();
        $text = Texts::render(Settings::get('faq_addresses'), ['work_time' => Schedule::fromSettings()->workTimeText()]);
        ok(str_ends_with($text, 'Офис работает Пн–Пт с 9:00 до 18:00.'), $text);
        ok(str_contains(Settings::get('faq_groups'), 'подскажет администратор'), 'расписание групп — у администратора (в BRANDCORE его нет)');
    } finally {
        putenv('WORK_DAYS=1-6');
        putenv('WORK_HOURS=10:00-18:00');
        Settings::reset();
    }
});
