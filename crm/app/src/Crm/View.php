<?php

declare(strict_types=1);

namespace App\Crm;

use App\Leads\Clock;
use App\Leads\Phone;
use App\Leads\Status;
use DateTimeImmutable;

/** Шаблоны templates/crm/*.php и мелочи для них: экранирование, даты, телефоны, статусы. */
final class View
{
    private const DAYS = [1 => 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];

    public static function render(string $template, array $vars = [], int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; frame-ancestors 'none'; form-action 'self'");

        $vars += ['title' => 'Заявки', 'active' => '', 'user' => Auth::user(), 'flash' => self::takeFlash()];
        $content = self::partial($template, $vars);
        echo self::partial(($vars['bare'] ?? false) || $vars['user'] === null ? 'bare' : 'layout', $vars + ['content' => $content]);
    }

    public static function partial(string $template, array $vars = []): string
    {
        extract($vars, EXTR_SKIP);
        ob_start();
        require APP_ROOT . '/templates/crm/' . $template . '.php';

        return (string) ob_get_clean();
    }

    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function csrf(): string
    {
        return '<input type="hidden" name="_csrf" value="' . self::e(Auth::csrf()) . '">';
    }

    public static function flash(string $message, string $type = 'ok'): void
    {
        $_SESSION['flash'] = ['text' => $message, 'type' => $type];
    }

    public static function redirect(string $url): void
    {
        header('Location: ' . $url, true, 303);
    }

    /** «сегодня 10:42», «вчера 21:17», «Пн 10:42» (эта неделя), «12.10 14:32», «12.10.2025». */
    public static function date(?string $value, bool $withTime = true): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        $t = new DateTimeImmutable($value);
        $now = Clock::now();
        $time = $withTime ? ' ' . $t->format('H:i') : '';
        $days = (int) $t->setTime(0, 0)->diff($now->setTime(0, 0))->format('%r%a');

        return match (true) {
            $days === 0 => 'сегодня' . $time,
            $days === 1 => 'вчера' . $time,
            $days === -1 => 'завтра' . $time,
            $days > 1 && $days < 7 => self::DAYS[(int) $t->format('N')] . $time,
            $t->format('Y') === $now->format('Y') => $t->format('d.m') . $time,
            default => $t->format('d.m.Y') . $time,
        };
    }

    /** «3 мин», «2 ч 5 мин», «3 дн» с момента $value. */
    public static function ago(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        $minutes = max(0, intdiv(Clock::now()->getTimestamp() - (new DateTimeImmutable($value))->getTimestamp(), 60));

        return match (true) {
            $minutes < 60 => "$minutes мин",
            $minutes < 1440 => intdiv($minutes, 60) . ' ч' . ($minutes % 60 ? ' ' . ($minutes % 60) . ' мин' : ''),
            default => intdiv($minutes, 1440) . ' дн',
        };
    }

    /** «45 с», «3 мин 20 с», «1 ч 5 мин». */
    public static function duration(int $seconds): string
    {
        return match (true) {
            $seconds < 60 => "$seconds с",
            $seconds < 3600 => intdiv($seconds, 60) . ' мин' . ($seconds % 60 ? ' ' . ($seconds % 60) . ' с' : ''),
            default => intdiv($seconds, 3600) . ' ч ' . intdiv($seconds % 3600, 60) . ' мин',
        };
    }

    public static function phone(string $phone): string
    {
        return Phone::format($phone);
    }

    public static function status(array $lead): string
    {
        $status = $lead['status'];
        $label = Status::label($status);
        $detail = match ($status) {
            Status::NEW => $lead['due_at'] <= Clock::sql() ? self::ago($lead['due_at']) : 'на ' . self::date($lead['due_at']),
            Status::NO_ANSWER => 'звонков: ' . $lead['attempt'],
            Status::REFUSED => mb_strtolower((string) $lead['refusal_reason']),
            Status::CONTRACT => (string) ($lead['contract_note'] ?? ''),
            default => '',
        };

        return '<span class="badge badge--' . self::e($status) . '">' . self::e($label) . '</span>'
            . ($detail !== '' ? ' <span class="muted">· ' . self::e($detail) . '</span>' : '');
    }

    public static function money(int $value): string
    {
        return number_format($value, 0, ',', ' ') . ' ₽';
    }

    public static function number(int $value): string
    {
        return number_format($value, 0, ',', ' ');
    }

    /** Склонение: plural(1, 'заход', 'захода', 'заходов') → «заход», 3 → «захода», 11 → «заходов». */
    public static function plural(int $n, string $one, string $few, string $many): string
    {
        $n = abs($n) % 100;

        return match (true) {
            $n >= 11 && $n <= 14 => $many,
            $n % 10 === 1 => $one,
            $n % 10 >= 2 && $n % 10 <= 4 => $few,
            default => $many,
        };
    }

    /** Ссылка на текущую страницу с изменёнными параметрами запроса. */
    public static function query(array $changes): string
    {
        $params = array_filter(array_merge($_GET, $changes), static fn ($v): bool => $v !== null && $v !== '');

        return '?' . http_build_query($params);
    }

    private static function takeFlash(): ?array
    {
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        return $flash;
    }
}
