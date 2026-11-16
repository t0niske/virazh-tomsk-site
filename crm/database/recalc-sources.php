<?php

declare(strict_types=1);

// Пересчитать источник у сохранённых заходов после изменения правил: php database/recalc-sources.php
// Можно запускать сколько угодно раз: меняет только то, что по новым правилам определяется иначе.

require dirname(__DIR__) . '/app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit("Запускайте из командной строки.\n");
}

$changes = App\Visits\Visits::recalcSources();

if ($changes === []) {
    echo "Все источники уже определены по текущим правилам.\n";
}

foreach ($changes as $change => $count) {
    echo "$change: $count\n";
}
