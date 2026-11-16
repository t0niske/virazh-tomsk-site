<?php

declare(strict_types=1);

namespace App\Leads\Notify;

use App\Leads\Log;
use App\Leads\Source;
use App\Leads\Status;
use App\Leads\Tls;
use App\Support\Env;
use DateTimeImmutable;

/**
 * Оповещение администраторам в Telegram — БЕЗ персональных данных: ни имени, ни телефона, ни IP.
 * Только номер заявки, услуга, страница, источник, удобное время и ссылка в админку (нужен вход).
 * Переписка с клиентами — через MAX. TELEGRAM_DRIVER=log (по умолчанию) пишет в storage/logs/telegram.log.
 */
final class Telegram
{
    public static function text(array $lead, bool $repeat): string
    {
        $link = rtrim((string) Env::get('APP_URL', ''), '/') . '/crm/lead/' . $lead['id'];

        if ($repeat) {
            return "🔁 Повторная заявка №{$lead['id']} · " . Status::label($lead['status']) . "\nОткрыть: $link";
        }

        $time = (new DateTimeImmutable($lead['created_at']))->format('H:i');
        $lines = ["🔔 Новая заявка №{$lead['id']} · $time"];

        if (($lead['suspect'] ?? null) !== null) {
            $lines[] = '⚠️ Похоже на робота: ' . $lead['suspect'];
        }

        if (($lead['service'] ?? '') !== '') {
            $lines[] = $lead['service'];
        }

        // Только путь страницы: в адресе могут оказаться чужие параметры.
        $path = ($lead['page_url'] ?? '') !== '' ? (string) parse_url((string) $lead['page_url'], PHP_URL_PATH) : '';
        if ($path !== '') {
            $lines[] = 'Страница: ' . $path;
        }

        $lines[] = 'Откуда: ' . $lead['source'];
        $lines[] = 'Удобно: ' . (Source::CONTACT_TIMES[$lead['contact_time']] ?? 'сейчас')
            . ' · ' . (Source::CHANNELS[$lead['channel']] ?? 'звонок');
        $lines[] = 'Открыть: ' . $link;

        return implode("\n", $lines);
    }

    public static function send(string $text): void
    {
        $token = trim((string) Env::get('TELEGRAM_BOT_TOKEN', ''));
        $chatId = trim((string) Env::get('TELEGRAM_CHAT_ID', ''));

        if (Env::get('TELEGRAM_DRIVER', 'log') !== 'http') {
            Log::write('telegram.log', $text);

            return;
        }

        if ($token === '' || $chatId === '') {
            return;
        }

        $response = self::request($token, 'sendMessage', ['chat_id' => $chatId, 'text' => $text, 'disable_web_page_preview' => true]);

        if (($response['ok'] ?? false) !== true) {
            Log::error('Telegram: сообщение не отправлено — ' . ($response['description'] ?? 'нет ответа'));
        }
    }

    /** Запрос к Bot API. Возвращает разобранный JSON или null. */
    public static function request(string $token, string $method, array $params = []): ?array
    {
        $ch = curl_init("https://api.telegram.org/bot$token/$method");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($params, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
        ] + Tls::options((string) Env::get('TELEGRAM_CA_FILE', '')));
        $body = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            Log::error('Telegram: нет связи — ' . $error);

            return null;
        }

        return json_decode((string) $body, true) ?: null;
    }
}
