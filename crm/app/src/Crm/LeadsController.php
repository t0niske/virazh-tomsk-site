<?php

declare(strict_types=1);

namespace App\Crm;

use App\Chat\Chat;
use App\Leads\Audit;
use App\Leads\Clock;
use App\Leads\LeadForm;
use App\Leads\Leads;
use App\Leads\LeadService;
use App\Leads\Phone;
use App\Leads\Scheduler;
use App\Leads\Settings;
use App\Leads\Status;
use App\Max\AdminChat;
use App\Support\Db;
use App\Visits\Visits;
use DateTimeImmutable;

/** Список заявок, очередь «Кому звонить сейчас», карточка и действия с заявкой. */
final class LeadsController
{
    private const PER_PAGE = 50;

    public static function index(): void
    {
        $f = [
            'status' => (string) ($_GET['status'] ?? ''),
            'period' => (string) ($_GET['period'] ?? 'month'),
            'from' => (string) ($_GET['from'] ?? ''),
            'to' => (string) ($_GET['to'] ?? ''),
            'service' => (string) ($_GET['service'] ?? ''),
            'source' => (string) ($_GET['source'] ?? ''),
            'staff' => (string) ($_GET['staff'] ?? ''),
            'q' => trim((string) ($_GET['q'] ?? '')),
        ];
        $page = max(1, (int) ($_GET['page'] ?? 1));
        [$where, $params] = self::filters($f);
        $pdo = Db::pdo();

        $count = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE $where");
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $stmt = $pdo->prepare("SELECT * FROM leads WHERE $where ORDER BY id DESC LIMIT " . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE));
        $stmt->execute($params);

        View::render('leads', [
            'title' => 'Заявки',
            'active' => 'leads',
            'leads' => $stmt->fetchAll(),
            'filters' => $f,
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / self::PER_PAGE)),
            'services' => $pdo->query("SELECT DISTINCT service FROM leads WHERE service IS NOT NULL AND service <> '' ORDER BY service")->fetchAll(\PDO::FETCH_COLUMN),
            'sources' => $pdo->query('SELECT DISTINCT source FROM leads ORDER BY source')->fetchAll(\PDO::FETCH_COLUMN),
            'staffList' => Staff::all(true),
            'staffNames' => array_column(Staff::all(), 'name', 'id'),
            'queueCount' => count(Scheduler::dueLeads(Clock::now())),
        ]);
    }

    public static function queue(): void
    {
        $now = Clock::now();
        $closed = "'" . implode("','", Status::CLOSED) . "'";
        $later = Db::pdo()->prepare(
            "SELECT * FROM leads WHERE status NOT IN ($closed) AND next_action IN ('call', 'message')
               AND next_action_at > ? AND next_action_at < ? ORDER BY next_action_at"
        );
        $later->execute([Clock::sql($now), Clock::sql($now->setTime(23, 59, 59))]);

        // «Сейчас»: подошло время звонка или новые, которые никто не взял.
        $due = Db::pdo()->prepare(
            "SELECT * FROM leads WHERE status NOT IN ($closed) AND (
                (next_action IN ('call', 'message') AND next_action_at <= ?) OR (status = 'new' AND due_at <= ?)
             ) ORDER BY CASE WHEN status = 'new' THEN 0 ELSE 1 END, next_action_at, id"
        );
        $due->execute([Clock::sql($now), Clock::sql($now)]);

        View::render('queue', [
            'title' => 'Кому звонить',
            'active' => 'queue',
            'due' => $due->fetchAll(),
            'later' => $later->fetchAll(),
            'staffNames' => array_column(Staff::all(), 'name', 'id'),
            'open' => \App\Leads\Schedule::fromSettings()->isOpen($now),
        ]);
    }

    public static function show(int $id): void
    {
        $lead = Leads::find($id);

        if ($lead === null) {
            View::render('error', ['title' => 'Не найдено', 'message' => 'Заявки №' . $id . ' нет — возможно, её удалили.'], 404);

            return;
        }

        // В журнал — не чаще раза в 10 минут на одну заявку, чтобы обновления страницы не засоряли его.
        $seen = $_SESSION['viewed'][$id] ?? 0;
        if (time() - $seen > 600) {
            Audit::log(Auth::actor(), 'lead_view', $id);
            $_SESSION['viewed'][$id] = time();
        }

        $events = Leads::events($id);
        View::render('lead', [
            'title' => 'Заявка №' . $id,
            'active' => 'leads',
            'lead' => $lead,
            'events' => array_reverse($events),
            'chat' => array_values(array_filter($events, static fn (array $e): bool => in_array($e['type'], ['max_in', 'max_out', 'max_bot'], true))),
            'comments' => array_values(array_filter($events, static fn (array $e): bool => $e['type'] === 'comment')),
            'staffList' => Staff::all(true),
            'staffName' => Leads::staffName($lead['assigned_to'] !== null ? (int) $lead['assigned_to'] : null),
            'statusLine' => AdminChat::statusLine($lead),
            'reasons' => Settings::lines('refusal_reasons'),
            'sms' => self::smsFor($id),
            'visits' => Visits::forLead($lead),
            'siteChat' => ($lead['chat_id'] ?? null) !== null ? Chat::findByChatId($lead['chat_id']) : null,
        ]);
    }

    public static function action(int $id, string $action): void
    {
        $lead = Leads::find($id);
        $back = '/crm/lead/' . $id;

        if (($_POST['return'] ?? '') === 'queue') {
            $back = '/crm/queue';
        }

        if ($lead === null) {
            View::redirect('/crm');

            return;
        }

        $actor = Auth::actor();

        switch ($action) {
            case 'take':
                LeadService::take($lead, $actor);
                Audit::log($actor, 'lead_status', $id, 'Взял в работу');
                View::flash('Заявка ваша — звоните ' . Phone::format($lead['phone']));
                break;

            case 'miss':
                $lead = LeadService::miss($lead, $actor);
                Audit::log($actor, 'lead_status', $id, 'Недозвон ' . $lead['attempt']);
                View::flash('Записали недозвон. ' . strip_tags(AdminChat::statusLine($lead)));
                break;

            case 'status':
                $status = (string) ($_POST['status'] ?? '');

                if (!isset(Status::LABELS[$status])) {
                    break;
                }

                $reason = trim((string) ($_POST['reason'] ?? ''));
                if ($reason === 'other' || $reason === '') {
                    $reason = trim((string) ($_POST['reason_other'] ?? '')) ?: $reason;
                }

                LeadService::setStatus($lead, $status, $actor, $reason);
                Audit::log($actor, 'lead_status', $id, Status::label($status) . ($status === Status::REFUSED ? ": $reason" : ''));
                View::flash('Статус: ' . Status::label($status));
                break;

            case 'comment':
                $text = trim((string) ($_POST['text'] ?? ''));
                if ($text !== '') {
                    LeadService::comment($lead, $actor, $text);
                    Audit::log($actor, 'lead_comment', $id);
                }
                break;

            case 'schedule':
                $at = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', (string) ($_POST['at'] ?? ''));

                if ($at === false) {
                    View::flash('Укажите дату и время.', 'error');
                    break;
                }

                LeadService::scheduleCall($lead, $at, $actor, ($_POST['kind'] ?? '') === 'message' ? 'message' : 'call');
                Audit::log($actor, 'lead_update', $id, 'Запланирован контакт на ' . $at->format('d.m.Y H:i'));
                View::flash('Запланировано на ' . View::date($at->format('Y-m-d H:i:s')));
                break;

            case 'assign':
                $staffId = (int) ($_POST['staff_id'] ?? 0);
                LeadService::assign($lead, $staffId > 0 ? $staffId : null, $actor);
                Audit::log($actor, 'lead_update', $id, 'Ответственный: ' . (Leads::staffName($staffId ?: null) ?? 'нет'));
                break;

            case 'reply':
                $text = trim((string) ($_POST['text'] ?? ''));

                if ($text === '') {
                    break;
                }

                if (LeadService::maxReply($lead, $actor, mb_substr($text, 0, 4000))) {
                    Audit::log($actor, 'max_reply', $id);
                    View::flash('Отправлено в MAX');
                } else {
                    View::flash('Не удалось отправить: клиент не открывал бота или MAX недоступен.', 'error');
                }
                $back .= '#chat';
                break;

            case 'edit':
                self::edit($lead);
                break;

            case 'delete':
                if (!Auth::isManager()) {
                    View::flash('Удалять заявки может только руководитель.', 'error');
                    break;
                }

                Leads::delete($id);
                Audit::log($actor, 'lead_delete', $id, "Удалена заявка №$id");
                View::flash("Заявка №$id удалена");
                $back = '/crm';
                break;
        }

        View::redirect($back);
    }

    public static function create(string $method): void
    {
        if ($method !== 'POST') {
            View::render('new', ['title' => 'Новая заявка', 'active' => 'leads', 'errors' => [], 'old' => []]);

            return;
        }

        ['data' => $data, 'errors' => $errors] = LeadForm::validate($_POST + ['consent' => '1']);

        if ($errors !== []) {
            View::render('new', ['title' => 'Новая заявка', 'active' => 'leads', 'errors' => $errors, 'old' => $_POST], 422);

            return;
        }

        $data['utm_source'] = 'office';
        $result = LeadService::accept($data, sendSms: isset($_POST['send_sms']), notify: false);
        $lead = $result['lead'];

        if (trim((string) ($_POST['comment'] ?? '')) !== '') {
            LeadService::comment($lead, Auth::actor(), (string) $_POST['comment']);
        }

        Audit::log(Auth::actor(), 'lead_update', (int) $lead['id'], $result['created'] ? 'Заявка добавлена вручную' : 'Повторное обращение добавлено вручную');
        View::flash($result['created'] ? 'Заявка добавлена' : 'С этого номера уже есть заявка — обращение добавлено к ней');
        View::redirect('/crm/lead/' . $lead['id']);
    }

    private static function edit(array $lead): void
    {
        $name = trim((string) ($_POST['name'] ?? ''));
        $phone = Phone::normalize((string) ($_POST['phone'] ?? ''));
        $service = trim((string) ($_POST['service'] ?? ''));

        if ($name === '' || $phone === null) {
            View::flash('Нужны имя и российский мобильный номер.', 'error');

            return;
        }

        $changes = [];
        foreach (['name' => $name, 'phone' => $phone, 'service' => $service ?: null] as $field => $value) {
            if ($lead[$field] !== $value) {
                $changes[$field] = $value;
            }
        }

        if ($changes === []) {
            return;
        }

        $changes['search'] = Leads::searchString($name, $phone, $service);
        $lead = Leads::update((int) $lead['id'], $changes);
        $list = implode(', ', array_keys(array_diff_key($changes, ['search' => 1])));
        Leads::event((int) $lead['id'], 'edited', "Изменено: $list", Auth::actor());
        Audit::log(Auth::actor(), 'lead_update', (int) $lead['id'], "Изменено: $list");
        AdminChat::refresh($lead);
        View::flash('Сохранено');
    }

    /** @return array{0: string, 1: list<mixed>} */
    private static function filters(array $f): array
    {
        $where = ['1 = 1'];
        $params = [];
        $now = Clock::now();

        if ($f['status'] === 'active') {
            $where[] = 'status NOT IN (' . implode(',', array_fill(0, count(Status::CLOSED), '?')) . ')';
            $params = [...$params, ...Status::CLOSED];
        } elseif (isset(Status::LABELS[$f['status']])) {
            $where[] = 'status = ?';
            $params[] = $f['status'];
        }

        [$from, $to] = self::period($f['period'], $f['from'], $f['to'], $now);
        if ($from !== null) {
            $where[] = 'created_at >= ?';
            $params[] = Clock::sql($from);
        }
        if ($to !== null) {
            $where[] = 'created_at < ?';
            $params[] = Clock::sql($to);
        }

        if ($f['service'] !== '') {
            $where[] = 'service = ?';
            $params[] = $f['service'];
        }

        if ($f['source'] !== '') {
            $where[] = 'source = ?';
            $params[] = $f['source'];
        }

        if ($f['staff'] === 'me') {
            $where[] = 'assigned_to = ?';
            $params[] = Auth::user()['id'];
        } elseif ($f['staff'] === 'none') {
            $where[] = 'assigned_to IS NULL';
        } elseif (ctype_digit($f['staff'])) {
            $where[] = 'assigned_to = ?';
            $params[] = (int) $f['staff'];
        }

        if ($f['q'] !== '') {
            $q = mb_strtolower($f['q']);
            $digits = preg_replace('/\D+/', '', $q);

            if (ctype_digit(ltrim($f['q'], '№#'))) {
                $where[] = '(id = ? OR search LIKE ?)';
                $params[] = (int) ltrim($f['q'], '№#');
                $params[] = '%' . $digits . '%';
            } else {
                $where[] = 'search LIKE ?';
                $params[] = '%' . (strlen($digits) >= 4 && preg_match('/^[\d\s()+-]+$/', $q) ? $digits : $q) . '%';
            }
        }

        return [implode(' AND ', $where), $params];
    }

    /** @return array{0: ?DateTimeImmutable, 1: ?DateTimeImmutable} */
    public static function period(string $period, string $from, string $to, DateTimeImmutable $now): array
    {
        $today = $now->setTime(0, 0);

        return match ($period) {
            'today' => [$today, null],
            'days30' => [$today->modify('-29 days'), null],
            'week' => [$today->modify('monday this week'), null],
            'last_week' => [$today->modify('monday this week')->modify('-7 days'), $today->modify('monday this week')],
            'month' => [$today->modify('first day of this month'), null],
            'last_month' => [$today->modify('first day of last month'), $today->modify('first day of this month')],
            'custom' => [
                DateTimeImmutable::createFromFormat('!Y-m-d', $from) ?: null,
                ($d = DateTimeImmutable::createFromFormat('!Y-m-d', $to)) ? $d->modify('+1 day') : null,
            ],
            default => [null, null],
        };
    }

    private static function smsFor(int $leadId): array
    {
        $stmt = Db::pdo()->prepare('SELECT * FROM sms_messages WHERE lead_id = ? ORDER BY id DESC');
        $stmt->execute([$leadId]);

        return $stmt->fetchAll();
    }
}
