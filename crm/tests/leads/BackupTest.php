<?php

declare(strict_types=1);

use App\Leads\Backup;
use App\Support\Db;

// На хостинге reg.ru SQLite 3.26 — VACUUM INTO там нет, работает ветка «копия файла под блокировкой».
test('Резервная копия SQLite без VACUUM INTO: целая база с заявками', function (): void {
    Tests::lead(['name' => 'Пётр', 'phone' => '+7 913 111-22-33']);
    Tests::lead(['name' => 'Анна', 'phone' => '+7 913 444-55-66']);

    Backup::$vacuumInto = false;

    try {
        $gz = Backup::make();
    } finally {
        Backup::$vacuumInto = null;
    }

    ok(is_file($gz), 'файл копии создан');
    ok(!Db::pdo()->inTransaction(), 'блокировка снята');

    $copy = sys_get_temp_dir() . '/virazh-backup-check-' . getmypid() . '.sqlite';
    file_put_contents($copy, gzdecode((string) file_get_contents($gz)));
    unlink($gz);

    $restored = new PDO('sqlite:' . $copy);
    eq('ok', $restored->query('PRAGMA integrity_check')->fetchColumn());
    eq(2, (int) $restored->query('SELECT COUNT(*) FROM leads')->fetchColumn());
    $restored = null;
    unlink($copy);
});
