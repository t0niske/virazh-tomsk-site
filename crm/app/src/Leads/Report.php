<?php

declare(strict_types=1);

namespace App\Leads;

use App\Support\Db;
use DateTimeImmutable;

/**
 * Показатели из раздела 7 предложения: скорость, дозвон, записи, каналы, причины отказов, источники.
 * Заявок немного (сотни в месяц), поэтому считаем в PHP — одинаково для SQLite и MySQL.
 */
final class Report
{
    /** @return array<string, mixed> */
    public static function build(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $stmt = Db::pdo()->prepare('SELECT * FROM leads WHERE created_at >= ? AND created_at < ? ORDER BY created_at');
        $stmt->execute([Clock::sql($from), Clock::sql($to)]);
        $leads = $stmt->fetchAll();
        $now = Clock::now();

        $r = [
            'from' => $from,
            'to' => $to,
            'total' => count($leads),
            'off_hours' => 0,
            'junk' => 0,
            'contacted' => 0,
            'contacted_call' => 0,
            'contacted_max' => 0,
            'enrolled' => 0,
            'refused' => 0,
            'unreachable' => 0,
            'in_progress' => 0,
            'chose_max' => 0,
            'no_response_30' => 0,
            'avg_response_min' => null,
            'avg_night_time' => null,
            'statuses' => array_fill_keys(array_keys(Status::LABELS), 0),
            'reasons' => [],
            'sources' => [],
            'staff' => [],
            'days' => [],
        ];

        $responses = [];
        $nightTimes = [];
        $staffNames = Db::pdo()->query('SELECT id, name FROM staff')->fetchAll(\PDO::FETCH_KEY_PAIR);

        foreach ($leads as $lead) {
            $status = $lead['status'];
            $r['statuses'][$status] = ($r['statuses'][$status] ?? 0) + 1;
            $day = substr($lead['created_at'], 0, 10);
            $r['days'][$day] ??= ['total' => 0, 'contacted' => 0, 'enrolled' => 0];
            $r['days'][$day]['total']++;

            $source = $lead['source'] ?: 'Сайт';
            $r['sources'][$source] ??= ['total' => 0, 'enrolled' => 0];
            $r['sources'][$source]['total']++;

            if ($status === Status::JUNK) {
                $r['junk']++;
                continue;
            }

            if ((int) $lead['off_hours'] === 1) {
                $r['off_hours']++;
            }

            if ($lead['channel'] === 'max') {
                $r['chose_max']++;
            }

            if ($lead['contacted_at'] !== null) {
                $r['contacted']++;
                $r['days'][$day]['contacted']++;
                $lead['contacted_via'] === 'max' ? $r['contacted_max']++ : $r['contacted_call']++;
            }

            match ($status) {
                Status::ENROLLED => $r['enrolled']++,
                Status::REFUSED => $r['refused']++,
                Status::UNREACHABLE => $r['unreachable']++,
                default => $r['in_progress']++,
            };

            if ($status === Status::ENROLLED) {
                $r['days'][$day]['enrolled']++;
                $r['sources'][$source]['enrolled']++;
            }

            if ($status === Status::REFUSED) {
                $reason = $lead['refusal_reason'] ?: 'Не указана';
                $r['reasons'][$reason] = ($r['reasons'][$reason] ?? 0) + 1;
            }

            $due = new DateTimeImmutable($lead['due_at']);
            $first = Clock::parse($lead['first_action_at']);
            $minutes = (int) round((($first ?? $now)->getTimestamp() - $due->getTimestamp()) / 60);

            if ($first !== null) {
                if ((int) $lead['off_hours'] === 1) {
                    $nightTimes[] = (int) $first->format('G') * 60 + (int) $first->format('i');
                } else {
                    $responses[] = max(0, $minutes);
                }
            }

            if ($minutes > 30 && $due < $now) {
                $r['no_response_30']++;
            }

            if ($lead['assigned_to'] !== null) {
                $name = $staffNames[$lead['assigned_to']] ?? 'Удалённый сотрудник';
                $r['staff'][$name] ??= ['total' => 0, 'contacted' => 0, 'enrolled' => 0, 'response' => []];
                $r['staff'][$name]['total']++;
                $r['staff'][$name]['contacted'] += $lead['contacted_at'] !== null ? 1 : 0;
                $r['staff'][$name]['enrolled'] += $status === Status::ENROLLED ? 1 : 0;

                if ($first !== null && (int) $lead['off_hours'] === 0) {
                    $r['staff'][$name]['response'][] = max(0, $minutes);
                }
            }
        }

        if ($responses !== []) {
            $r['avg_response_min'] = (int) round(array_sum($responses) / count($responses));
        }

        if ($nightTimes !== []) {
            $avg = (int) round(array_sum($nightTimes) / count($nightTimes));
            $r['avg_night_time'] = sprintf('%02d:%02d', intdiv($avg, 60), $avg % 60);
        }

        foreach ($r['staff'] as &$row) {
            $row['response'] = $row['response'] === [] ? null : (int) round(array_sum($row['response']) / count($row['response']));
        }
        unset($row);

        arsort($r['reasons']);
        uasort($r['sources'], static fn (array $a, array $b): int => $b['total'] <=> $a['total']);
        ksort($r['days']);

        return $r;
    }

    public static function percent(int $part, int $total): string
    {
        return $total > 0 ? (string) round($part * 100 / $total) . '%' : '—';
    }

    /** Еженедельная сводка руководителю в MAX. */
    public static function weeklyText(array $r): string
    {
        $base = $r['total'] - $r['junk'];
        $lines = [
            '📊 Заявки за ' . self::periodText($r['from'], $r['to']),
            '',
            "Всего: {$r['total']} (из них ночью и в выходные: {$r['off_hours']})",
            'Среднее время до звонка: ' . ($r['avg_response_min'] === null ? '—' : $r['avg_response_min'] . ' мин'),
            'По ночным заявкам: ' . ($r['avg_night_time'] === null ? '—' : 'в среднем в ' . $r['avg_night_time']),
            "Связались: {$r['contacted']} из $base (" . self::percent($r['contacted'], $base) . ')',
            "— по телефону: {$r['contacted_call']}, в MAX: {$r['contacted_max']}",
            "Записались: {$r['enrolled']}",
            "Не дозвонились: {$r['unreachable']}",
            "Без ответа дольше 30 мин: {$r['no_response_30']}",
        ];

        if ($r['junk'] > 0) {
            $lines[] = "Мусорных заявок: {$r['junk']}";
        }

        return implode("\n", $lines);
    }

    /** «19–25 октября» или «28 октября – 3 ноября». $to — исключительная граница. */
    public static function periodText(DateTimeImmutable $from, DateTimeImmutable $to): string
    {
        $months = [1 => 'января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
        $last = $to->modify('-1 day');

        if ($from->format('Y-m') === $last->format('Y-m')) {
            return $from->format('j') . '–' . $last->format('j') . ' ' . $months[(int) $last->format('n')];
        }

        return $from->format('j') . ' ' . $months[(int) $from->format('n')] . ' – ' . $last->format('j') . ' ' . $months[(int) $last->format('n')];
    }
}
