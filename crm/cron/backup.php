<?php

declare(strict_types=1);

// Ежедневная резервная копия базы заявок в storage/backups (хранится 14 дней).
// Cron на хостинге — раз в сутки ночью:
//   30 3 * * * php /путь/к/сайту/cron/backup.php

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Leads\Actor;
use App\Leads\Audit;
use App\Leads\Backup;
use App\Leads\Log;

if (PHP_SAPI !== 'cli') {
    exit;
}

try {
    $file = Backup::make((int) ($argv[1] ?? 14));
    $size = round(filesize($file) / 1024) . ' КБ';
    Audit::log(Actor::system(), 'backup', null, basename($file) . ", $size");
    Log::write('cron.log', "Резервная копия: $file ($size)");
    echo "Готово: $file ($size)\n";
} catch (Throwable $e) {
    Log::error('Ошибка резервного копирования', $e);
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
