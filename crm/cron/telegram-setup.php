<?php

declare(strict_types=1);

// Настройка оповещений о заявках в Telegram на хостинге (инструкция — cron/README.md, раздел про Telegram):
//   php cron/telegram-setup.php --install — спросит токен бота (при вводе не виден), проверит его, найдёт чат,
//                                           куда писали боту, сам впишет всё в .env и пришлёт пробное сообщение
//   php cron/telegram-setup.php           — проверить токен из .env и показать ID чатов, куда писали боту
//   php cron/telegram-setup.php --test    — отправить пробное сообщение в TELEGRAM_CHAT_ID
// Токен никуда не выводится и хранится только в .env на сервере.

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Leads\Notify\Telegram;
use App\Support\Env;
use App\Support\EnvFile;

if (PHP_SAPI !== 'cli') {
    exit;
}

const TEST_TEXT = "Проверка: оповещения о заявках «Авто-Вираж» подключены.\n"
    . 'Сюда будут приходить новые заявки с сайта: номер, услуга, страница и ссылка на карточку в /crm (имя и телефон — только в карточке).';

/** Чаты, где боту писали: [id => «название (тип)»]. */
function chats(string $token): array
{
    $out = [];

    foreach (Telegram::request($token, 'getUpdates')['result'] ?? [] as $update) {
        $chat = $update['message']['chat'] ?? $update['my_chat_member']['chat'] ?? null;

        if ($chat !== null) {
            $out[(string) $chat['id']] = ($chat['title'] ?? trim(($chat['first_name'] ?? '') . ' ' . ($chat['last_name'] ?? ''))) . ' (' . $chat['type'] . ')';
        }
    }

    return $out;
}

/** Ввод без отображения на экране (в терминале по SSH). */
function askSecret(string $prompt): string
{
    echo $prompt;
    $hide = DIRECTORY_SEPARATOR === '/' && function_exists('shell_exec') && function_exists('stream_isatty') && stream_isatty(STDIN);

    if ($hide) {
        shell_exec('stty -echo');
    }

    $value = trim((string) fgets(STDIN));

    if ($hide) {
        shell_exec('stty echo');
        echo "\n";
    }

    return $value;
}

if (in_array('--install', $argv, true)) {
    $token = askSecret('Токен бота от @BotFather (при вводе не виден, вставьте и нажмите Enter): ');

    if (!preg_match('/^\d{5,}:[A-Za-z0-9_-]{30,}$/', $token)) {
        exit("Это не похоже на токен бота (вид: 123456789:AA…). Скопируйте его из @BotFather ещё раз.\n");
    }

    $me = Telegram::request($token, 'getMe');

    if (($me['ok'] ?? false) !== true) {
        exit('Токен не подошёл: ' . ($me['description'] ?? 'нет ответа от api.telegram.org') . "\n");
    }

    echo 'Бот: @' . $me['result']['username'] . "\n";
    $chats = chats($token);

    if ($chats === []) {
        exit("Боту пока никто не писал. Откройте бота в Telegram и нажмите «Старт» (или добавьте его в группу и напишите там любое сообщение), затем запустите команду снова.\n");
    }

    $ids = array_keys($chats);
    $chatId = $ids[0];

    if (count($ids) > 1) {
        echo "Куда присылать заявки?\n";
        foreach ($ids as $n => $id) {
            echo '  ' . ($n + 1) . ") {$chats[$id]}\n";
        }
        echo 'Номер: ';
        $pick = (int) trim((string) fgets(STDIN));

        if (!isset($ids[$pick - 1])) {
            exit("Нет такого номера. В .env ничего не записано.\n");
        }

        $chatId = $ids[$pick - 1];
    }

    $sent = Telegram::request($token, 'sendMessage', ['chat_id' => $chatId, 'text' => TEST_TEXT]);

    if (($sent['ok'] ?? false) !== true) {
        exit('Пробное сообщение не отправилось: ' . ($sent['description'] ?? '?') . ". В .env ничего не записано.\n");
    }

    EnvFile::set(APP_ROOT . '/.env', ['TELEGRAM_DRIVER' => 'http', 'TELEGRAM_BOT_TOKEN' => $token, 'TELEGRAM_CHAT_ID' => $chatId]);
    exit("Готово: оповещения включены, пробное сообщение — в чате «{$chats[$chatId]}». Настройки записаны в .env.\n");
}

$token = trim((string) Env::get('TELEGRAM_BOT_TOKEN', ''));

if ($token === '') {
    exit("Токена нет. Запустите: php cron/telegram-setup.php --install\n");
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

    $sent = Telegram::request($token, 'sendMessage', ['chat_id' => $chatId, 'text' => TEST_TEXT]);
    exit(($sent['ok'] ?? false) ? "Пробное сообщение отправлено.\n" : 'Не отправилось: ' . ($sent['description'] ?? '?') . "\n");
}

$chats = chats($token);

if ($chats === []) {
    exit("Боту пока никто не писал. Нажмите у бота «Старт» или напишите в группе с ним и запустите снова.\n");
}

echo "Чаты (ID для TELEGRAM_CHAT_ID — у групп с минусом):\n";

foreach ($chats as $id => $title) {
    echo "  $id — $title\n";
}
