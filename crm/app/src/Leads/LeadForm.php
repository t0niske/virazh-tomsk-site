<?php

declare(strict_types=1);

namespace App\Leads;

/** Проверка и очистка полей заявки по контракту docs/lead-api.md. */
final class LeadForm
{
    public const UTM = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];

    /** @return array<string, ?string> все поля заявки со значениями по умолчанию */
    public static function empty(): array
    {
        return [
            'name' => '',
            'phone' => '',
            'phone_raw' => null,
            'service' => null,
            'page_url' => null,
            'contact_time' => 'now',
            'channel' => 'call',
            'utm_source' => null,
            'utm_medium' => null,
            'utm_campaign' => null,
            'utm_content' => null,
            'utm_term' => null,
            'yclid' => null,
            'ip' => null,
            'user_agent' => null,
            'visitor_id' => null,
            'visit_id' => null,
            'chat_id' => null,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{data: array<string, ?string>, errors: array<string, string>}
     */
    public static function validate(array $input): array
    {
        $data = self::empty();
        $errors = [];

        // Имя необязательно: форма первого экрана сайта просит только телефон.
        $data['name'] = self::clean($input['name'] ?? '', 100) ?? Leads::NO_NAME;

        $rawPhone = self::clean($input['phone'] ?? '', 64);
        $data['phone_raw'] = $rawPhone;
        $phone = Phone::normalize($rawPhone);

        if ($rawPhone === null) {
            $errors['phone'] = 'Укажите номер телефона.';
        } elseif ($phone === null) {
            $errors['phone'] = 'Проверьте номер: нужно 10 цифр после +7.';
        } else {
            $data['phone'] = $phone;
        }

        $consent = strtolower(trim((string) ($input['consent'] ?? '')));
        if (!in_array($consent, ['1', 'on', 'true', 'yes', 'да'], true)) {
            $errors['consent'] = 'Нужно согласие на обработку персональных данных.';
        }

        $data['service'] = self::clean($input['service'] ?? '', 150);

        $url = self::clean($input['page_url'] ?? '', 500);
        $data['page_url'] = $url !== null && preg_match('#^https?://#i', $url) ? $url : null;

        $contactTime = (string) ($input['contact_time'] ?? '');
        $data['contact_time'] = array_key_exists($contactTime, Source::CONTACT_TIMES) ? $contactTime : 'now';

        $channel = (string) ($input['channel'] ?? '');
        $data['channel'] = array_key_exists($channel, Source::CHANNELS) ? $channel : 'call';

        foreach (self::UTM as $key) {
            $data[$key] = self::clean($input[$key] ?? '', 150);
        }

        $yclid = preg_replace('/[^0-9A-Za-z_-]/', '', (string) ($input['yclid'] ?? ''));
        $data['yclid'] = $yclid !== '' ? substr($yclid, 0, 64) : null;

        // Связь с заходом на сайт (docs/visits-api.md): cookie sd_vid и sd_sid счётчика.
        foreach (['visitor_id', 'visit_id'] as $key) {
            $value = (string) ($input[$key] ?? '');
            $data[$key] = preg_match('/^[A-Za-z0-9_-]{8,40}$/', $value) ? $value : null;
        }

        // Заявка из чата-бота («Позвоните мне», docs/chat-api.md).
        $chat = (string) ($input['chat_id'] ?? '');
        $data['chat_id'] = preg_match('/^[A-Za-z0-9_-]{16,40}$/', $chat) ? $chat : null;

        return ['data' => $data, 'errors' => $errors];
    }

    /** Общая фраза для ответа с ошибками. */
    public static function errorMessage(array $errors): string
    {
        return match (true) {
            count($errors) > 1 => 'Проверьте данные в форме.',
            isset($errors['phone']) => 'Проверьте номер телефона.',
            isset($errors['name']) => 'Укажите имя.',
            isset($errors['consent']) => 'Подтвердите согласие на обработку данных.',
            isset($errors['captcha']) => 'Подтвердите, что вы не робот.',
            default => 'Проверьте данные в форме.',
        };
    }

    private static function clean(mixed $value, int $max): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = strip_tags((string) $value);
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
