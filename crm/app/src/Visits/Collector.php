<?php

declare(strict_types=1);

namespace App\Visits;

use App\Leads\Clock;
use App\Leads\Leads;
use App\Leads\Log;
use App\Leads\RateLimiter;
use App\Support\Db;
use App\Support\Env;

/**
 * POST /api/visit — данные счётчика сайта (docs/visits-api.md): просмотры, действия, ping, уход.
 * Ответ всегда 204, лишнее и чужое молча отбрасываем.
 */
final class Collector
{
    public const MAX_BYTES = 8192;
    public const PER_MINUTE = 120;
    public const BATCH = 50;

    private const ID = '/^[A-Za-z0-9_-]{8,40}$/';
    private const UTM = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'yclid', 'gclid'];
    private const EVENTS = ['tel', 'max', 'vk', 'map', 'route', 'form_view', 'form_start', 'form_error', 'lead', 'scroll', 'a11y', 'out', 'file'];

    /** Разобрать запрос счётчика. Ответ (всегда 204) отдаёт Routes. */
    public static function handle(string $method, string $body, array $server): void
    {
        if ($method !== 'POST' || $body === '' || strlen($body) > self::MAX_BYTES || !self::sameSite($server)) {
            return;
        }

        $ip = substr((string) ($server['REMOTE_ADDR'] ?? ''), 0, 45);

        if (!RateLimiter::hit('visit_ip:' . $ip, self::PER_MINUTE, 60)) {
            return;
        }

        $data = json_decode($body, true);

        if (!is_array($data)) {
            return;
        }

        $messages = array_is_list($data) ? $data : [$data];
        $ua = isset($server['HTTP_USER_AGENT']) ? mb_substr((string) $server['HTTP_USER_AGENT'], 0, 255) : null;

        foreach (array_slice($messages, 0, self::BATCH) as $message) {
            if (!is_array($message)) {
                continue;
            }

            try {
                self::message($message, $ip, $ua);
            } catch (\Throwable $e) {
                Log::error('Ошибка приёма данных счётчика', $e);
            }
        }
    }

    /** Запрос со страниц нашего сайта: Origin, а без него Referer — того же хоста. */
    public static function sameSite(array $server): bool
    {
        $from = (string) ($server['HTTP_ORIGIN'] ?? $server['HTTP_REFERER'] ?? '');

        if ($from === '' || $from === 'null') {
            return false;
        }

        $host = strtolower((string) parse_url($from, PHP_URL_HOST));
        $port = parse_url($from, PHP_URL_PORT);
        $origin = $host . ($port ? ':' . $port : '');
        $allowed = [strtolower((string) ($server['HTTP_HOST'] ?? ''))];
        $app = (string) Env::get('APP_URL', '');

        if ($app !== '') {
            $appPort = parse_url($app, PHP_URL_PORT);
            $allowed[] = strtolower((string) parse_url($app, PHP_URL_HOST)) . ($appPort ? ':' . $appPort : '');
        }

        return $host !== '' && in_array($origin, $allowed, true);
    }

    private static function message(array $m, string $ip, ?string $ua): void
    {
        $vid = (string) ($m['vid'] ?? '');
        $sid = (string) ($m['sid'] ?? '');
        $pid = (string) ($m['pid'] ?? '');

        if (!preg_match(self::ID, $vid) || !preg_match(self::ID, $sid)) {
            return;
        }

        $type = (string) ($m['t'] ?? '');
        $visit = Visits::findBySid($sid);

        if ($visit !== null && $visit['vid'] !== $vid) {
            return;
        }

        if ($type === 'view') {
            if (!preg_match(self::ID, $pid)) {
                return;
            }

            $visit ??= self::createVisit($m, $vid, $sid, $ip, $ua);
            self::addPage((int) $visit['id'], $pid, $m);
        } elseif ($visit === null) {
            return;
        } elseif ($type === 'ping') {
            Visits::update((int) $visit['id'], ['last_ping_at' => Clock::sql(), 'last_at' => Clock::sql()]);
        } elseif ($type === 'leave') {
            self::leave($visit, $pid, $m);
        } elseif ($type === 'event') {
            self::event($visit, $pid, $m);
        } else {
            return;
        }

        Visits::reclassify((int) $visit['id'], false);
    }

    private static function createVisit(array $m, string $vid, string $sid, string $ip, ?string $ua): array
    {
        $now = Clock::sql();
        $pdo = Db::pdo();
        $visitor = $pdo->prepare('SELECT * FROM visitors WHERE id = ?');
        $visitor->execute([$vid]);
        $row = $visitor->fetch();

        if ($row) {
            $number = (int) $row['visits_count'] + 1;
            $pdo->prepare('UPDATE visitors SET visits_count = ?, last_seen = ? WHERE id = ?')->execute([$number, $now, $vid]);
        } else {
            $number = 1;
            $pdo->prepare('INSERT INTO visitors (id, first_seen, last_seen, visits_count) VALUES (?, ?, ?, 1)')->execute([$vid, $now, $now]);
        }

        $utm = [];
        foreach (self::UTM as $key) {
            $value = self::str($m['utm'][$key] ?? null, 200);
            if ($value !== null) {
                $utm[$key] = $value;
            }
        }

        $ref = self::str($m['ref'] ?? null, 300);
        $screen = self::str($m['screen'] ?? null, 20);
        $device = Detect::device($ua, (bool) ($m['touch'] ?? false), $screen);

        $id = Visits::insert([
            'sid' => $sid,
            'vid' => $vid,
            'started_at' => $now,
            'last_at' => $now,
            'number' => $number,
            'source' => Detect::source($utm, (string) $ref),
            'referrer' => $ref,
            'utm' => $utm === [] ? null : json_encode($utm, JSON_UNESCAPED_UNICODE),
            'landing' => self::str($m['path'] ?? null, 300) ?? '/',
            'ip' => $ip !== '' ? $ip : null,
            'user_agent' => $ua,
            'device' => $device['device'],
            'browser' => $device['browser'],
            'os' => $device['os'],
            'screen' => $screen,
            'lang' => self::str($m['lang'] ?? null, 20),
            'tz' => self::str($m['tz'] ?? null, 50),
            'touch' => ($m['touch'] ?? false) === true ? 1 : 0,
            'webdriver' => ($m['wd'] ?? false) === true ? 1 : 0,
            'ym' => self::str($m['ym'] ?? null, 30),
        ]);

        $visit = Visits::find($id);
        Visits::notifyReturning($visit);

        return $visit;
    }

    private static function addPage(int $visitId, string $pid, array $m): void
    {
        if (Visits::page($visitId, $pid) !== null) {
            return;
        }

        Db::pdo()->prepare('INSERT INTO visit_pages (visit_id, pid, path, title, opened_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$visitId, $pid, self::str($m['path'] ?? null, 300) ?? '/', self::str($m['title'] ?? null, 200), Clock::sql()]);

        $count = Db::pdo()->prepare('SELECT COUNT(*) FROM visit_pages WHERE visit_id = ?');
        $count->execute([$visitId]);
        Visits::update($visitId, ['pages' => (int) $count->fetchColumn(), 'last_at' => Clock::sql()]);
    }

    private static function leave(array $visit, string $pid, array $m): void
    {
        $page = Visits::page((int) $visit['id'], $pid);

        if ($page !== null) {
            $active = max((int) $page['active_seconds'], min(86400, max(0, (int) ($m['active'] ?? 0))));
            $scroll = max((int) $page['scroll'], min(100, max(0, (int) ($m['scroll'] ?? 0))));
            Db::pdo()->prepare('UPDATE visit_pages SET active_seconds = ?, scroll = ? WHERE id = ?')->execute([$active, $scroll, $page['id']]);
        }

        $sum = Db::pdo()->prepare('SELECT COALESCE(SUM(active_seconds), 0) FROM visit_pages WHERE visit_id = ?');
        $sum->execute([$visit['id']]);
        Visits::update((int) $visit['id'], ['has_leave' => 1, 'active_seconds' => (int) $sum->fetchColumn(), 'last_at' => Clock::sql()]);
    }

    private static function event(array $visit, string $pid, array $m): void
    {
        $name = (string) ($m['name'] ?? '');

        if (!in_array($name, self::EVENTS, true)) {
            return;
        }

        $data = self::str($m['data'] ?? null, 200);
        $page = Visits::page((int) $visit['id'], $pid);

        Db::pdo()->prepare('INSERT INTO visit_events (visit_id, page_id, created_at, name, data) VALUES (?, ?, ?, ?, ?)')
            ->execute([$visit['id'], $page['id'] ?? null, Clock::sql(), $name, $data]);

        $fields = ['events_count' => (int) $visit['events_count'] + 1, 'last_at' => Clock::sql()];

        if ($name === 'tel') {
            $fields['has_tel'] = 1;
        }

        Visits::update((int) $visit['id'], $fields);

        if ($name === 'lead' && ctype_digit((string) $data) && Leads::find((int) $data) !== null) {
            Visits::linkLead((int) $data, $visit['vid'], $visit['sid']);
        }
    }

    private static function str(mixed $value, int $max): ?string
    {
        if (!is_scalar($value) || is_bool($value)) {
            return null;
        }

        $value = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $value) ?? '');

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
