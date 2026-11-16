<?php

declare(strict_types=1);

namespace App\Sms;

use App\Leads\Tls;
use App\Support\Env;

/**
 * SMS.ru (https://sms.ru/api/send). Нужен ключ SMS_API_KEY из личного кабинета,
 * имя отправителя SMS_SENDER — после согласования в SMS.ru (пусто — номер по умолчанию).
 */
final class SmsRuGateway implements SmsGateway
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $sender = '',
        private readonly bool $test = false,
    ) {
    }

    public function send(string $phone, string $text): array
    {
        $params = [
            'api_id' => $this->apiKey,
            'to' => ltrim($phone, '+'),
            'msg' => $text,
            'json' => 1,
        ];

        if ($this->sender !== '') {
            $params['from'] = $this->sender;
        }

        if ($this->test) {
            $params['test'] = 1;
        }

        $ch = curl_init('https://sms.ru/sms/send');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
        ] + Tls::options((string) Env::get('SMS_CA_FILE', '')));
        $body = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return ['ok' => false, 'id' => null, 'error' => 'Нет связи с SMS.ru: ' . $error];
        }

        $json = json_decode((string) $body, true);
        $item = $json['sms'][ltrim($phone, '+')] ?? null;

        if (($json['status'] ?? '') === 'OK' && ($item['status'] ?? '') === 'OK') {
            return ['ok' => true, 'id' => (string) ($item['sms_id'] ?? ''), 'error' => null];
        }

        return [
            'ok' => false,
            'id' => null,
            'error' => (string) ($item['status_text'] ?? $json['status_text'] ?? 'Неизвестный ответ SMS.ru'),
        ];
    }
}
