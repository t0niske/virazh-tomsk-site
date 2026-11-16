<?php

declare(strict_types=1);

namespace App\Crm;

use App\Leads\Audit;
use App\Leads\Settings;
use App\Max\Max;
use App\Support\Db;

/** Настройки, сотрудники и журнал действий — разделы руководителя. */
final class SettingsController
{
    /**
     * Поля страницы настроек: группа → ключ → [подпись, тип, подсказка].
     * Типы: text, textarea, number, time, sms (textarea со счётчиком знаков и частей SMS).
     */
    /** Поля без выключенного: группа SMS — только если SMS подключены, срок переписки чат-бота — только если чат включён. */
    public static function fields(): array
    {
        $chat = \App\Chat\DeepSeek::fromEnv() !== null;
        $out = [];
        foreach (self::FIELDS as $group => $fields) {
            if (str_starts_with($group, 'SMS') && !\App\Sms\Sms::enabled()) {
                continue;
            }
            if (!$chat) {
                unset($fields['chats_retention_months']);
            }
            $out[$group] = $fields;
        }

        return $out;
    }

    public const FIELDS = [
        'Режим работы' => [
            'work_days' => ['Рабочие дни', 'text', '1 — понедельник … 7 — воскресенье. Например: 1-6 или 1,2,3,4,5'],
            'work_start' => ['Начало дня', 'time', ''],
            'work_end' => ['Конец дня', 'time', ''],
            'holidays' => ['Праздники и нерабочие дни', 'textarea', 'По одной дате в строке: 01.01.2027'],
            'slot_morning' => ['«Утром» — с', 'time', ''],
            'slot_day' => ['«Днём» — с', 'time', ''],
            'slot_evening' => ['«Вечером» — с', 'time', 'и до конца рабочего дня'],
            'school_phone' => ['Номер, с которого звоним', 'text', 'Показывается клиентам в ответе на заявку'],
        ],
        'Что обещаем клиенту в ответе на заявку' => [
            'promise_text' => ['Когда позвоним', 'text', 'Подставляется в ответ на сайте и в SMS вместо {when}'],
            'weekend_from_day' => ['«Позвоним в понедельник» — с дня недели', 'text', '1 — понедельник … 7 — воскресенье; 0 — не использовать. Действует до конца воскресенья'],
            'weekend_from_time' => ['…и с какого времени', 'time', ''],
        ],
        'Скорость и напоминания' => [
            'answer_minutes' => ['Обещаем ответить в MAX в течение, мин', 'number', 'Используется в приветствии бота'],
            'remind_chat_minutes' => ['Напомнить в чат, если никто не взял, через, мин', 'number', ''],
            'remind_manager_minutes' => ['Сообщить руководителю через, мин', 'number', ''],
            'max_admin_chat_id' => ['ID чата администраторов в MAX', 'text', 'Бот пишет его в чат, когда его туда добавляют. Можно отправить в чат команду /chatid'],
        ],
        'График повторных попыток' => [
            'retry_delay_minutes' => ['Повторный звонок после первого недозвона через, мин', 'number', '60–120 минут'],
            'max_attempts' => ['Всего попыток дозвониться', 'number', ''],
            'final_day' => ['«Заявка ещё актуальна?» на день', 'number', 'День от поступления заявки'],
            'give_up_hours' => ['Статус «Не дозвонились», если клиент молчит, через, ч', 'number', ''],
        ],
        'SMS (до 134 знаков — две части; при изменении текста создайте новый шаблон в P1SMS)' => [
            'sms_daily_limit' => ['Не больше SMS в сутки', 'number', 'Защита баланса от ботов: при превышении SMS до полуночи не уходят, в Telegram придёт предупреждение'],
            'sms_accepted_later' => ['Заявка принята', 'sms', '{when} — «в ближайшее рабочее время», «в понедельник», «сегодня после 17:00»; {phone} — номер'],
            'sms_accepted_later_short' => ['То же, короткий вариант', 'sms', 'Используется, если обычный длиннее 134 знаков'],
            'sms_max_invite' => ['Клиент выбрал переписку в MAX', 'sms', '{max_link} — короткая ссылка на бота'],
            'sms_missed' => ['Не дозвонились', 'sms', ''],
            'sms_final' => ['Последнее, на 3-й день', 'sms', ''],
        ],
        'Ответ на сайте после отправки формы' => [
            'form_title' => ['Заголовок', 'text', ''],
            'form_message_call' => ['Текст для звонка', 'textarea', '{when} — как в SMS, {phone_pretty} — номер'],
            'form_message_max' => ['Текст, если выбрал MAX', 'textarea', ''],
        ],
        'Сообщения бота в MAX' => [
            'bot_greeting_open' => ['Приветствие в рабочее время', 'textarea', '{name}, {service_part}, {minutes}'],
            'bot_greeting_closed' => ['Приветствие в нерабочее время', 'textarea', '{name}, {work_time}, {when}'],
            'bot_greeting_unknown' => ['Если заявка не найдена', 'textarea', ''],
            'bot_missed' => ['После пропущенного звонка', 'textarea', ''],
            'bot_final' => ['На третий день', 'textarea', ''],
            'bot_time_ok' => ['Клиент выбрал время', 'text', '{when}'],
            'bot_chat_ok' => ['Клиент выбрал переписку', 'text', '{when}'],
            'bot_actual_yes' => ['Ответ на «Да, актуально»', 'text', ''],
            'bot_actual_no' => ['Ответ на «Уже не нужно»', 'text', ''],
            'faq_prices' => ['Кнопка «Цены и рассрочка»', 'textarea', ''],
            'faq_addresses' => ['Кнопка «Адреса классов»', 'textarea', ''],
            'faq_groups' => ['Кнопка «Ближайшие группы»', 'textarea', ''],
        ],
        'Заявки и хранение' => [
            'refusal_reasons' => ['Причины отказа', 'textarea', 'По одной в строке'],
            'dedupe_days' => ['Повторная заявка с того же номера добавляется к первой в течение, дней', 'number', ''],
            'chats_retention_months' => ['Удалять переписку чат-бота без заявки через, мес.', 'number', 'Переписка с заявкой хранится, пока хранится заявка'],
            'visits_retention_months' => ['Удалять заходы на сайт без заявки через, мес.', 'number', 'Заходы клиентов с заявкой хранятся, пока хранится заявка'],
            'visits_notify_returning' => ['Сообщать в Telegram, что клиент с заявкой снова на сайте', 'text', '1 — да, 0 — нет. Без имени и телефона: номер заявки и страница'],
            'retention_months' => ['Удалять заявки без записи через, мес.', 'number', 'Требование 152-ФЗ: не хранить дольше, чем нужно'],
        ],
    ];

    public static function settings(string $method): void
    {
        if ($method === 'POST') {
            $defaults = Settings::defaults();
            $changed = [];
            $errors = [];

            foreach (self::fields() as $fields) {
                foreach ($fields as $key => [$label, $type]) {
                    if (!array_key_exists($key, $_POST)) {
                        continue;
                    }

                    $value = str_replace("\r\n", "\n", trim((string) $_POST[$key]));

                    if ($key === 'school_phone' && $value !== '') {
                        $value = \App\Leads\Phone::compact($value);
                    }

                    $error = self::check($type, $value);

                    if ($error !== null) {
                        $errors[] = "«{$label}»: $error";
                        continue;
                    }

                    // Пустое поле или значение по умолчанию — храним как «по умолчанию».
                    $store = $value === '' || $value === ($defaults[$key] ?? null) ? null : $value;

                    if ($store !== (Settings::isCustom($key) ? Settings::get($key) : null)) {
                        Settings::set($key, $store);
                        $changed[] = $label;
                    }
                }
            }

            if ($changed !== []) {
                Audit::log(Auth::actor(), 'settings', null, 'Изменено: ' . implode(', ', $changed));
            }

            View::flash($errors !== [] ? 'Не сохранено: ' . implode('; ', $errors) : ($changed === [] ? 'Изменений нет' : 'Сохранено'),
                $errors !== [] ? 'error' : 'ok');
            View::redirect('/crm/settings');

            return;
        }

        View::render('settings', ['title' => 'Настройки', 'active' => 'settings', 'fields' => self::fields(), 'defaults' => Settings::defaults()]);
    }

    public static function staff(string $method): void
    {
        if ($method === 'POST') {
            self::saveStaff();
            View::redirect('/crm/staff');

            return;
        }

        $staff = Staff::all();

        View::render('staff', [
            'title' => 'Сотрудники',
            'active' => 'staff',
            'staff' => $staff,
            'links' => Max::enabled()
                ? array_combine(array_column($staff, 'id'), array_map(static fn (array $s): string => Max::link(Max::staffPayload((int) $s['id'])), $staff))
                : [],
        ]);
    }

    public static function audit(): void
    {
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $where = '1 = 1';
        $params = [];

        if (ctype_digit((string) ($_GET['staff'] ?? ''))) {
            $where .= ' AND staff_id = ?';
            $params[] = (int) $_GET['staff'];
        }

        if (isset(Audit::LABELS[$_GET['action'] ?? ''])) {
            $where .= ' AND action = ?';
            $params[] = $_GET['action'];
        }

        if (ctype_digit((string) ($_GET['lead'] ?? ''))) {
            $where .= ' AND lead_id = ?';
            $params[] = (int) $_GET['lead'];
        }

        $stmt = Db::pdo()->prepare("SELECT * FROM audit_log WHERE $where ORDER BY id DESC LIMIT 100 OFFSET " . (($page - 1) * 100));
        $stmt->execute($params);

        View::render('audit', ['title' => 'Журнал действий', 'active' => 'audit', 'rows' => $stmt->fetchAll(), 'page' => $page, 'staff' => Staff::all()]);
    }

    private static function saveStaff(): void
    {
        $actor = Auth::actor();
        $id = (int) ($_POST['id'] ?? 0);
        $role = ($_POST['role'] ?? '') === 'manager' ? 'manager' : 'admin';
        $name = trim((string) ($_POST['name'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if ($id === 0) {
            $login = (string) ($_POST['login'] ?? '');
            $error = Staff::validate($login, $name, $password, true);

            if ($error !== null) {
                View::flash($error, 'error');

                return;
            }

            $newId = Staff::create($login, $name, $role, $password);
            Audit::log($actor, 'staff', null, "Добавлен сотрудник №$newId $name (" . Staff::ROLES[$role] . ')');
            View::flash("Сотрудник «{$name}» добавлен. Передайте ему логин и пароль лично.");

            return;
        }

        $staff = Staff::find($id);

        if ($staff === null) {
            return;
        }

        $active = isset($_POST['active']) ? 1 : 0;
        $error = Staff::validate($staff['login'], $name, $password !== '' ? $password : null, false);

        // Не даём оставить систему без руководителя.
        if ($error === null && $staff['role'] === 'manager' && ($role !== 'manager' || !$active) && Staff::managersCount() <= 1) {
            $error = 'Должен остаться хотя бы один активный руководитель.';
        }

        if ($error !== null) {
            View::flash($error, 'error');

            return;
        }

        $fields = ['name' => $name, 'role' => $role, 'active' => $active];

        if ($password !== '') {
            $fields['password'] = $password;
        }

        if (isset($_POST['unlink_max'])) {
            $fields['max_user_id'] = null;
        }

        Staff::update($id, $fields);

        if ($id === (int) Auth::user()['id'] && $password !== '') {
            Auth::refreshPasswordMark();
        }

        Audit::log($actor, 'staff', null, "Изменён сотрудник №$id $name" . ($password !== '' ? ', новый пароль' : '') . ($active ? '' : ', отключён'));
        View::flash('Сохранено');
    }

    private static function check(string $type, string $value): ?string
    {
        return match (true) {
            $value === '' => null,
            $type === 'number' && (!ctype_digit($value) || (int) $value < 1 || (int) $value > 10000) => 'нужно целое число',
            $type === 'time' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) => 'время в виде 10:00',
            default => null,
        };
    }
}
