<?php

declare(strict_types=1);

namespace App\Sms;

use App\Leads\Tls;
use App\Support\Env;

/**
 * P1SMS (https://p1sms.ru, метод POST https://admin.p1sms.ru/apiSms/create).
 * Канал char — буквенный отправитель (своё имя или общее, например INFO), digit — цифровой номер.
 * Если своё имя не зарегистрировано у оператора, P1SMS сам подставляет общее имя.
 */
final class P1smsGateway implements SmsGateway
{
    private const URL = 'https://admin.p1sms.ru/apiSms/create';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $sender = 'INFO',
        private readonly string $channel = 'char',
    ) {
    }

    public function send(string $phone, string $text): array
    {
        $ch = curl_init(self::URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($this->payload($phone, $text), JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
        ] + Tls::options((string) Env::get('SMS_CA_FILE', '')));
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return ['ok' => false, 'id' => null, 'error' => 'Нет связи с P1SMS: ' . $error];
        }

        return self::parse($code, (string) $body);
    }

    /** Тело запроса. Номер — 11 цифр без «+». */
    public function payload(string $phone, string $text): array
    {
        $sms = ['channel' => $this->channel, 'phone' => ltrim($phone, '+'), 'text' => $text];

        if ($this->channel === 'char') {
            $sms['sender'] = $this->sender;
        }

        return ['apiKey' => $this->apiKey, 'sms' => [$sms]];
    }

    /** @return array{ok: bool, id: ?string, error: ?string} */
    public static function parse(int $code, string $body): array
    {
        $json = json_decode($body, true);

        if (!is_array($json)) {
            return ['ok' => false, 'id' => null, 'error' => "P1SMS ответил не JSON (HTTP $code): " . mb_substr(trim($body), 0, 120)];
        }

        $item = $json['data'][0] ?? null;

        if (($json['status'] ?? '') === 'success' && is_array($item) && ($item['status'] ?? '') !== 'error') {
            return ['ok' => true, 'id' => isset($item['id']) ? (string) $item['id'] : null, 'error' => null];
        }

        $message = $json['data']['message'] ?? $item['message'] ?? $json['message'] ?? 'Неизвестный ответ P1SMS';

        return ['ok' => false, 'id' => null, 'error' => (string) $message];
    }
}
