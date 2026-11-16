<?php

declare(strict_types=1);

namespace App\Crm;

use App\Leads\Audit;
use App\Leads\Clock;
use App\Leads\Report;
use App\Leads\Source;
use App\Leads\Status;
use App\Support\Db;
use App\Visits\Visits;
use DateTimeImmutable;

/** Отчёты руководителя и выгрузка в CSV для Excel. */
final class ReportsController
{
    public static function index(): void
    {
        [$period, $from, $to] = self::range();

        View::render('reports', [
            'title' => 'Отчёты',
            'active' => 'reports',
            'r' => Report::build($from, $to),
            'site' => Visits::summary($from, $to),
            'period' => $period,
            'fromValue' => $from->format('Y-m-d'),
            'toValue' => $to->modify('-1 day')->format('Y-m-d'),
        ]);
    }

    /** ?kind=leads — все заявки периода; ?kind=summary — сводка. */
    public static function csv(): void
    {
        [, $from, $to] = self::range();
        $kind = ($_GET['kind'] ?? 'leads') === 'summary' ? 'summary' : 'leads';
        $rows = $kind === 'leads' ? self::leadRows($from, $to) : self::summaryRows(Report::build($from, $to));

        Audit::log(Auth::actor(), 'export', null, "CSV $kind за " . Report::periodText($from, $to));

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="zayavki-' . $kind . '-' . $from->format('Y-m-d') . '.csv"');
        header('Cache-Control: no-store');

        // BOM и точка с запятой — чтобы Excel сразу открыл кириллицу по столбцам.
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");

        foreach ($rows as $row) {
            fputcsv($out, array_map(static fn ($v): string => self::safeCell((string) $v), $row), ';', '"', '');
        }

        fclose($out);
    }

    /** @return array{0: string, 1: DateTimeImmutable, 2: DateTimeImmutable} */
    private static function range(): array
    {
        $period = (string) ($_GET['period'] ?? 'last_week');
        $now = Clock::now();
        [$from, $to] = LeadsController::period($period, (string) ($_GET['from'] ?? ''), (string) ($_GET['to'] ?? ''), $now);
        $from ??= $now->setTime(0, 0)->modify('-29 days');
        $to ??= $now->setTime(0, 0)->modify('+1 day');

        return [$period, $from, $to];
    }

    private static function leadRows(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $stmt = Db::pdo()->prepare('SELECT * FROM leads WHERE created_at >= ? AND created_at < ? ORDER BY id');
        $stmt->execute([Clock::sql($from), Clock::sql($to)]);
        $staff = array_column(Staff::all(), 'name', 'id');

        $rows = [[
            '№', 'Поступила', 'Имя', 'Телефон', 'Услуга', 'Удобно', 'Связь', 'Источник', 'utm_source', 'utm_medium', 'utm_campaign',
            'utm_content', 'utm_term', 'Статус', 'Причина отказа', 'Ответственный', 'Попыток', 'Ночная/выходной', 'Первое действие',
            'Минут до первого действия', 'Связались', 'Как связались', 'Заявок с номера', 'Страница',
        ]];

        foreach ($stmt as $lead) {
            $minutes = $lead['first_action_at'] !== null
                ? max(0, intdiv(strtotime($lead['first_action_at']) - strtotime($lead['due_at']), 60))
                : '';

            $rows[] = [
                $lead['id'], $lead['created_at'], $lead['name'], ltrim($lead['phone'], '+'), $lead['service'],
                Source::CONTACT_TIMES[$lead['contact_time']] ?? '', Source::CHANNELS[$lead['channel']] ?? '', $lead['source'],
                $lead['utm_source'], $lead['utm_medium'], $lead['utm_campaign'], $lead['utm_content'], $lead['utm_term'],
                Status::label($lead['status']), $lead['refusal_reason'], $staff[$lead['assigned_to']] ?? '', $lead['attempt'],
                (int) $lead['off_hours'] === 1 ? 'да' : 'нет', $lead['first_action_at'], $minutes,
                $lead['contacted_at'], $lead['contacted_via'] === 'max' ? 'MAX' : ($lead['contacted_via'] ? 'звонок' : ''),
                $lead['request_count'], $lead['page_url'],
            ];
        }

        return $rows;
    }

    private static function summaryRows(array $r): array
    {
        $base = $r['total'] - $r['junk'];
        $rows = [
            ['Показатель', 'Значение'],
            ['Период', Report::periodText($r['from'], $r['to'])],
            ['Всего заявок', $r['total']],
            ['Ночью и в выходные', $r['off_hours']],
            ['Мусорные', $r['junk']],
            ['Среднее время до звонка, мин', $r['avg_response_min'] ?? ''],
            ['Ночные заявки: среднее время звонка', $r['avg_night_time'] ?? ''],
            ['Связались', $r['contacted']],
            ['Доля дозвона', Report::percent($r['contacted'], $base)],
            ['— по телефону', $r['contacted_call']],
            ['— в MAX', $r['contacted_max']],
            ['Выбрали переписку в MAX', $r['chose_max']],
            ['Записались', $r['enrolled']],
            ['Заявка → запись', Report::percent($r['enrolled'], $base)],
            ['Отказы', $r['refused']],
            ['Не дозвонились', $r['unreachable']],
            ['В работе', $r['in_progress']],
            ['Без ответа дольше 30 мин', $r['no_response_30']],
            [],
            ['Причина отказа', 'Заявок'],
        ];

        foreach ($r['reasons'] as $reason => $count) {
            $rows[] = [$reason, $count];
        }

        $rows[] = [];
        $rows[] = ['Источник', 'Заявок', 'Записались'];
        foreach ($r['sources'] as $source => $row) {
            $rows[] = [$source, $row['total'], $row['enrolled']];
        }

        $rows[] = [];
        $rows[] = ['Сотрудник', 'Заявок', 'Связались', 'Записались', 'Среднее время до звонка, мин'];
        foreach ($r['staff'] as $name => $row) {
            $rows[] = [$name, $row['total'], $row['contacted'], $row['enrolled'], $row['response'] ?? ''];
        }

        $rows[] = [];
        $rows[] = ['День', 'Заявок', 'Связались', 'Записались'];
        foreach ($r['days'] as $day => $row) {
            $rows[] = [$day, $row['total'], $row['contacted'], $row['enrolled']];
        }

        return $rows;
    }

    /** Защита от формул в Excel: ячейка, начинающаяся с =, +, -, @, открывается как текст. Телефон выгружаем цифрами. */
    private static function safeCell(string $value): string
    {
        return $value !== '' && str_contains("=+-@\t\r", $value[0]) ? "'" . $value : $value;
    }
}
