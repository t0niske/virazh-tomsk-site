<?php

declare(strict_types=1);

namespace App\Leads;

use App\Support\Db;
use PDO;

/**
 * Применяет файлы database/migrations/*.sql по порядку имён, каждый один раз.
 * Применённые записываются в таблицу migrations.
 */
final class Migrator
{
    public static function dir(): string
    {
        return APP_ROOT . '/database/migrations';
    }

    /** @return list<string> имена применённых сейчас файлов */
    public static function run(?PDO $pdo = null): array
    {
        $pdo ??= Db::pdo();
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        $pdo->exec('CREATE TABLE IF NOT EXISTS migrations (name VARCHAR(190) NOT NULL PRIMARY KEY, applied_at DATETIME NOT NULL)'
            . self::tableOptions($driver));

        $done = $pdo->query('SELECT name FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
        $files = glob(self::dir() . '/*.sql') ?: [];
        sort($files);

        $applied = [];

        foreach ($files as $file) {
            $name = basename($file);

            if (in_array($name, $done, true)) {
                continue;
            }

            $sql = strtr(file_get_contents($file), [
                '{{pk}}' => $driver === 'mysql'
                    ? 'INT NOT NULL AUTO_INCREMENT PRIMARY KEY'
                    : 'INTEGER PRIMARY KEY AUTOINCREMENT',
                '{{table}}' => self::tableOptions($driver),
            ]);

            // MySQL не откатывает CREATE TABLE в транзакции, поэтому транзакция только для SQLite.
            $useTx = $driver === 'sqlite';
            if ($useTx) {
                $pdo->beginTransaction();
            }

            foreach (self::statements($sql) as $statement) {
                $pdo->exec($statement);
            }

            $stmt = $pdo->prepare('INSERT INTO migrations (name, applied_at) VALUES (?, ?)');
            $stmt->execute([$name, date('Y-m-d H:i:s')]);

            if ($useTx) {
                $pdo->commit();
            }

            $applied[] = $name;
        }

        return $applied;
    }

    /** @return list<string> */
    private static function statements(string $sql): array
    {
        $lines = array_filter(
            preg_split('/\R/u', $sql),
            static fn (string $line): bool => !str_starts_with(ltrim($line), '--'),
        );

        return array_values(array_filter(
            array_map('trim', explode(';', implode("\n", $lines))),
            static fn (string $s): bool => $s !== '',
        ));
    }

    private static function tableOptions(string $driver): string
    {
        return $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    }
}
