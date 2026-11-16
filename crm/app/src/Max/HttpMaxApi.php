<?php

declare(strict_types=1);

namespace App\Max;

use App\Leads\Log;
use App\Leads\Tls;

/**
 * Настоящие запросы к https://platform-api2.max.ru. Токен — в заголовке Authorization.
 * Если хостинг не доверяет сертификату Минцифры, путь к нему задаётся в MAX_CA_FILE.
 */
final class HttpMaxApi implements MaxApi
{
    public function __construct(
        private readonly string $token,
        private readonly string $baseUrl = 'https://platform-api2.max.ru',
        private readonly string $caFile = '',
    ) {
    }

    public function send(array $to, string $text, ?array $keyboard = null, bool $notify = true): ?string
    {
        $body = ['text' => $text, 'notify' => $notify];

        if ($keyboard !== null) {
            $body['attachments'] = [Keyboard::attachment($keyboard)];
        }

        $response = $this->request('POST', '/messages', $to + ['disable_link_preview' => 'true'], $body);

        return $response['message']['body']['mid'] ?? null;
    }

    public function edit(string $mid, string $text, ?array $keyboard = null): bool
    {
        $body = ['text' => $text, 'attachments' => $keyboard === null ? [] : [Keyboard::attachment($keyboard)]];

        return ($this->request('PUT', '/messages', ['message_id' => $mid], $body)['success'] ?? false) === true;
    }

    public function answer(string $callbackId, ?string $notification = null, ?array $message = null): bool
    {
        $body = [];

        if ($notification !== null) {
            $body['notification'] = $notification;
        }

        if ($message !== null) {
            $body['message'] = [
                'text' => $message['text'],
                'attachments' => isset($message['keyboard']) ? [Keyboard::attachment($message['keyboard'])] : [],
            ];
        }

        return ($this->request('POST', '/answers', ['callback_id' => $callbackId], $body)['success'] ?? false) === true;
    }

    /** Подписка на вебхук: MAX будет присылать события на $url с секретом в заголовке. */
    public function subscribe(string $url, string $secret): ?array
    {
        return $this->request('POST', '/subscriptions', [], [
            'url' => $url,
            'secret' => $secret,
            'update_types' => ['bot_started', 'bot_added', 'message_created', 'message_callback'],
        ]);
    }

    public function me(): ?array
    {
        return $this->request('GET', '/me', [], []);
    }

    private function request(string $method, string $path, array $query, array $body): ?array
    {
        $ch = curl_init(rtrim($this->baseUrl, '/') . $path . '?' . http_build_query($query));
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => ['Authorization: ' . $this->token, 'Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
        ];

        if ($method !== 'GET') {
            $options[CURLOPT_POSTFIELDS] = json_encode($body === [] ? new \stdClass() : $body, JSON_UNESCAPED_UNICODE);
        }

        $options += Tls::options($this->caFile);

        curl_setopt_array($ch, $options);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $code >= 400) {
            Log::write('max.log', "Ошибка $method $path: HTTP $code " . ($raw === false ? $error : mb_substr((string) $raw, 0, 500)));

            return null;
        }

        return json_decode((string) $raw, true) ?: [];
    }
}
