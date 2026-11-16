<?php

declare(strict_types=1);

namespace App\Chat;

use App\Leads\Tls;
use App\Support\Env;

/**
 * DeepSeek API (OpenAI-совместимый): POST https://api.deepseek.com/chat/completions.
 * В тестах вместо HTTP подставляется заглушка через Chat::useModel().
 */
class DeepSeek
{
    public const URL = 'https://api.deepseek.com/chat/completions';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model = 'deepseek-flash',
    ) {
    }

    public static function fromEnv(): ?self
    {
        $key = trim((string) Env::get('DEEPSEEK_API_KEY', ''));

        return $key === '' ? null : new self($key, trim((string) Env::get('DEEPSEEK_MODEL', '')) ?: 'deepseek-flash');
    }

    /** Тело запроса — по docs/chat-api.md. */
    public function payload(array $messages): array
    {
        return [
            'model' => $this->model,
            'thinking' => ['type' => 'disabled'],
            'messages' => $messages,
            'max_tokens' => 400,
            'temperature' => 0.3,
            'stream' => false,
        ];
    }

    /**
     * @param list<array{role: string, content: string}> $messages
     * @return array{ok: bool, text: string, status: int, error: ?string}
     */
    public function complete(array $messages): array
    {
        $ch = curl_init(self::URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($this->payload($messages), JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $this->apiKey],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
        ] + Tls::options((string) Env::get('DEEPSEEK_CA_FILE', '')));
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return ['ok' => false, 'text' => '', 'status' => 0, 'error' => 'нет связи или таймаут: ' . $error];
        }

        return self::parse($status, (string) $body);
    }

    /** @return array{ok: bool, text: string, status: int, error: ?string} */
    public static function parse(int $status, string $body): array
    {
        $json = json_decode($body, true);
        $text = is_array($json) ? trim((string) ($json['choices'][0]['message']['content'] ?? '')) : '';

        if ($status === 200 && $text !== '') {
            return ['ok' => true, 'text' => $text, 'status' => 200, 'error' => null];
        }

        $error = is_array($json) ? (string) ($json['error']['message'] ?? 'пустой ответ') : mb_substr($body, 0, 200);

        return ['ok' => false, 'text' => '', 'status' => $status, 'error' => $error];
    }
}
