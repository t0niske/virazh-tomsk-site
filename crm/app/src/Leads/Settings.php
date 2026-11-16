<?php

declare(strict_types=1);

namespace App\Leads;

use App\Support\Db;
use App\Support\Env;

/**
 * Настройки, которые руководитель меняет в админке: тексты, режим работы, график попыток.
 * В базе хранятся только изменённые значения, остальное — значения по умолчанию ниже.
 * Служебные отметки планировщика тоже лежат здесь с префиксом «cron.».
 */
final class Settings
{
    /** @var array<string, string>|null */
    private static ?array $cache = null;

    /** @return array<string, string> */
    public static function defaults(): array
    {
        [$start, $end] = array_pad(explode('-', Env::get('WORK_HOURS', '09:00-18:00') ?? ''), 2, '');

        return [
            'school_phone' => Phone::compact(Env::get('SCHOOL_PHONE', '+73822345252') ?? ''),
            'work_days' => Env::get('WORK_DAYS', '1-5') ?? '1-5',
            'work_start' => trim($start) ?: '09:00',
            'work_end' => trim($end) ?: '18:00',
            // государственные праздники (без переносов выходных — их утверждают позже, добавляет руководитель в настройках)
            'holidays' => "04.11.2026
01.01.2027
02.01.2027
03.01.2027
04.01.2027
05.01.2027
06.01.2027
07.01.2027
08.01.2027
23.02.2027
08.03.2027
01.05.2027
09.05.2027
12.06.2027
04.11.2027",
            // «Утром» начинается с открытия офиса.
            'slot_morning' => trim($start) ?: '09:00',
            'slot_day' => '13:00',
            'slot_evening' => '17:00',
            'answer_minutes' => '10',
            'promise_text' => 'в ближайшее рабочее время',
            'weekend_from_day' => '5',
            'weekend_from_time' => '17:30',
            'remind_chat_minutes' => '15',
            'remind_manager_minutes' => '30',
            'retry_delay_minutes' => '90',
            'max_attempts' => '4',
            'final_day' => '3',
            'give_up_hours' => '24',
            'dedupe_days' => '30',
            'retention_months' => '12',
            'sms_daily_limit' => '40',
            'visits_retention_months' => '3',
            'chats_retention_months' => '3',
            'visits_notify_returning' => '0',
            'max_admin_chat_id' => Env::get('MAX_ADMIN_CHAT_ID', '') ?? '',
            'refusal_reasons' => "Дорого\nДалеко\nНеудобное время групп\nВыбрал другую автошколу\nПросто узнавал\nДругое",

            'form_title' => 'Заявка принята!',
            'form_message_call' => 'Позвоним {when} с номера {phone_pretty} — сохраните его, чтобы не пропустить звонок.',
            'form_message_max' => 'Нажмите «Написать в MAX» — администратор ответит там {when}. Если удобнее звонок, позвоним с номера {phone_pretty}.',

            // SMS уходят от общего имени (INFO), поэтому автошкола представляется в начале текста.
            // Тексты — две части SMS (до 134 знаков) и совпадают с шаблонами P1SMS без модерации.
            'sms_accepted_later' => 'Здравствуйте! Это автошкола «Авто-Вираж». Спасибо за заявку — позвоним {when} с номера {phone_pretty}.',
            'sms_accepted_later_short' => 'Автошкола «Авто-Вираж»: спасибо за заявку! Позвоним {when}.',
            'sms_max_invite' => 'Здравствуйте! Это автошкола «Авто-Вираж». Удобнее переписка? Напишите нам в MAX: {max_link}',
            'sms_missed' => 'Здравствуйте! Это автошкола «Авто-Вираж». Мы звонили вам по заявке, но не дозвонились. Перезвоните, пожалуйста: {phone_pretty}.',
            'sms_final' => 'Здравствуйте! Это автошкола «Авто-Вираж». Ваша заявка на обучение ещё актуальна? Позвоните нам: {phone_pretty}.',

            'bot_greeting_open' => "Здравствуйте, {name}! Это автошкола «Авто-Вираж». Ваша заявка{service_part} у нас. Администратор ответит здесь в течение {minutes} минут.\n\nПока можно посмотреть:",
            'bot_greeting_closed' => "Здравствуйте, {name}! Сейчас мы не на связи — работаем {work_time}. Ответим {when}.\n\nКогда вам удобнее?",
            'bot_greeting_unknown' => "Здравствуйте! Это автошкола «Авто-Вираж». Напишите вопрос здесь — администратор ответит {when}.\n\nЧтобы мы нашли вашу заявку, поделитесь номером телефона.",
            'bot_missed' => "{name}, мы звонили вам по заявке, но не застали. Когда удобно поговорить?",
            'bot_final' => "{name}, не получилось с вами связаться. Если обучение ещё актуально — нажмите кнопку, и мы перезвоним в удобное время.",
            'bot_time_ok' => 'Хорошо, позвоним {when}.',
            'bot_chat_ok' => 'Хорошо, администратор напишет вам здесь {when}.',
            'bot_actual_yes' => 'Спасибо! Перезвоним {when}.',
            'bot_actual_no' => 'Поняли, заявку закрыли. Если передумаете — напишите сюда или позвоните {phone_pretty}.',
            'faq_prices' => "Стоимость обучения:\n• Категория B на автомате — 66 000 ₽: теория 5 000 ₽, 28 занятий вождения по 2 000 ₽ и внутренний экзамен 5 000 ₽\n• Категория B на механике — 68 000 ₽: 29 занятий вождения\n• Категория A (мотоцикл) — 25 000 ₽, экзамены в цене\n• Категория AI (квадроцикл, снегоход) — 15 000 ₽ под ключ, срок 1 месяц\n• Восстановление навыков — 2 100 ₽ за занятие из двух учебных часов\n\nОплата поэтапно, без банка: первый взнос 5 000 ₽, далее 2 000 ₽ за каждое занятие. Экзамен в ГИБДД для категории B — 5 000 ₽ отдельно.\n\nВ Новосибирске учат только на категорию B, цены свои: 65 100 ₽ на автомате и на механике. Телефон офиса в Новосибирске +7 (383) 205-24-34.",
            'faq_addresses' => "Адреса:\n• Томск, ул. Киевская, 105/б — главный офис, классы теории\n• Томск, ул. Ивана Черных, 40 — классы теории\n• Томск, пр. Ленина, 94 (2 этаж) — классы теории\n• Автодром — пос. Хромовка, 44: первые занятия по вождению, туда едете с инструктором на учебной машине\n• Новосибирск: пр. Карла Маркса, 57, офис 853 (8 этаж); ул. Зорге, 6; рп. Краснообск, 2-й микрорайон, 240, офис 308 — телефон +7 (383) 205-24-34\n\nОфис в Томске работает {work_time}.",
            'faq_groups' => "Теория — очно в классе в любом из трёх офисов или онлайн в личном кабинете. Расписание и даты старта ближайших групп подскажет администратор — напишите здесь, какой офис и время вам удобны.",
        ];
    }

    public static function get(string $name): string
    {
        self::load();

        return self::$cache[$name] ?? self::defaults()[$name] ?? '';
    }

    public static function int(string $name): int
    {
        return (int) self::get($name);
    }

    /** @return list<string> непустые строки значения */
    public static function lines(string $name): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/u', self::get($name)))));
    }

    public static function isCustom(string $name): bool
    {
        self::load();

        return array_key_exists($name, self::$cache);
    }

    public static function set(string $name, ?string $value): void
    {
        self::load();
        $pdo = Db::pdo();
        $now = Clock::sql();

        if ($value === null) {
            $pdo->prepare('DELETE FROM settings WHERE name = ?')->execute([$name]);
            unset(self::$cache[$name]);

            return;
        }

        if (array_key_exists($name, self::$cache)) {
            $pdo->prepare('UPDATE settings SET value = ?, updated_at = ? WHERE name = ?')->execute([$value, $now, $name]);
        } else {
            $pdo->prepare('INSERT INTO settings (name, value, updated_at) VALUES (?, ?, ?)')->execute([$name, $value, $now]);
        }

        self::$cache[$name] = $value;
    }

    public static function reset(): void
    {
        self::$cache = null;
    }

    private static function load(): void
    {
        if (self::$cache !== null) {
            return;
        }

        self::$cache = [];

        foreach (Db::pdo()->query('SELECT name, value FROM settings') as $row) {
            self::$cache[$row['name']] = (string) $row['value'];
        }
    }
}
