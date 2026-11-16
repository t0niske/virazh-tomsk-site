<?php

declare(strict_types=1);

namespace App\Max;

use App\Leads\Log;
use App\Leads\Settings;
use App\Support\Env;

/**
 * Точка доступа к боту MAX: выбор драйвера и ссылки для клиентов.
 * Реальный драйвер включается только при APP_ENV=production и MAX_DRIVER=http.
 */
final class Max
{
    private static ?MaxApi $api = null;

    public static function api(): MaxApi
    {
        if (self::$api !== null) {
            return self::$api;
        }

        $driver = Env::get('MAX_DRIVER', 'log');

        if ($driver !== 'log' && Env::get('APP_ENV') !== 'production') {
            Log::write('max.log', "MAX_DRIVER=$driver отключён: APP_ENV не production, пишем в лог.");
            $driver = 'log';
        }

        return self::$api = $driver === 'http'
            ? new HttpMaxApi((string) Env::get('MAX_BOT_TOKEN', ''), (string) Env::get('MAX_API_URL', 'https://platform-api2.max.ru'), (string) Env::get('MAX_CA_FILE', ''))
            : new LogMaxApi();
    }

    public static function useApi(?MaxApi $api): void
    {
        self::$api = $api;
    }

    /** Бот настроен: известно его имя, значит, можно давать клиентам ссылку. */
    public static function enabled(): bool
    {
        return self::botName() !== '';
    }

    public static function botName(): string
    {
        return trim((string) Env::get('MAX_BOT_NAME', ''), " @\t");
    }

    public static function adminChatId(): string
    {
        return trim(Settings::get('max_admin_chat_id'));
    }

    /** Ссылка, открывающая бота со стартовым параметром (до 128 символов). */
    public static function link(?string $payload = null): string
    {
        return 'https://max.ru/' . self::botName() . ($payload !== null ? '?start=' . rawurlencode($payload) : '');
    }

    /** Короткая ссылка для SMS: max.ru/имя_бота */
    public static function shortLink(): string
    {
        return 'max.ru/' . self::botName();
    }

    /** Стартовый параметр заявки: L148_подпись. Подпись не даёт подобрать чужую заявку. */
    public static function leadPayload(int $leadId): string
    {
        return 'L' . $leadId . '_' . self::sign('L' . $leadId);
    }

    /** Стартовый параметр для привязки сотрудника: S5_подпись. */
    public static function staffPayload(int $staffId): string
    {
        return 'S' . $staffId . '_' . self::sign('S' . $staffId);
    }

    /** @return array{type: string, id: int}|null */
    public static function parsePayload(?string $payload): ?array
    {
        if ($payload === null || !preg_match('/^([LS])(\d+)_([a-f0-9]{10})$/', $payload, $m)) {
            return null;
        }

        if (!hash_equals(self::sign($m[1] . $m[2]), $m[3])) {
            return null;
        }

        return ['type' => $m[1] === 'L' ? 'lead' : 'staff', 'id' => (int) $m[2]];
    }

    private static function sign(string $value): string
    {
        $key = (string) Env::get('LEADS_SECRET', '');

        return substr(hash_hmac('sha256', $value, $key !== '' ? $key : 'virazh-dev-key'), 0, 10);
    }
}
