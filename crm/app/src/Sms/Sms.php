<?php

declare(strict_types=1);

namespace App\Sms;

use App\Leads\Clock;
use App\Leads\Log;
use App\Leads\Notify\Telegram;
use App\Leads\Settings;
use App\Support\Db;
use App\Support\Env;

/**
 * Отправка SMS с записью в sms_messages. Драйверы: p1sms (основной), smsru, log.
 * Реальный провайдер включается только при APP_ENV=production, иначе SMS пишутся в лог.
 */
final class Sms
{
    /** Одна SMS на русском — 70 знаков; длиннее — части по 67, каждая оплачивается отдельно. */
    public const MAX_LENGTH = 70;

    /** Наши тексты укладываются в две части. */
    public const TWO_PARTS = 134;

    private static ?SmsGateway $gateway = null;

    /** SMS подключены? SMS_DRIVER=off (по умолчанию) — нет: заявки и админка работают без SMS. */
    public static function enabled(): bool
    {
        return (Env::get('SMS_DRIVER') ?: 'off') !== 'off';
    }

    public static function gateway(): SmsGateway
    {
        if (self::$gateway !== null) {
            return self::$gateway;
        }

        $driver = Env::get('SMS_DRIVER', 'log');

        if ($driver !== 'log' && Env::get('APP_ENV') !== 'production') {
            Log::write('sms.log', "SMS_DRIVER=$driver отключён: APP_ENV не production, пишем в лог.");
            $driver = 'log';
        }

        return self::$gateway = match ($driver) {
            'p1sms' => new P1smsGateway((string) Env::get('SMS_API_KEY', ''), (string) (Env::get('SMS_SENDER') ?: 'INFO'), Env::get('SMS_CHANNEL') === 'digit' ? 'digit' : 'char'),
            'smsru' => new SmsRuGateway((string) Env::get('SMS_API_KEY', ''), (string) Env::get('SMS_SENDER', ''), Env::bool('SMS_TEST')),
            default => new LogGateway(),
        };
    }

    public static function useGateway(?SmsGateway $gateway): void
    {
        self::$gateway = $gateway;
    }

    /** @return array{ok: bool, id: ?string, error: ?string} */
    public static function send(string $phone, string $text, string $kind, ?int $leadId = null): array
    {
        if (mb_strlen($text) > self::TWO_PARTS) {
            Log::write('sms.log', "Внимание: текст «{$kind}» длиннее " . self::TWO_PARTS . ' знаков, уйдёт тремя SMS и больше.');
        }

        if (self::dailyLimitReached()) {
            $result = ['ok' => false, 'id' => null, 'error' => 'Превышен лимит SMS в сутки (' . Settings::int('sms_daily_limit') . ')'];
            self::warnAboutLimit();
        } else {
            $result = self::attempt($phone, $text);
        }

        Db::pdo()->prepare(
            'INSERT INTO sms_messages (created_at, lead_id, phone, kind, text, status, provider_id, error) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            Clock::sql(), $leadId, $phone, $kind, mb_substr($text, 0, 500),
            $result['ok'] ? 'sent' : 'failed', $result['id'], $result['error'] ? mb_substr($result['error'], 0, 255) : null,
        ]);

        return $result;
    }

    /**
     * Общий предохранитель: не больше sms_daily_limit SMS за сутки (с полуночи по Томску).
     * Защищает баланс, если бот начнёт засыпать форму случайными номерами с разных IP.
     */
    public static function dailyLimitReached(): bool
    {
        $limit = Settings::int('sms_daily_limit');

        if ($limit <= 0) {
            return false;
        }

        $stmt = Db::pdo()->prepare("SELECT COUNT(*) FROM sms_messages WHERE status = 'sent' AND created_at >= ?");
        $stmt->execute([Clock::sql(Clock::now()->setTime(0, 0))]);

        return (int) $stmt->fetchColumn() >= $limit;
    }

    /** Раз в сутки сообщаем администраторам, что SMS остановлены, — в Telegram (без персональных данных) и в журнал. */
    private static function warnAboutLimit(): void
    {
        $today = Clock::now()->format('Y-m-d');

        if (Settings::get('cron.sms_limit_notice') === $today) {
            return;
        }

        Settings::set('cron.sms_limit_notice', $today);
        $text = '⚠️ Сегодня отправлено уже ' . Settings::int('sms_daily_limit') . ' SMS — это лимит в сутки. '
            . 'Новые SMS клиентам до полуночи не уходят, заявки принимаются как обычно. '
            . 'Если это не наплыв настоящих заявок, проверьте список в /crm. Лимит меняется в настройках админки.';
        Log::error($text);

        try {
            Telegram::send($text);
        } catch (\Throwable $e) {
            Log::error('Telegram о лимите SMS', $e);
        }
    }

    /** @return array{ok: bool, id: ?string, error: ?string} */
    private static function attempt(string $phone, string $text): array
    {
        try {
            return self::gateway()->send($phone, $text);
        } catch (\Throwable $e) {
            Log::error('SMS не отправлено', $e);

            return ['ok' => false, 'id' => null, 'error' => $e->getMessage()];
        }
    }

    /** Отправляли ли этому номеру SMS одного из видов $kinds за последние сутки. */
    public static function sentRecently(string $phone, array $kinds, int $hours = 24): bool
    {
        $placeholders = implode(',', array_fill(0, count($kinds), '?'));
        $stmt = Db::pdo()->prepare(
            "SELECT COUNT(*) FROM sms_messages WHERE phone = ? AND status = 'sent' AND kind IN ($placeholders) AND created_at >= ?"
        );
        $stmt->execute([$phone, ...$kinds, Clock::sql(Clock::now()->modify("-$hours hours"))]);

        return (int) $stmt->fetchColumn() > 0;
    }
}
