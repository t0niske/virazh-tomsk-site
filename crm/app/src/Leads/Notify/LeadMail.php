<?php

declare(strict_types=1);

namespace App\Leads\Notify;

use App\Leads\Leads;
use App\Leads\Log;
use App\Leads\Phone;
use App\Support\Env;
use DateTimeImmutable;

/**
 * Письмо о заявке на почту — в том же виде, что слал send-form.php старого сайта:
 * тема «ID: N. Новая заявка с сайта Вираж» (как у send-form.php Виража), простой текст, отправка встроенным mail() хостинга.
 * MAIL_DRIVER=log (по умолчанию) пишет письмо в storage/logs/mail.log, MAIL_DRIVER=mail — отправляет.
 */
final class LeadMail
{
    public static function subject(array $lead, bool $repeat): string
    {
        return "ID: {$lead['id']}. " . ($repeat ? 'Повторная заявка' : 'Новая заявка') . ' с сайта Вираж';
    }

    /**
     * Текст письма. Услуга, страница и IP — этой отправки формы ($request), как у старого скрипта;
     * без $request — из карточки заявки.
     */
    public static function body(array $lead, bool $repeat, ?string $ip, ?array $request = null): string
    {
        $service = $request !== null ? ($request['service'] ?? null) : ($lead['service'] ?? null);
        $page = $request !== null ? ($request['page_url'] ?? null) : ($lead['page_url'] ?? null);
        $date = new DateTimeImmutable($repeat ? $lead['last_request_at'] : $lead['created_at']);
        $lines = [
            ($repeat ? 'ПОВТОРНАЯ ЗАЯВКА' : 'НОВАЯ ЗАЯВКА') . " №{$lead['id']}",
            str_repeat('=', 40),
            '',
            'Имя: ' . (trim((string) $lead['name']) !== '' ? $lead['name'] : Leads::NO_NAME),
            'Телефон: ' . Phone::format($lead['phone']),
            'Что интересует: ' . (($service ?? '') !== '' ? $service : 'не указано'),
            'Страница: ' . (($page ?? '') !== '' ? $page : 'Не определена'),
            '',
            'Дата: ' . $date->format('d.m.Y H:i:s'),
            'IP: ' . ($ip !== null && $ip !== '' ? $ip : 'не определён'),
        ];

        if ($repeat) {
            $lines[] = '';
            $lines[] = 'Обращений с этого номера: ' . (int) $lead['request_count']
                . ', первое — ' . (new DateTimeImmutable($lead['created_at']))->format('d.m.Y H:i');
        }

        // ссылка на карточку в админке /crm — последней строкой
        $url = rtrim((string) Env::get('APP_URL', ''), '/');
        if ($url !== '') {
            $lines[] = '';
            $lines[] = 'Карточка заявки: ' . $url . '/crm/lead/' . $lead['id'];
        }

        return implode("\n", $lines);
    }

    public static function headers(string $from): string
    {
        // From — только голый адрес: так встроенный отправитель хостинга берёт его как адрес отправителя.
        return implode("\r\n", [
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . $from,
            'Reply-To: ' . $from,
            'X-Mailer: PHP/' . phpversion(),
        ]);
    }

    public static function send(array $lead, bool $repeat, ?string $ip, ?array $request = null): void
    {
        $to = trim((string) Env::get('MAIL_TO', ''));
        $from = trim((string) Env::get('MAIL_FROM', '')) ?: $to;
        $subject = self::subject($lead, $repeat);
        $body = self::body($lead, $repeat, $ip, $request);

        if (Env::get('MAIL_DRIVER', 'log') !== 'mail') {
            Log::write('mail.log', "Кому: " . ($to ?: '(MAIL_TO не задан)') . "\nТема: $subject\n$body\n");

            return;
        }

        if ($to === '') {
            return;
        }

        $ok = mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, self::headers($from));

        if (!$ok) {
            Log::error("Письмо о заявке №{$lead['id']} не отправлено: mail() вернул false");
        }
    }
}
