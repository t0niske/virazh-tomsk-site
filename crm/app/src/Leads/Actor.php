<?php

declare(strict_types=1);

namespace App\Leads;

/** Кто совершил действие: сотрудник из админки, участник чата MAX, клиент или сама система. */
final class Actor
{
    public function __construct(
        public readonly ?int $staffId,
        public readonly string $name,
        public readonly string $role = '',
    ) {
    }

    public static function system(): self
    {
        return new self(null, 'Система');
    }

    public static function client(string $name = 'Клиент'): self
    {
        return new self(null, $name);
    }

    /** @param array<string, mixed> $staff строка таблицы staff */
    public static function staff(array $staff): self
    {
        return new self((int) $staff['id'], (string) $staff['name'], (string) $staff['role']);
    }

    public function isManager(): bool
    {
        return $this->role === 'manager';
    }
}
