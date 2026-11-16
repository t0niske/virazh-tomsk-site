<?php

declare(strict_types=1);

namespace App\Leads\Http;

use App\Leads\Captcha;
use App\Leads\LeadForm;
use App\Leads\LeadService;
use App\Leads\Log;
use App\Leads\Phone;
use App\Leads\RateLimiter;
use App\Leads\Settings;
use App\Leads\Schedule;
use App\Leads\Clock;
use App\Leads\Texts;
use App\Max\Max;
use App\Support\Env;
use App\Support\FormToken;

/** POST /api/lead и /send-form.php — приём заявок по контракту docs/lead-api.md. */
final class LeadController
{
    private const IP_LIMIT = 10;       // заявок с одного IP
    private const IP_WINDOW = 600;     // за 10 минут
    private const PHONE_LIMIT = 3;     // заявок на один номер
    private const PHONE_WINDOW = 3600; // за час
    private const MIN_FORM_SECONDS = 3; // быстрее человек форму не заполнит

    public static function handle(string $method): void
    {
        self::cors();

        if ($method === 'OPTIONS') {
            http_response_code(204);

            return;
        }

        if ($method !== 'POST') {
            self::json(405, ['success' => false, 'message' => 'Отправьте заявку методом POST.']);

            return;
        }

        try {
            self::submit(self::withVisitCookies(self::input(), $_COOKIE));
        } catch (\Throwable $e) {
            Log::error('Ошибка приёма заявки', $e);
            self::respond(500, [
                'success' => false,
                'message' => 'Не удалось отправить заявку. Позвоните нам: ' . Phone::format(Settings::get('school_phone')) . '.',
            ]);
        }
    }

    private static function submit(array $input): void
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        // Ловушка для ботов: человек это поле не видит. Боту отвечаем «успешно», но ничего не сохраняем.
        if (trim((string) ($input['website'] ?? '')) !== '') {
            Log::write('spam.log', "Ловушка website сработала, IP $ip");
            self::fakeSuccess();

            return;
        }

        $robot = self::robotCheck($input);

        if ($robot['block'] !== null) {
            Log::write('spam.log', $robot['block'] . ", IP $ip");
            self::fakeSuccess();

            return;
        }

        if (!RateLimiter::hit('lead_ip:' . $ip, self::IP_LIMIT, self::IP_WINDOW)) {
            self::tooMany();

            return;
        }

        ['data' => $data, 'errors' => $errors] = LeadForm::validate($input);

        if ($errors === [] && Captcha::enabled() && !Captcha::verify((string) ($input['smart-token'] ?? ''), $ip)) {
            $errors['captcha'] = 'Подтвердите, что вы не робот.';
        }

        if ($errors !== []) {
            self::respond(422, ['success' => false, 'message' => LeadForm::errorMessage($errors), 'errors' => $errors]);

            return;
        }

        if (!RateLimiter::hit('lead_phone:' . $data['phone'], self::PHONE_LIMIT, self::PHONE_WINDOW)) {
            self::tooMany();

            return;
        }

        $data['ip'] = $ip !== '' ? substr($ip, 0, 45) : null;
        $data['user_agent'] = isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 255) : null;
        $data['suspect'] = $robot['suspect'];

        if ($robot['suspect'] !== null) {
            Log::write('spam.log', "Заявка с пометкой «{$robot['suspect']}», IP $ip");
        }

        $result = LeadService::accept($data);
        $lead = $result['lead'];

        $response = [
            'success' => true,
            'lead_id' => (int) $lead['id'],
            'title' => Settings::get('form_title'),
            'message' => Texts::formMessage(Schedule::fromSettings(), $result['call_at'], Clock::now(), $lead['channel'], $lead['contact_time']),
        ];

        if (Max::enabled()) {
            $response['max_link'] = Max::link(Max::leadPayload((int) $lead['id']));
        }

        self::respond(200, $response);
    }

    /**
     * Защита от роботов (form_ts и js из docs/lead-api.md). block — не создавать заявку: форма отправлена быстрее
     * 3 секунд после открытия страницы. suspect — создать, но пометить и не слать SMS: форма ушла без скриптов
     * (js ≠ 1) или без подписанной метки времени.
     *
     * @return array{block: ?string, suspect: ?string}
     */
    public static function robotCheck(array $input, ?int $now = null): array
    {
        $age = FormToken::age((string) ($input['form_ts'] ?? ''), $now);

        if ($age !== null && $age < self::MIN_FORM_SECONDS) {
            return ['block' => "Форма отправлена за $age с после открытия страницы", 'suspect' => null];
        }

        $reasons = [];

        if ((string) ($input['js'] ?? '') !== '1') {
            $reasons[] = 'форма без скриптов';
        }

        if ($age === null) {
            $reasons[] = 'нет метки формы';
        }

        return ['block' => null, 'suspect' => $reasons === [] ? null : implode(', ', $reasons)];
    }

    /** Роботу отвечаем как при успехе, чтобы он не понял, что его отсеяли. */
    private static function fakeSuccess(): void
    {
        self::respond(200, ['success' => true, 'lead_id' => null, 'title' => Settings::get('form_title'), 'message' => 'Спасибо! Мы свяжемся с вами.']);
    }

    /**
     * Связь с заходом на сайт: форма шлёт visitor_id и visit_id сама, а если она ушла без JavaScript —
     * берём их из cookie счётчика sd_vid и sd_sid (docs/visits-api.md).
     */
    public static function withVisitCookies(array $input, array $cookies): array
    {
        foreach (['visitor_id' => 'sd_vid', 'visit_id' => 'sd_sid'] as $field => $cookie) {
            if (trim((string) ($input[$field] ?? '')) === '' && isset($cookies[$cookie]) && is_string($cookies[$cookie])) {
                $input[$field] = $cookies[$cookie];
            }
        }

        return $input;
    }

    /** Поля формы: multipart/urlencoded, на всякий случай и JSON. */
    private static function input(): array
    {
        if ($_POST !== []) {
            return $_POST;
        }

        if (str_contains((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
            $json = json_decode((string) file_get_contents('php://input'), true);

            return is_array($json) ? $json : [];
        }

        return [];
    }

    private static function tooMany(): void
    {
        self::respond(429, [
            'success' => false,
            'message' => 'Слишком много заявок подряд. Попробуйте через несколько минут или позвоните нам: '
                . Phone::format(Settings::get('school_phone')) . '.',
        ]);
    }

    /** Разрешает отправку формы со страниц старого сайта, если он на другом домене (LEADS_ALLOWED_ORIGINS). */
    private static function cors(): void
    {
        $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
        $allowed = array_filter(array_map('trim', explode(',', (string) Env::get('LEADS_ALLOWED_ORIGINS', ''))));

        if ($origin !== '' && in_array(rtrim($origin, '/'), $allowed, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Methods: POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Vary: Origin');
        }
    }

    /**
     * Ответ форме. Формы сайта шлют заявку через fetch и получают JSON (контракт docs/lead-api.md).
     * Если браузер отправил форму сам, без JavaScript, — после успеха ведём на /spasibo,
     * при ошибке показываем короткую страницу с текстом ошибки, а не сырой JSON.
     */
    private static function respond(int $code, array $body): void
    {
        if (!self::wantsHtml()) {
            self::json($code, $body);

            return;
        }

        if ($body['success'] ?? false) {
            header('Location: /spasibo', true, 303);

            return;
        }

        http_response_code($code);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        $e = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        $back = parse_url($referer, PHP_URL_HOST) === ($_SERVER['HTTP_HOST'] ?? null) ? $referer : '/';
        $items = implode('', array_map(static fn ($m): string => '<li>' . $e($m) . '</li>', array_values($body['errors'] ?? [])));

        echo '<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex"><title>Заявка не отправлена — Авто-Вираж</title></head>'
            . '<body style="font-family:system-ui,sans-serif;max-width:560px;margin:48px auto;padding:0 16px;line-height:1.5">'
            . '<h1 style="font-size:24px">Заявка не отправлена</h1><p>' . $e($body['message'] ?? 'Попробуйте ещё раз.') . '</p>'
            . ($items !== '' ? '<ul>' . $items . '</ul>' : '')
            . '<p><a href="' . $e($back) . '">Вернуться к форме</a> или позвоните нам: '
            . $e(Phone::format(Settings::get('school_phone'))) . '</p></body></html>';
    }

    /** Форму отправил сам браузер (без JavaScript): он просит HTML, а fetch — JSON или «что угодно». */
    private static function wantsHtml(): bool
    {
        return str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'text/html')
            && !isset($_SERVER['HTTP_X_REQUESTED_WITH']);
    }

    public static function json(int $code, array $body): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
