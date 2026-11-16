<?php

declare(strict_types=1);

namespace App\Leads;

use App\Support\Env;

/** Яндекс SmartCaptcha. Включается ключом SMARTCAPTCHA_SERVER_KEY. */
final class Captcha
{
    public static function enabled(): bool
    {
        return (string) Env::get('SMARTCAPTCHA_SERVER_KEY', '') !== '';
    }

    public static function verify(string $token, string $ip): bool
    {
        if ($token === '') {
            return false;
        }

        $ch = curl_init('https://smartcaptcha.yandexcloud.net/validate');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'secret' => Env::get('SMARTCAPTCHA_SERVER_KEY'),
                'token' => $token,
                'ip' => $ip,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 5,
        ] + Tls::options());
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Если сервис капчи недоступен, заявку не теряем (так советует и Яндекс).
        if ($body === false || $code !== 200) {
            Log::error("SmartCaptcha недоступна (HTTP $code), заявка пропущена без проверки");

            return true;
        }

        return (json_decode((string) $body, true)['status'] ?? '') === 'ok';
    }
}
