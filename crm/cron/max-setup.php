<?php

declare(strict_types=1);

// Разовая настройка бота MAX после выкладки на хостинг:
//   php cron/max-setup.php
// Проверяет токен и подписывает бота на вебхук APP_URL/max/webhook с секретом MAX_WEBHOOK_SECRET.

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Max\HttpMaxApi;
use App\Support\Env;

if (PHP_SAPI !== 'cli') {
    exit;
}

$token = (string) Env::get('MAX_BOT_TOKEN', '');
$secret = (string) Env::get('MAX_WEBHOOK_SECRET', '');
$url = rtrim((string) Env::get('APP_URL', ''), '/') . '/max/webhook';

if (Env::get('APP_ENV') !== 'production' || Env::get('MAX_DRIVER') !== 'http') {
    exit("Нужны APP_ENV=production и MAX_DRIVER=http — при разработке запросы к MAX не отправляем.\n");
}

if ($token === '' || !preg_match('/^[A-Za-z0-9_-]{5,256}$/', $secret) || !str_starts_with($url, 'https://')) {
    exit("Заполните в .env MAX_BOT_TOKEN, MAX_WEBHOOK_SECRET (латиница, цифры, _ и -) и APP_URL с https://\n");
}

$api = new HttpMaxApi($token, (string) Env::get('MAX_API_URL', 'https://platform-api2.max.ru'), (string) Env::get('MAX_CA_FILE', ''));
$me = $api->me();

if ($me === null) {
    exit("Не удалось обратиться к MAX: проверьте токен и storage/logs/max.log\n");
}

echo 'Бот: ' . ($me['first_name'] ?? $me['name'] ?? '?') . ' (@' . ($me['username'] ?? '?') . ")\n";

$result = $api->subscribe($url, $secret);
echo ($result['success'] ?? false) ? "Вебхук подключён: $url\n" : "Не удалось подключить вебхук, подробности в storage/logs/max.log\n";
echo "Дальше: добавьте бота в чат «Заявки» — он пришлёт ID чата для MAX_ADMIN_CHAT_ID.\n";
