<?php

declare(strict_types=1);

namespace App\Visits;

use App\Leads\Clock;
use App\Leads\Leads;
use App\Leads\Log;
use App\Leads\Notify\Telegram;
use App\Leads\Settings;
use App\Support\Db;
use App\Support\Env;
use DateTimeImmutable;

/** Заходы на сайт: чтение и запись, связь с заявками, сводка, очистка. */
final class Visits
{
    public const KINDS = ['human' => 'человек', 'suspect' => 'подозрительно', 'bot' => 'бот'];

    public const EVENT_LABELS = [
        'tel' => 'Нажал на телефон',
        'max' => 'Нажал «Написать в MAX»',
        'vk' => 'Перешёл во ВКонтакте',
        'map' => 'Открыл карту',
        'route' => 'Построил маршрут',
        'form_view' => 'Увидел форму заявки',
        'form_start' => 'Начал заполнять форму',
        'form_error' => 'Ошибка при отправке формы',
        'lead' => 'Оставил заявку',
        'scroll' => 'Дочитал страницу до',
        'a11y' => 'Включил версию для слабовидящих',
        'out' => 'Ушёл на другой сайт',
        'file' => 'Открыл документ',
    ];

    /** Заход считается законченным после 30 минут без данных (как cookie sd_sid). */
    public const IDLE_MINUTES = 30;

    public static function findBySid(string $sid): ?array
    {
        $stmt = Db::pdo()->prepare('SELECT * FROM visits WHERE sid = ?');
        $stmt->execute([$sid]);

        return $stmt->fetch() ?: null;
    }

    public static function find(int $id): ?array
    {
        $stmt = Db::pdo()->prepare('SELECT * FROM visits WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    public static function insert(array $fields): int
    {
        $columns = array_keys($fields);
        Db::pdo()->prepare('INSERT INTO visits (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')')
            ->execute(array_values($fields));

        return (int) Db::pdo()->lastInsertId();
    }

    public static function update(int $id, array $fields): void
    {
        $sets = implode(', ', array_map(static fn (string $c): string => "$c = ?", array_keys($fields)));
        Db::pdo()->prepare("UPDATE visits SET $sets WHERE id = ?")->execute([...array_values($fields), $id]);
    }

    public static function page(int $visitId, string $pid): ?array
    {
        $stmt = Db::pdo()->prepare('SELECT * FROM visit_pages WHERE visit_id = ? AND pid = ?');
        $stmt->execute([$visitId, $pid]);

        return $stmt->fetch() ?: null;
    }

    /** Пересчитать «человек / подозрительно / бот». */
    public static function reclassify(int $id, bool $finished): void
    {
        $visit = self::find($id);

        if ($visit === null) {
            return;
        }

        $stmt = Db::pdo()->prepare('SELECT COUNT(*) FROM visit_pages WHERE visit_id = ? AND opened_at <= ?');
        $stmt->execute([$id, Clock::sql((new DateTimeImmutable($visit['started_at']))->modify('+5 minutes'))]);
        $result = Detect::classify($visit, (int) $stmt->fetchColumn(), $finished);

        $reasons = $result['reasons'] === [] ? null : mb_substr(implode('; ', $result['reasons']), 0, 255);

        if ($visit['kind'] !== $result['kind'] || $visit['reasons'] !== $reasons) {
            self::update($id, ['kind' => $result['kind'], 'reasons' => $reasons]);
        }
    }

    /** Связать заявку с заходом и посетителем (поля формы visitor_id / visit_id или событие lead счётчика). */
    public static function linkLead(int $leadId, ?string $vid, ?string $sid): void
    {
        $lead = Leads::find($leadId);

        if ($lead === null) {
            return;
        }

        if ($sid !== null && $sid !== '') {
            Db::pdo()->prepare('UPDATE visits SET lead_id = ? WHERE sid = ? AND lead_id IS NULL')->execute([$leadId, $sid]);
        }

        $fields = [];

        if ($vid !== null && $vid !== '' && ($lead['visitor_id'] ?? null) === null) {
            $fields['visitor_id'] = $vid;
        }

        if ($sid !== null && $sid !== '' && ($lead['visit_id'] ?? null) === null) {
            $fields['visit_id'] = $sid;
        }

        if ($fields !== []) {
            Leads::update($leadId, $fields);
        }
    }

    /** «Клиент по заявке №… снова на сайте» — в Telegram, если включено в настройках. Без персональных данных. */
    public static function notifyReturning(?array $visit): void
    {
        if ($visit === null || Settings::get('visits_notify_returning') !== '1' || $visit['kind'] === 'bot') {
            return;
        }

        $stmt = Db::pdo()->prepare('SELECT id FROM leads WHERE visitor_id = ? AND created_at < ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$visit['vid'], $visit['started_at']]);
        $leadId = $stmt->fetchColumn();

        if ($leadId === false) {
            return;
        }

        $path = (string) parse_url((string) $visit['landing'], PHP_URL_PATH) ?: '/';
        $link = rtrim((string) Env::get('APP_URL', ''), '/') . '/crm/lead/' . $leadId;

        try {
            Telegram::send("👀 Клиент по заявке №$leadId снова на сайте (заход №{$visit['number']}), смотрит $path\nОткрыть: $link");
        } catch (\Throwable $e) {
            Log::error('Telegram о повторном заходе', $e);
        }
    }

    /** Сейчас на сайте: заходы с ping за последние 2 минуты, без ботов. */
    public static function online(): int
    {
        $stmt = Db::pdo()->prepare("SELECT COUNT(*) FROM visits WHERE last_ping_at >= ? AND kind <> 'bot'");
        $stmt->execute([Clock::sql(Clock::now()->modify('-2 minutes'))]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Список заходов с фильтрами: from/to (DateTimeImmutable|null), source, kind (human|bot|all), lead, tel.
     *
     * @return array{rows: list<array>, total: int}
     */
    public static function search(array $f, int $page, int $perPage = 50): array
    {
        [$where, $params] = self::where($f);
        $pdo = Db::pdo();

        $count = $pdo->prepare("SELECT COUNT(*) FROM visits WHERE $where");
        $count->execute($params);

        $stmt = $pdo->prepare("SELECT * FROM visits WHERE $where ORDER BY started_at DESC, id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage));
        $stmt->execute($params);

        return ['rows' => self::withMessengers($stmt->fetchAll()), 'total' => (int) $count->fetchColumn()];
    }

    /**
     * Отметки «перешёл во ВКонтакте» и «перешёл в MAX» для строк списка: события vk / max счётчика
     * и уход на vk.com, vk.ru, max.ru по обычной ссылке (out). Одним запросом на страницу списка.
     *
     * @param list<array> $rows
     * @return list<array> те же строки с went_vk и went_max (bool)
     */
    public static function withMessengers(array $rows): array
    {
        if ($rows === []) {
            return $rows;
        }

        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $stmt = Db::pdo()->prepare(
            'SELECT visit_id, name, data FROM visit_events WHERE visit_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ")
               AND (name IN ('vk', 'max') OR (name = 'out' AND (data LIKE '%vk.com%' OR data LIKE '%vk.ru%' OR data LIKE '%max.ru%')))"
        );
        $stmt->execute($ids);
        $went = [];

        foreach ($stmt->fetchAll() as $e) {
            $data = strtolower((string) $e['data']);
            $where = $e['name'] === 'out' ? (str_contains($data, 'max.ru') ? 'max' : 'vk') : $e['name'];
            $went[(int) $e['visit_id']][$where] = true;
        }

        foreach ($rows as &$r) {
            $r['went_vk'] = isset($went[(int) $r['id']]['vk']);
            $r['went_max'] = isset($went[(int) $r['id']]['max']);
        }
        unset($r);

        return $rows;
    }

    /**
     * Заходы по дням и группам источников (график на /crm/visits) и сводка по группам за тот же период.
     * Фильтры — те же, что у списка. Дни без заходов тоже есть (нули), чтобы на графике не было дыр.
     *
     * @return array{days: array<string, array<string, int>>, groups: array<string, array{visits: int, leads: int}>,
     *               visits: int, leads: int}
     */
    public static function daily(array $f, DateTimeImmutable $now): array
    {
        [$where, $params] = self::where($f);
        $stmt = Db::pdo()->prepare("SELECT started_at, source, lead_id FROM visits WHERE $where");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $end = ($f['to'] ?? null) instanceof DateTimeImmutable ? $f['to'] : $now->setTime(0, 0)->modify('+1 day');
        $start = ($f['from'] ?? null) instanceof DateTimeImmutable ? $f['from'] : null;

        if ($start === null) {
            $first = $rows === [] ? null : min(array_column($rows, 'started_at'));
            $start = $first === null ? $now : new DateTimeImmutable((string) $first);
        }

        $start = $start->setTime(0, 0);
        // Не больше года столбиков: дальше график нечитаем, а заходы без заявки и так хранятся 3 месяца.
        if ($start < $end->modify('-366 days')) {
            $start = $end->modify('-366 days')->setTime(0, 0);
        }

        $zero = array_fill_keys(array_keys(Detect::GROUPS), 0) + ['total' => 0];
        $days = [];
        for ($d = $start; $d < $end; $d = $d->modify('+1 day')) {
            $days[$d->format('Y-m-d')] = $zero;
        }

        $groups = array_fill_keys(array_keys(Detect::GROUPS), ['visits' => 0, 'leads' => []]);
        $total = 0;
        $leads = [];

        foreach ($rows as $v) {
            $day = substr((string) $v['started_at'], 0, 10);
            if (!isset($days[$day])) {
                continue;
            }

            $group = Detect::group((string) $v['source']);
            $days[$day][$group]++;
            $days[$day]['total']++;
            $groups[$group]['visits']++;
            $total++;

            if ($v['lead_id'] !== null) {
                $groups[$group]['leads'][(int) $v['lead_id']] = true;
                $leads[(int) $v['lead_id']] = true;
            }
        }

        foreach ($groups as &$g) {
            $g['leads'] = count($g['leads']);
        }
        unset($g);

        return ['days' => $days, 'groups' => $groups, 'visits' => $total, 'leads' => count($leads)];
    }

    /**
     * Заново определить источник у сохранённых заходов (после изменения правил в Detect::source).
     * Метки и адрес, откуда пришли, хранятся — пересчёт ничего не теряет.
     *
     * @return array<string, int> «было → стало» => сколько заходов
     */
    public static function recalcSources(): array
    {
        $pdo = Db::pdo();
        $update = $pdo->prepare('UPDATE visits SET source = ? WHERE id = ?');
        $changes = [];

        foreach ($pdo->query('SELECT id, source, utm, referrer FROM visits')->fetchAll() as $v) {
            $utm = json_decode((string) ($v['utm'] ?? ''), true);
            $source = Detect::source(is_array($utm) ? $utm : [], (string) ($v['referrer'] ?? ''));

            if ($source !== $v['source']) {
                $update->execute([$source, $v['id']]);
                $key = $v['source'] . ' → ' . $source;
                $changes[$key] = ($changes[$key] ?? 0) + 1;
            }
        }

        return $changes;
    }

    /** @return list<string> */
    public static function sources(): array
    {
        return Db::pdo()->query('SELECT DISTINCT source FROM visits ORDER BY source')->fetchAll(\PDO::FETCH_COLUMN);
    }

    /** Путь по страницам: каждая страница со своими действиями, по порядку. */
    public static function timeline(int $visitId): array
    {
        $pages = Db::pdo()->prepare('SELECT * FROM visit_pages WHERE visit_id = ? ORDER BY id');
        $pages->execute([$visitId]);
        $events = Db::pdo()->prepare('SELECT * FROM visit_events WHERE visit_id = ? ORDER BY id');
        $events->execute([$visitId]);

        $result = [];
        foreach ($pages->fetchAll() as $page) {
            $result[(int) $page['id']] = $page + ['events' => []];
        }

        $loose = [];
        foreach ($events->fetchAll() as $event) {
            if ($event['page_id'] !== null && isset($result[(int) $event['page_id']])) {
                $result[(int) $event['page_id']]['events'][] = $event;
            } else {
                $loose[] = $event;
            }
        }

        return ['pages' => array_values($result), 'loose' => $loose];
    }

    /** Все заходы посетителя, новые сверху. */
    public static function byVisitor(string $vid): array
    {
        $stmt = Db::pdo()->prepare('SELECT * FROM visits WHERE vid = ? ORDER BY started_at DESC, id DESC LIMIT 200');
        $stmt->execute([$vid]);

        return $stmt->fetchAll();
    }

    /** Заходы, относящиеся к заявке: этого посетителя или привязанные к заявке, по порядку. */
    public static function forLead(array $lead): array
    {
        $stmt = Db::pdo()->prepare('SELECT * FROM visits WHERE lead_id = ? OR (vid = ? AND ? <> \'\') ORDER BY started_at, id LIMIT 200');
        $vid = (string) ($lead['visitor_id'] ?? '');
        $stmt->execute([$lead['id'], $vid, $vid]);

        return $stmt->fetchAll();
    }

    /**
     * Сводка для отчётов за период (без ботов).
     *
     * @return array<string, mixed>
     */
    public static function summary(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $pdo = Db::pdo();
        $range = [Clock::sql($from), Clock::sql($to)];
        $visits = $pdo->prepare("SELECT * FROM visits WHERE started_at >= ? AND started_at < ? AND kind <> 'bot'");
        $visits->execute($range);
        $rows = $visits->fetchAll();

        $leads = $pdo->prepare('SELECT COUNT(*) FROM leads WHERE created_at >= ? AND created_at < ?');
        $leads->execute($range);

        $people = [];
        $sources = [];
        $landings = [];
        $withLead = 0;
        $exitPages = [];

        foreach ($rows as $v) {
            $people[$v['vid']] = true;
            $sources[$v['source']] ??= ['visits' => 0, 'people' => [], 'leads' => 0];
            $sources[$v['source']]['visits']++;
            $sources[$v['source']]['people'][$v['vid']] = true;
            $landing = (string) parse_url((string) $v['landing'], PHP_URL_PATH) ?: '/';
            $landings[$landing] ??= ['visits' => 0, 'leads' => 0];
            $landings[$landing]['visits']++;

            if ($v['lead_id'] !== null) {
                $withLead++;
                $sources[$v['source']]['leads']++;
                $landings[$landing]['leads']++;
            }
        }

        foreach ($sources as &$row) {
            $row['people'] = count($row['people']);
        }
        unset($row);

        // Где уходят: последняя страница заходов без заявки.
        $exit = $pdo->prepare(
            "SELECT p.path FROM visit_pages p JOIN visits v ON v.id = p.visit_id
              WHERE v.started_at >= ? AND v.started_at < ? AND v.kind <> 'bot' AND v.lead_id IS NULL
                AND p.id = (SELECT MAX(p2.id) FROM visit_pages p2 WHERE p2.visit_id = v.id)"
        );
        $exit->execute($range);
        foreach ($exit->fetchAll(\PDO::FETCH_COLUMN) as $path) {
            $path = (string) parse_url((string) $path, PHP_URL_PATH) ?: '/';
            $exitPages[$path] = ($exitPages[$path] ?? 0) + 1;
        }

        // После каких страниц оставляют заявку: страница события lead.
        $before = $pdo->prepare(
            "SELECT p.path FROM visit_events e JOIN visit_pages p ON p.id = e.page_id
              WHERE e.name = 'lead' AND e.created_at >= ? AND e.created_at < ?"
        );
        $before->execute($range);
        $leadPages = [];
        foreach ($before->fetchAll(\PDO::FETCH_COLUMN) as $path) {
            $path = (string) parse_url((string) $path, PHP_URL_PATH) ?: '/';
            $leadPages[$path] = ($leadPages[$path] ?? 0) + 1;
        }

        $clicks = $pdo->prepare(
            "SELECT e.name, COUNT(*) FROM visit_events e JOIN visits v ON v.id = e.visit_id
              WHERE e.created_at >= ? AND e.created_at < ? AND v.kind <> 'bot' AND e.name IN ('tel', 'max') GROUP BY e.name"
        );
        $clicks->execute($range);
        $clickCounts = $clicks->fetchAll(\PDO::FETCH_KEY_PAIR);

        $bots = $pdo->prepare("SELECT COUNT(*) FROM visits WHERE started_at >= ? AND started_at < ? AND kind = 'bot'");
        $bots->execute($range);

        uasort($sources, static fn (array $a, array $b): int => $b['visits'] <=> $a['visits']);
        uasort($landings, static fn (array $a, array $b): int => $b['visits'] <=> $a['visits']);
        arsort($exitPages);
        arsort($leadPages);

        return [
            'people' => count($people),
            'visits' => count($rows),
            'bots' => (int) $bots->fetchColumn(),
            'leads' => (int) $leads->fetchColumn(),
            'visits_with_lead' => $withLead,
            'tel' => (int) ($clickCounts['tel'] ?? 0),
            'max' => (int) ($clickCounts['max'] ?? 0),
            'online' => self::online(),
            'sources' => $sources,
            'landings' => array_slice($landings, 0, 10, true),
            'lead_pages' => array_slice($leadPages, 0, 10, true),
            'exit_pages' => array_slice($exitPages, 0, 10, true),
        ];
    }

    /** Раз в минуту: закрыть заходы без данных дольше 30 минут и окончательно решить «человек / бот». */
    public static function finalize(DateTimeImmutable $now): int
    {
        $stmt = Db::pdo()->prepare('SELECT id FROM visits WHERE checked = 0 AND last_at < ? LIMIT 500');
        $stmt->execute([Clock::sql($now->modify('-' . self::IDLE_MINUTES . ' minutes'))]);
        $ids = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        foreach ($ids as $id) {
            self::reclassify((int) $id, true);
            self::update((int) $id, ['checked' => 1]);
        }

        return count($ids);
    }

    /**
     * Раз в сутки: заходы без заявки старше 3 месяцев и посетители без заходов и заявок.
     * Заходы посетителя с заявкой хранятся, пока хранится заявка (её удаляет очистка заявок).
     */
    public static function cleanup(DateTimeImmutable $now): int
    {
        $pdo = Db::pdo();
        $border = Clock::sql($now->modify('-' . max(1, Settings::int('visits_retention_months')) . ' months'));
        $stmt = $pdo->prepare(
            'SELECT id FROM visits WHERE started_at < ? AND lead_id IS NULL
               AND vid NOT IN (SELECT visitor_id FROM leads WHERE visitor_id IS NOT NULL)'
        );
        $stmt->execute([$border]);
        $ids = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        foreach (array_chunk($ids, 200) as $chunk) {
            $pdo->prepare('DELETE FROM visits WHERE id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')')->execute($chunk);
        }

        $pdo->exec('DELETE FROM visitors WHERE id NOT IN (SELECT vid FROM visits)
                      AND id NOT IN (SELECT visitor_id FROM leads WHERE visitor_id IS NOT NULL)');

        return count($ids);
    }

    /** @return array{0: string, 1: list<mixed>} */
    private static function where(array $f): array
    {
        $where = ['1 = 1'];
        $params = [];

        if (($f['from'] ?? null) instanceof DateTimeImmutable) {
            $where[] = 'started_at >= ?';
            $params[] = Clock::sql($f['from']);
        }

        if (($f['to'] ?? null) instanceof DateTimeImmutable) {
            $where[] = 'started_at < ?';
            $params[] = Clock::sql($f['to']);
        }

        if (($f['source'] ?? '') !== '') {
            $where[] = 'source = ?';
            $params[] = $f['source'];
        }

        match ($f['kind'] ?? 'human') {
            'bot' => $where[] = "kind = 'bot'",
            'all' => null,
            default => $where[] = "kind <> 'bot'",
        };

        if (!empty($f['lead'])) {
            $where[] = 'lead_id IS NOT NULL';
        }

        if (!empty($f['tel'])) {
            $where[] = 'has_tel = 1';
        }

        if (($f['vid'] ?? '') !== '') {
            $where[] = 'vid = ?';
            $params[] = $f['vid'];
        }

        return [implode(' AND ', $where), $params];
    }
}
