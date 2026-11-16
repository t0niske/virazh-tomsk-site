<?php

declare(strict_types=1);

// Создаёт и обновляет таблицы системы заявок: php database/migrate.php

require dirname(__DIR__) . '/app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit("Запускайте из командной строки.\n");
}

$applied = App\Leads\Migrator::run();

echo $applied === []
    ? "База уже актуальна.\n"
    : 'Применено: ' . implode(', ', $applied) . "\n";
