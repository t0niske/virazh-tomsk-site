<?php

declare(strict_types=1);

namespace App\Crm;

use App\Leads\Clock;
use App\Support\Db;

/** Сотрудники админки: администраторы и руководители. */
final class Staff
{
    public const MIN_PASSWORD = 10;

    public const ROLES = [
        'admin' => 'администратор',
        'manager' => 'руководитель',
    ];

    public static function find(int $id): ?array
    {
        $stmt = Db::pdo()->prepare('SELECT * FROM staff WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    public static function findByLogin(string $login): ?array
    {
        $stmt = Db::pdo()->prepare('SELECT * FROM staff WHERE login = ?');
        $stmt->execute([mb_strtolower(trim($login))]);

        return $stmt->fetch() ?: null;
    }

    /** @return list<array<string, mixed>> */
    public static function all(bool $activeOnly = false): array
    {
        return Db::pdo()->query('SELECT * FROM staff' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY active DESC, name')->fetchAll();
    }

    /** Текст ошибки или null, если всё хорошо. */
    public static function validate(string $login, string $name, ?string $password, bool $isNew): ?string
    {
        if (!preg_match('/^[a-z0-9._-]{3,32}$/', mb_strtolower(trim($login)))) {
            return 'Логин — от 3 до 32 латинских букв, цифр, точек, дефисов.';
        }

        if (trim($name) === '' || mb_strlen($name) > 100) {
            return 'Укажите имя сотрудника.';
        }

        if ($isNew && self::findByLogin($login) !== null) {
            return 'Такой логин уже есть.';
        }

        if ($password !== null && mb_strlen($password) < self::MIN_PASSWORD) {
            return 'Пароль — не меньше ' . self::MIN_PASSWORD . ' символов.';
        }

        return null;
    }

    public static function create(string $login, string $name, string $role, string $password): int
    {
        Db::pdo()->prepare('INSERT INTO staff (login, name, role, password_hash, active, created_at) VALUES (?, ?, ?, ?, 1, ?)')
            ->execute([mb_strtolower(trim($login)), trim($name), $role, password_hash($password, PASSWORD_DEFAULT), Clock::sql()]);

        return (int) Db::pdo()->lastInsertId();
    }

    public static function update(int $id, array $fields): void
    {
        if (isset($fields['password'])) {
            $fields['password_hash'] = password_hash($fields['password'], PASSWORD_DEFAULT);
            unset($fields['password']);
        }

        $sets = implode(', ', array_map(static fn (string $c): string => "$c = ?", array_keys($fields)));
        Db::pdo()->prepare("UPDATE staff SET $sets WHERE id = ?")->execute([...array_values($fields), $id]);
    }

    public static function managersCount(): int
    {
        return (int) Db::pdo()->query("SELECT COUNT(*) FROM staff WHERE role = 'manager' AND active = 1")->fetchColumn();
    }
}
