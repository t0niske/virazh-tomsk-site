<?php

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

// Классы App\Foo\Bar лежат в app/src/Foo/Bar.php.
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }

    $file = APP_ROOT . '/app/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';

    if (is_file($file)) {
        require $file;
    }
});

App\Support\Env::load(APP_ROOT . '/.env');

date_default_timezone_set(App\Support\Env::get('APP_TIMEZONE', 'Asia/Tomsk'));
