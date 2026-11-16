<?php

declare(strict_types=1);

namespace App\Crm;

use App\Leads\Audit;
use App\Leads\Clock;
use App\Visits\Visits;

/** Заходы на сайт: список с фильтрами и страница одного захода. */
final class VisitsController
{
    public static function index(): void
    {
        $f = [
            'period' => (string) ($_GET['period'] ?? 'days30'),
            'from' => (string) ($_GET['from'] ?? ''),
            'to' => (string) ($_GET['to'] ?? ''),
            'source' => (string) ($_GET['source'] ?? ''),
            'kind' => (string) ($_GET['kind'] ?? 'human'),
            'lead' => isset($_GET['lead']) && $_GET['lead'] !== '',
            'tel' => isset($_GET['tel']) && $_GET['tel'] !== '',
        ];
        [$from, $to] = LeadsController::period($f['period'], $f['from'], $f['to'], Clock::now());
        $page = max(1, (int) ($_GET['page'] ?? 1));
        // Даты периода заменяют строковые from/to из запроса (оператор + их бы не перезаписал).
        $query = ['from' => $from, 'to' => $to] + $f;
        $result = Visits::search($query, $page);
        $daily = Visits::daily($query, Clock::now());

        View::render('visits', [
            'title' => 'Заходы на сайт',
            'active' => 'visits',
            'filters' => $f,
            'rows' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / 50)),
            'sources' => Visits::sources(),
            'online' => Visits::online(),
            'daily' => $daily,
            'scale' => self::scale(max([0, ...array_column($daily['days'], 'total')])),
        ]);
    }

    /**
     * Шкала оси «заходы»: верх — круглое число (1, 2, 5 × 10ⁿ на деление), не больше 5 делений, 0 внизу.
     *
     * @return array{max: int, ticks: list<int>}
     */
    public static function scale(int $value): array
    {
        $step = 1;
        foreach ([1, 2, 5, 10, 20, 25, 50, 100, 200, 250, 500, 1000, 2000, 2500, 5000, 10000] as $candidate) {
            $step = $candidate;
            if ($value <= $candidate * 5) {
                break;
            }
        }

        $max = max($step * 4, (int) ceil($value / $step) * $step);

        return ['max' => $max, 'ticks' => range(0, $max, $step)];
    }

    public static function show(int $id): void
    {
        $visit = Visits::find($id);

        if ($visit === null) {
            View::render('error', ['title' => 'Не найдено', 'message' => 'Такого захода нет — возможно, он уже удалён (заходы без заявки хранятся 3 месяца).'], 404);

            return;
        }

        // В заходе есть IP (персональные данные) — просмотр пишем в журнал, не чаще раза в 10 минут.
        $seen = $_SESSION['visit_viewed'][$id] ?? 0;
        if (time() - $seen > 600) {
            Audit::log(Auth::actor(), 'visit_view', $visit['lead_id'] !== null ? (int) $visit['lead_id'] : null, "Открыл заход №$id");
            $_SESSION['visit_viewed'][$id] = time();
        }

        View::render('visit', [
            'title' => 'Заход №' . $id,
            'active' => 'visits',
            'visit' => $visit,
            'timeline' => Visits::timeline($id),
            'others' => Visits::byVisitor($visit['vid']),
            'showIp' => Auth::isManager(),
        ]);
    }
}
