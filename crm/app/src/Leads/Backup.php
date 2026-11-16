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
            $pdo->exec('VACUUM INTO ' . $pdo->quote($file));
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
