<?php

declare(strict_types=1);

// Тесты системы заявок: php tests/leads/run.php [фильтр]
// Работают на временной базе SQLite; SMS и MAX — только в памяти, никаких реальных запросов.

$tmp = sys_get_temp_dir() . '/virazh-crm-tests-' . getmypid();
@mkdir($tmp, 0777, true);

foreach ([
    'APP_ENV' => 'testing',
    'APP_URL' => 'https://virazh-tomsk.ru',
    'DB_DSN' => 'sqlite:' . $tmp . '/test.sqlite',
    'DB_USER' => '',
    'DB_PASS' => '',
    'SMS_DRIVER' => 'log',
    'MAIL_DRIVER' => 'log',
    'TELEGRAM_DRIVER' => 'log',
    'LEADS_FIRST_ID' => '1',
    'DEEPSEEK_API_KEY' => '',
    'CHAT_DAILY_LIMIT' => '500',
    'MAX_DRIVER' => 'log',
    'MAX_BOT_NAME' => 'stodorog_bot',
    'MAX_ADMIN_CHAT_ID' => '-500',
    'MAX_WEBHOOK_SECRET' => 'test-secret',
    'LEADS_SECRET' => 'test-key',
    'LEADS_LOG_DIR' => $tmp . '/logs',
    'SMARTCAPTCHA_SERVER_KEY' => '',
    'SCHOOL_PHONE' => '+79832345656',
    'WORK_DAYS' => '1-6',
    'WORK_HOURS' => '10:00-18:00',
] as $key => $value) {
    putenv("$key=$value");
}

require dirname(__DIR__, 2) . '/app/bootstrap.php';
require __DIR__ . '/helpers.php';

App\Leads\Migrator::run();

foreach (glob(__DIR__ . '/*Test.php') as $file) {
    require $file;
}

$filter = $argv[1] ?? '';
$failed = 0;
$passed = 0;

foreach (Tests::$cases as $name => $fn) {
    if ($filter !== '' && !str_contains(mb_strtolower($name), mb_strtolower($filter))) {
        continue;
    }

    Tests::reset();

    try {
        $fn();
        $passed++;
        echo "  ✓ $name\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ $name\n      " . $e->getMessage() . "\n      " . basename($e->getFile()) . ':' . $e->getLine() . "\n";
    }
}

App\Leads\Clock::set(null);
echo "\nПройдено: $passed, с ошибками: $failed\n";

array_map('unlink', glob($tmp . '/logs/*') ?: []);
@rmdir($tmp . '/logs');
@unlink($tmp . '/test.sqlite');
@rmdir($tmp);
exit($failed > 0 ? 1 : 0);
