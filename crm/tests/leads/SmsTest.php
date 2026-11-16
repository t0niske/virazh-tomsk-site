<?php

declare(strict_types=1);

use App\Sms\P1smsGateway;

test('P1SMS: запрос с общим именем INFO и разбор ответа', function (): void {
    $gateway = new P1smsGateway('key123', 'INFO', 'char');
    eq([
        'apiKey' => 'key123',
        'sms' => [['channel' => 'char', 'phone' => '79131234567', 'text' => 'Сто дорог: тест', 'sender' => 'INFO']],
    ], $gateway->payload('+79131234567', 'Сто дорог: тест'));

    $digit = (new P1smsGateway('key123', 'INFO', 'digit'))->payload('+79131234567', 'тест');
    ok(!isset($digit['sms'][0]['sender']), 'цифровому каналу имя не нужно');

    eq(['ok' => true, 'id' => '370506708', 'error' => null],
        P1smsGateway::parse(200, '{"status":"success","data":[{"message":"тест","id":370506708,"status":"sent","phone":"79131234567"}]}'));

    $error = P1smsGateway::parse(400, '{"status":"error","data":{"message":"Неверный API-ключ","exception":"UserNotFoundException","debugInfo":""}}');
    eq(false, $error['ok']);
    eq('Неверный API-ключ', $error['error']);

    eq(false, P1smsGateway::parse(429, 'Too Many Attempts.')['ok']);
});

test('SMS: общий лимит в сутки останавливает отправку и предупреждает один раз', function (): void {
    App\Leads\Settings::set('sms_daily_limit', '2');
    $dir = getenv('LEADS_LOG_DIR');
    @unlink("$dir/telegram.log");

    App\Leads\Clock::set('2026-11-13 12:00:00');
    Tests::lead(['phone' => '+79130000001']);
    Tests::lead(['phone' => '+79130000002']);
    Tests::lead(['phone' => '+79130000003']);
    Tests::lead(['phone' => '+79130000004']);

    eq(2, count(Tests::$sms), 'ушло только две SMS');
    $failed = App\Support\Db::pdo()->query("SELECT COUNT(*) FROM sms_messages WHERE status = 'failed' AND error LIKE 'Превышен лимит%'")->fetchColumn();
    eq(2, (int) $failed, 'остальные записаны как неотправленные');
    eq(1, substr_count((string) file_get_contents("$dir/telegram.log"), 'лимит в сутки'), 'предупреждение одно');

    App\Leads\Clock::set('2026-11-14 09:00:00');
    Tests::lead(['phone' => '+79130000005']);
    eq(3, count(Tests::$sms), 'на следующий день снова отправляем');
});

test('Вираж: SMS_DRIVER=off — заявка принимается, SMS не уходит и в истории не пишется', function (): void {
    putenv('SMS_DRIVER=off');
    try {
        ok(!App\Sms\Sms::enabled(), 'SMS выключены');
        $before = count(Tests::$sms);
        $lead = Tests::lead(['phone' => '+7 913 777-11-22']);
        eq($before, count(Tests::$sms), 'SMS не отправлялись');
        $stmt = App\Support\Db::pdo()->prepare("SELECT COUNT(*) FROM lead_events WHERE lead_id = ? AND type IN ('sms', 'sms_failed')");
        $stmt->execute([(int) $lead['id']]);
        eq(0, (int) $stmt->fetchColumn(), 'в истории нет записей об SMS');
    } finally {
        putenv('SMS_DRIVER=log');
    }
});

test('Вираж: заявка с городского номера принимается, SMS на него не уходит', function (): void {
    $before = count(Tests::$sms);
    $lead = Tests::lead(['phone' => '8 (3822) 34-52-99']);
    eq('+73822345299', $lead['phone']);
    eq($before, count(Tests::$sms), 'на городской SMS не отправляется');
});
