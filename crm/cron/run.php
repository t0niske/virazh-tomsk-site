<?php

declare(strict_types=1);

// Планировщик системы заявок. Cron на хостинге — раз в минуту:
//   * * * * * php /путь/к/сайту/cron/run.php
// Ручная проверка с подменой времени: php cron/run.php --now="2026-11-02 10:00"

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Leads\Clock;
use App\Leads\Log;
use App\Leads\Scheduler;

if (PHP_SAPI !== 'cli') {
    exit;
}

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--now=')) {
        Clock::set(substr($arg, 6));
    }
}

// Не запускаем второй экземпляр, если предыдущий ещё работает.
$lockDir = APP_ROOT . '/storage';
$lock = fopen($lockDir . '/cron.lock', 'c');

if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}

try {
    foreach (Scheduler::run() as $line) {
        Log::write('cron.log', $line);
        echo $line, "\n";
    }
} catch (Throwable $e) {
    Log::error('Ошибка планировщика', $e);
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
