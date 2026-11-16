<?php

declare(strict_types=1);

// Разовая настройка оповещений в Telegram на хостинге:
//   php cron/telegram-setup.php          — проверить токен и показать ID чатов, куда писали боту
//   php cron/telegram-setup.php --test   — отправить пробное сообщение в TELEGRAM_CHAT_ID
// Перед запуском: TELEGRAM_BOT_TOKEN в .env, бот добавлен в группу, в группе написано любое сообщение.

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Leads\Notify\Telegram;
use App\Support\Env;

if (PHP_SAPI !== 'cli') {
    exit;
}

$token = trim((string) Env::get('TELEGRAM_BOT_TOKEN', ''));

if ($token === '') {
    exit("Впишите в .env TELEGRAM_BOT_TOKEN (выдаёт @BotFather).\n");
}

$me = Telegram::request($token, 'getMe');

if (($me['ok'] ?? false) !== true) {
    exit('Токен не подошёл: ' . ($me['description'] ?? 'нет ответа от api.telegram.org') . "\n");
}

echo 'Бот: @' . $me['result']['username'] . "\n";

if (in_array('--test', $argv, true)) {
    $chatId = trim((string) Env::get('TELEGRAM_CHAT_ID', ''));

    if ($chatId === '') {
        exit("Сначала впишите TELEGRAM_CHAT_ID.\n");
    }

    $sent = Telegram::request($token, 'sendMessage', ['chat_id' => $chatId, 'text' => 'Проверка: оповещения о заявках «Авто-Вираж» подключены.']);
    exit(($sent['ok'] ?? false) ? "Пробное сообщение отправлено.\n" : 'Не отправилось: ' . ($sent['description'] ?? '?') . "\n");
}

$updates = Telegram::request($token, 'getUpdates')['result'] ?? [];
$chats = [];

foreach ($updates as $update) {
    $chat = $update['message']['chat'] ?? $update['my_chat_member']['chat'] ?? null;

    if ($chat !== null) {
        $chats[$chat['id']] = ($chat['title'] ?? trim(($chat['first_name'] ?? '') . ' ' . ($chat['last_name'] ?? ''))) . ' (' . $chat['type'] . ')';
    }
}

if ($chats === []) {
    exit("Боту пока никто не писал. Добавьте его в группу, напишите там любое сообщение и запустите снова.\n");
}

echo "Чаты (ID для TELEGRAM_CHAT_ID — у групп с минусом):\n";

foreach ($chats as $id => $title) {
    echo "  $id — $title\n";
}
