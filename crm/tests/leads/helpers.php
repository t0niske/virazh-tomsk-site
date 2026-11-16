<?php

declare(strict_types=1);

use App\Leads\Clock;
use App\Leads\LeadForm;
use App\Leads\LeadService;
use App\Leads\Settings;
use App\Max\LogMaxApi;
use App\Max\Max;
use App\Sms\Sms;
use App\Sms\SmsGateway;
use App\Support\Db;

/** Мини-набор для тестов без внешних зависимостей. */
final class Tests
{
    /** @var array<string, Closure> */
    public static array $cases = [];

    public static LogMaxApi $max;

    /** @var list<array{phone: string, text: string}> */
    public static array $sms = [];

    public static function reset(): void
    {
        $pdo = Db::pdo();

        foreach (['chat_messages', 'chats', 'visit_events', 'visit_pages', 'visits', 'visitors', 'lead_events', 'max_messages', 'sms_messages', 'leads', 'staff', 'settings', 'audit_log', 'rate_hits'] as $table) {
            $pdo->exec("DELETE FROM $table");
        }

        Settings::reset();
        Clock::set(null);
        App\Chat\Chat::useModel(null, false);
        self::$max = new LogMaxApi();
        Max::useApi(self::$max);
        self::$sms = [];
        Sms::useGateway(new class implements SmsGateway {
            public function send(string $phone, string $text): array
            {
                Tests::$sms[] = ['phone' => $phone, 'text' => $text];

                return ['ok' => true, 'id' => 'test', 'error' => null];
            }
        });
    }

    /** Отправляет заявку как форма сайта. */
    public static function lead(array $fields = []): array
    {
        $result = LeadForm::validate($fields + ['name' => 'Иван', 'phone' => '+7 913 000-00-00', 'consent' => '1']);

        if ($result['errors'] !== []) {
            throw new RuntimeException('Форма не прошла проверку: ' . json_encode($result['errors'], JSON_UNESCAPED_UNICODE));
        }

        return LeadService::accept($result['data'])['lead'];
    }

    /** @return list<array<string, mixed>> вызовы MAX с методом $method */
    public static function maxCalls(string $method = 'send'): array
    {
        return array_values(array_filter(self::$max->calls, static fn (array $c): bool => $c['method'] === $method));
    }
}

function test(string $name, Closure $fn): void
{
    Tests::$cases[$name] = $fn;
}

function eq(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(($message !== '' ? "$message: " : '') . 'ожидалось ' . var_export($expected, true) . ', получено ' . var_export($actual, true));
    }
}

function ok(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
