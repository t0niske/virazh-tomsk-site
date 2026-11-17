<?php

declare(strict_types=1);

namespace App\Leads;

use App\Support\Db;
use PDO;

/**
 * Резервная копия базы в storage/backups: SQLite — копия файла, MySQL — SQL-дамп, сжатый gzip.
 * Без mysqldump: на обычном хостинге его может не быть.
 */
final class Backup
{
    public static function dir(): string
    {
        return APP_ROOT . '/storage/backups';
    }

    public static function make(int $keepDays = 14): string
    {
        $dir = self::dir();

        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        $pdo = Db::pdo();
        $stamp = Clock::now()->format('Y-m-d_His');

        if (Db::driver() === 'sqlite') {
            $file = "$dir/leads_$stamp.sqlite";
            self::copySqlite($pdo, $file);
            $gz = $file . '.gz';
            file_put_contents($gz, gzencode((string) file_get_contents($file), 6));
            unlink($file);
        } else {
            $gz = "$dir/leads_$stamp.sql.gz";
            self::dumpMysql($pdo, $gz);
        }

        @chmod($gz, 0600);
        self::prune($keepDays);

        return $gz;
    }

    /** null — по версии SQLite; false — всегда копией файла (для тестов той ветки, что работает на хостинге). */
    public static ?bool $vacuumInto = null;

    /**
     * VACUUM INTO есть только с SQLite 3.27, а на хостинге reg.ru 3.26 (17.11.2026: ночные копии падали с «near INTO»).
     * Там — копия файла под BEGIN IMMEDIATE: пока она идёт, никто не пишет, чтение не мешает.
     */
    private static function copySqlite(PDO $pdo, string $file): void
    {
        $vacuum = self::$vacuumInto
            ?? version_compare((string) $pdo->query('SELECT sqlite_version()')->fetchColumn(), '3.27.0', '>=');

        if ($vacuum) {
            $pdo->exec('VACUUM INTO ' . $pdo->quote($file));

            return;
        }

        $source = (string) ($pdo->query('PRAGMA database_list')->fetch(PDO::FETCH_ASSOC)['file'] ?? '');

        if ($source === '' || !is_file($source)) {
            throw new \RuntimeException('Не найден файл базы SQLite для копии');
        }

        $pdo->exec('BEGIN IMMEDIATE');

        try {
            if (!copy($source, $file)) {
                throw new \RuntimeException("Не удалось скопировать базу в $file");
            }
        } finally {
            $pdo->exec('COMMIT');
        }
    }

    private static function dumpMysql(PDO $pdo, string $file): void
    {
        $out = gzopen($file, 'wb6');
        gzwrite($out, "-- Резервная копия базы заявок, " . Clock::sql() . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");

        foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $create = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM)[1];
            gzwrite($out, "DROP TABLE IF EXISTS `$table`;\n$create;\n\n");

            $rows = $pdo->query("SELECT * FROM `$table`", PDO::FETCH_ASSOC);

            foreach ($rows as $row) {
                $values = array_map(static fn ($v): string => $v === null ? 'NULL' : $pdo->quote((string) $v), $row);
                gzwrite($out, "INSERT INTO `$table` (`" . implode('`, `', array_keys($row)) . '`) VALUES (' . implode(', ', $values) . ");\n");
            }

            gzwrite($out, "\n");
        }

        gzwrite($out, "SET FOREIGN_KEY_CHECKS=1;\n");
        gzclose($out);
    }

    private static function prune(int $keepDays): void
    {
        $border = time() - $keepDays * 86400;

        foreach (glob(self::dir() . '/leads_*') ?: [] as $file) {
            if (filemtime($file) < $border) {
                unlink($file);
            }
        }
    }
}
