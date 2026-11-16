<?php

declare(strict_types=1);

use App\Leads\LeadForm;
use App\Leads\Phone;

test('Телефон: разные записи приводятся к +7XXXXXXXXXX', function (): void {
    foreach ([
        '+7 (913) 123-45-67',
        '8 913 123 45 67',
        '89131234567',
        '79131234567',
        '9131234567',
        '+7 8 913 123-45-67',
        ' +7-913-123-45-67 ',
    ] as $input) {
        eq('+79131234567', Phone::normalize($input), $input);
    }
});

test('Телефон: иностранные и мусор не принимаются', function (): void {
    foreach (['', '12345', '+380501234567', '+1 913 123 4567', '9131234', 'abc', '+7 913 123 45 678', '+7 (113) 123-45-67'] as $input) {
        eq(null, Phone::normalize($input), "«{$input}»");
    }
});

test('Телефон: городские номера принимаются, SMS — только на мобильные', function (): void {
    eq('+73822123456', Phone::normalize('+7 (3822) 12-34-56'));
    eq('+73822123456', Phone::normalize('83822123456'));
    eq('+79131234567', Phone::normalize('8 913 123-45-67'));
    ok(Phone::isMobile('+79131234567'), 'мобильный');
    ok(!Phone::isMobile('+73822123456'), 'городской — не мобильный');
});

test('Телефон: вид для людей и для SMS', function (): void {
    eq('+7 (983) 234-56-56', Phone::format('+79832345656'));
    eq('+7 (3822) 34-52-52', Phone::format('+73822345252'), 'томский код из четырёх цифр');
    eq('+7 (383) 205-24-34', Phone::format('+73832052434'), 'новосибирский — из трёх');
    eq('+79832345656', Phone::compact('8 (983) 234-56-56'));
});

test('Форма: обязательные поля и понятные ошибки', function (): void {
    $result = LeadForm::validate(['name' => ' ', 'phone' => '123']);
    eq(['phone', 'consent'], array_keys($result['errors']));
    eq('Проверьте номер: нужно 10 цифр после +7.', $result['errors']['phone']);
    eq('Проверьте данные в форме.', LeadForm::errorMessage($result['errors']));
    eq('Проверьте номер телефона.', LeadForm::errorMessage(['phone' => 'x']));
});

test('Форма: очистка полей и значения по умолчанию', function (): void {
    $result = LeadForm::validate([
        'name' => '  <b>Анна</b>   Петрова ',
        'phone' => '8-913-000-00-00',
        'consent' => 'on',
        'contact_time' => 'полночь',
        'channel' => 'telegram',
        'page_url' => 'javascript:alert(1)',
        'yclid' => '123<script>',
    ]);
    eq([], $result['errors']);
    eq('Анна Петрова', $result['data']['name']);
    eq('+79130000000', $result['data']['phone']);
    eq('now', $result['data']['contact_time']);
    eq('call', $result['data']['channel']);
    eq(null, $result['data']['page_url']);
    eq('123script', $result['data']['yclid']);
});
