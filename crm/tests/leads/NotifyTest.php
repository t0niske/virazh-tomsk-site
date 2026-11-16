<?php

declare(strict_types=1);

use App\Leads\Clock;
use App\Leads\Notify\LeadMail;
use App\Leads\Notify\Notifier;
use App\Leads\Notify\Telegram;

test('Письмо о заявке: тот же формат, что у send-form.php старого сайта', function (): void {
    Clock::set('2026-11-13 14:05:09');
    $lead = Tests::lead([
        'name' => 'Анна', 'phone' => '8 913 111 22 33', 'service' => 'Категория B (АКПП)',
        'page_url' => 'https://sto-dorog-tomsk.ru/price?utm_source=yandex',
    ]);

    eq("ID: {$lead['id']}. Новая заявка с сайта Вираж", LeadMail::subject($lead, false));
    eq(
        "НОВАЯ ЗАЯВКА №{$lead['id']}\n"
        . "========================================\n"
        . "\n"
        . "Имя: Анна\n"
        . "Телефон: +7 (913) 111-22-33\n"
        . "Что интересует: Категория B (АКПП)\n"
        . "Страница: https://sto-dorog-tomsk.ru/price?utm_source=yandex\n"
        . "\n"
        . "Дата: 13.11.2026 14:05:09\n"
        . "IP: 5.5.5.5\n"
        . "\n"
        . "Карточка заявки: https://virazh-tomsk.ru/crm/lead/{$lead['id']}",
        LeadMail::body($lead, false, '5.5.5.5'),
    );

    $headers = LeadMail::headers('school@example.ru');
    eq("MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nFrom: school@example.ru\r\nReply-To: school@example.ru\r\nX-Mailer: PHP/" . phpversion(), $headers);
});

test('Письмо о заявке: пустые поля и повторная заявка', function (): void {
    Clock::set('2026-11-13 10:00:00');
    $lead = Tests::lead(['name' => '']);
    $body = LeadMail::body($lead, false, null);
    ok(str_contains($body, "Имя: Без имени\n"), $body);
    ok(str_contains($body, "Что интересует: не указано\nСтраница: Не определена\n"), $body);
    ok(str_contains($body, "IP: не определён

Карточка заявки: https://virazh-tomsk.ru/crm/lead/{$lead['id']}"), $body);

    Clock::set('2026-11-14 11:30:00');
    $lead = Tests::lead(['name' => '', 'service' => 'Категория A', 'page_url' => 'https://sto-dorog-tomsk.ru/a']);
    eq("ID: {$lead['id']}. Повторная заявка с сайта Вираж", LeadMail::subject($lead, true));

    // Как у старого скрипта: страница и услуга — этой отправки, а не первой заявки.
    $request = App\Leads\LeadForm::validate(['phone' => '+7 913 000-00-00', 'consent' => '1'])['data'];
    $body = LeadMail::body($lead, true, '6.6.6.6', $request);
    ok(str_contains($body, "Что интересует: не указано\nСтраница: Не определена\n"), $body);
    $body = LeadMail::body($lead, true, '6.6.6.6');
    ok(str_starts_with($body, "ПОВТОРНАЯ ЗАЯВКА №{$lead['id']}\n"), $body);
    ok(str_contains($body, "Дата: 14.11.2026 11:30:00\nIP: 6.6.6.6\n\nОбращений с этого номера: 2, первое — 13.11.2026 10:00"), $body);
});

test('Telegram: без имени, телефона и IP, с номером и ссылкой в админку', function (): void {
    Clock::set('2026-11-13 14:05:00');
    $lead = Tests::lead([
        'name' => 'Анна Петрова', 'phone' => '+7 913 111-22-33', 'service' => 'Категория B (АКПП)',
        'page_url' => 'https://sto-dorog-tomsk.ru/price?phone=79131112233&name=Anna', 'utm_source' => 'yandex',
    ]);
    $text = Telegram::text($lead, false);

    eq("🔔 Новая заявка №{$lead['id']} · 14:05\nКатегория B (АКПП)\nСтраница: /price\nОткуда: Яндекс Директ\nУдобно: сейчас · звонок\n"
        . "Открыть: https://virazh-tomsk.ru/crm/lead/{$lead['id']}", $text);

    foreach (['Анна', 'Петрова', '913', '111', '79131112233', 'Anna'] as $private) {
        ok(!str_contains($text, $private), "В Telegram попало «{$private}»");
    }

    $repeat = Telegram::text($lead, true);
    eq("🔁 Повторная заявка №{$lead['id']} · Новая\nОткрыть: https://virazh-tomsk.ru/crm/lead/{$lead['id']}", $repeat);
});

test('Оповещения: заявка с сайта пишет письмо и Telegram, сбой канала не ломает приём', function (): void {
    $dir = getenv('LEADS_LOG_DIR');
    @unlink("$dir/mail.log");
    @unlink("$dir/telegram.log");

    Clock::set('2026-11-13 12:00:00');
    $lead = Tests::lead(['name' => 'Ирина', 'phone' => '+79134445566']);

    $mail = (string) @file_get_contents("$dir/mail.log");
    $telegram = (string) @file_get_contents("$dir/telegram.log");
    ok(str_contains($mail, "Тема: ID: {$lead['id']}. Новая заявка с сайта Вираж") && str_contains($mail, 'Телефон: +7 (913) 444-55-66'), $mail);
    ok(str_contains($telegram, "Новая заявка №{$lead['id']}") && !str_contains($telegram, '444') && !str_contains($telegram, 'Ирина'), $telegram);

    // Битая заявка: оба канала падают внутри, но наружу ошибка не выходит.
    Notifier::leadAccepted(['id' => 1, 'created_at' => 'не дата', 'last_request_at' => 'не дата', 'status' => 'new'], true);
    ok(str_contains((string) file_get_contents("$dir/errors.log"), 'Письмо о заявке №1'), 'ошибка записана в журнал');
});

test('Номера заявок продолжают счётчик старого сайта', function (): void {
    putenv('LEADS_FIRST_ID=91');

    try {
        Clock::set('2026-11-13 12:00:00');
        eq(91, (int) Tests::lead(['phone' => '+79130000091'])['id']);
        eq(92, (int) Tests::lead(['phone' => '+79130000092'])['id']);
    } finally {
        putenv('LEADS_FIRST_ID=1');
    }
});
