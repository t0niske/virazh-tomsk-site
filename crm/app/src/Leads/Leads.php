<?php

declare(strict_types=1);

namespace App\Leads;

use App\Support\Db;
use App\Support\Env;

/** Чтение и запись заявок и их истории. */
final class Leads
{
    /** Имя заявки, если клиент его не указал (форма «только телефон»). */
    public const NO_NAME = 'Без имени';

    public static function hasName(?string $name): bool
    {
        return $name !== null && trim($name) !== '' && $name !== self::NO_NAME;
    }

    public const EVENT_LABELS = [
        'created' => 'Заявка',
        'repeat' => 'Повторная заявка',
        'sms' => 'SMS',
        'sms_failed' => 'SMS не отправлено',
        'taken' => 'Взята в работу',
        'call_miss' => 'Звонок — не дозвонились',
        'status' => 'Статус',
        'comment' => 'Комментарий',
        'assigned' => 'Ответственный',
        'scheduled' => 'Запланирован звонок',
        'queue' => 'Утренняя очередь',
        'reminder' => 'Напоминание',
        'max_started' => 'Клиент открыл бота MAX',
        'max_in' => 'MAX: клиент',
        'max_out' => 'MAX: ответ',
        'max_bot' => 'MAX: бот',
        'client_choice' => 'Выбор клиента',
        'edited' => 'Изменены данные',
        'final' => 'Последнее сообщение',
    ];

    public static function find(int $id): ?array
    {
        $stmt = Db::pdo()->prepare('SELECT * FROM leads WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    /** Последняя заявка с этим номером, обновлявшаяся не раньше $since. */
    public static function findRecentByPhone(string $phone, string $since): ?array
    {
        $stmt = Db::pdo()->prepare('SELECT * FROM leads WHERE phone = ? AND last_request_at >= ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$phone, $since]);

        return $stmt->fetch() ?: null;
    }

    public static function findByMaxUser(string $userId): ?array
    {
        $stmt = Db::pdo()->prepare('SELECT * FROM leads WHERE max_user_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$userId]);

        return $stmt->fetch() ?: null;
    }

    public static function insert(array $fields): int
    {
        // Номера продолжают счётчик старого сайта (LEADS_FIRST_ID), чтобы в почте не было двух заявок №1.
        $first = (int) Env::get('LEADS_FIRST_ID', '1');
        if ($first > 1 && !isset($fields['id']) && (int) Db::pdo()->query('SELECT MAX(id) FROM leads')->fetchColumn() < $first) {
            $fields = ['id' => $first] + $fields;
        }

        $columns = array_keys($fields);
        $sql = 'INSERT INTO leads (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        Db::pdo()->prepare($sql)->execute(array_values($fields));

        return (int) Db::pdo()->lastInsertId();
    }

    /** Обновляет поля и возвращает свежую строку. */
    public static function update(int $id, array $fields): array
    {
        $fields['updated_at'] = Clock::sql();
        $sets = implode(', ', array_map(static fn (string $c): string => "$c = ?", array_keys($fields)));
        Db::pdo()->prepare("UPDATE leads SET $sets WHERE id = ?")->execute([...array_values($fields), $id]);

        return self::find($id);
    }

    public static function delete(int $id): void
    {
        Db::pdo()->prepare('DELETE FROM leads WHERE id = ?')->execute([$id]);
    }

    public static function event(int $leadId, string $type, ?string $text = null, ?Actor $actor = null, ?array $data = null): void
    {
        Db::pdo()->prepare(
            'INSERT INTO lead_events (lead_id, created_at, type, staff_id, actor, text, data) VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $leadId, Clock::sql(), $type, $actor?->staffId, $actor === null ? null : mb_substr($actor->name, 0, 100),
            $text, $data === null ? null : json_encode($data, JSON_UNESCAPED_UNICODE),
        ]);
    }

    /** @return list<array<string, mixed>> */
    public static function events(int $leadId, ?array $types = null): array
    {
        $sql = 'SELECT * FROM lead_events WHERE lead_id = ?';
        $params = [$leadId];

        if ($types !== null) {
            $sql .= ' AND type IN (' . implode(',', array_fill(0, count($types), '?')) . ')';
            $params = [...$params, ...$types];
        }

        $stmt = Db::pdo()->prepare($sql . ' ORDER BY id');
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** Строка для поиска: имя в нижнем регистре и цифры телефона (LIKE в SQLite не понимает регистр кириллицы). */
    public static function searchString(string $name, string $phone, ?string $service = null): string
    {
        $digits = preg_replace('/\D+/', '', $phone);

        return mb_substr(mb_strtolower(trim("$name $service")) . " $digits 8" . substr($digits, 1), 0, 255);
    }

    public static function staffName(?int $staffId): ?string
    {
        if ($staffId === null) {
            return null;
        }

        $stmt = Db::pdo()->prepare('SELECT name FROM staff WHERE id = ?');
        $stmt->execute([$staffId]);

        return $stmt->fetchColumn() ?: null;
    }
}
