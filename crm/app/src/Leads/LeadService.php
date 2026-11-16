<?php

declare(strict_types=1);

namespace App\Leads;

use App\Leads\Notify\Notifier;
use App\Chat\Chat;
use App\Max\AdminChat;
use App\Visits\Visits;
use App\Max\ClientMessages;
use App\Max\Max;
use App\Sms\Sms;
use DateTimeImmutable;

/**
 * Всё, что происходит с заявкой: приём, «Беру», недозвон, статусы, комментарии, переписка.
 * Одни и те же действия вызываются из админки, из кнопок в чате MAX и из планировщика,
 * поэтому чат и админка всегда показывают одно и то же.
 */
final class LeadService
{
    /**
     * Принимает проверенные данные формы. Повторная заявка с того же номера
     * в течение dedupe_days дописывается к существующей.
     *
     * @param array<string, ?string> $data поля из LeadForm
     * @param bool $sendSms false — для заявок, пришедших из самого MAX
     * @param bool $notify false — не слать письмо и Telegram (заявку внёс сам администратор)
     * @return array{lead: array, created: bool, call_at: DateTimeImmutable}
     */
    public static function accept(array $data, bool $sendSms = true, bool $notify = true): array
    {
        $now = Clock::now();
        $schedule = Schedule::fromSettings();
        $callAt = $schedule->firstCallAt($now, $data['contact_time']);
        $open = $schedule->isOpen($now);
        $since = Clock::sql($now->modify('-' . max(1, Settings::int('dedupe_days')) . ' days'));
        $existing = Leads::findRecentByPhone($data['phone'], $since);

        $plan = [
            'status' => Status::NEW,
            'attempt' => 0,
            'due_at' => Clock::sql($callAt),
            'next_action' => $data['channel'] === 'max' ? 'message' : 'call',
            'next_action_at' => Clock::sql($callAt),
            // Заявку в рабочее время показывает сама карточка в чате, отдельное «пора звонить» не нужно.
            'notified_at' => $open && $callAt <= $now ? Clock::sql($now) : null,
            'reminded_level' => 0,
            'off_hours' => $open ? 0 : 1,
        ];

        if ($existing === null) {
            $id = Leads::insert($data + $plan + [
                'created_at' => Clock::sql($now),
                'updated_at' => Clock::sql($now),
                'last_request_at' => Clock::sql($now),
                'request_count' => 1,
                'source' => Source::label($data),
                'search' => Leads::searchString($data['name'], $data['phone'], $data['service']),
            ]);
            $lead = Leads::find($id);
            Leads::event($id, 'created', self::describeRequest($data), Actor::client($data['name']), self::requestData($data));
            $created = true;
            $reopened = true;
        } else {
            $reopened = Status::isClosed($existing['status']) && $existing['status'] !== Status::ENROLLED;
            $fields = [
                'last_request_at' => Clock::sql($now),
                'request_count' => (int) $existing['request_count'] + 1,
                'service' => $data['service'] ?? $existing['service'],
                'name' => !Leads::hasName($existing['name']) && Leads::hasName($data['name']) ? $data['name'] : $existing['name'],
                'contact_time' => $data['contact_time'],
                'channel' => $data['channel'],
            ];

            $fields['search'] = Leads::searchString($fields['name'], $existing['phone'], $fields['service']);

            if ($reopened) {
                $fields += $plan + ['closed_at' => null, 'refusal_reason' => null, 'contacted_at' => null];
            }

            $lead = Leads::update((int) $existing['id'], $fields);
            Leads::event((int) $lead['id'], 'repeat', self::describeRequest($data) . ($reopened ? ' Заявка снова открыта.' : ''),
                Actor::client($data['name']), self::requestData($data));
            $created = false;
        }

        if ($sendSms) {
            self::sendAcceptedSms($lead, $schedule, $callAt, $now);
        }

        if ($reopened) {
            AdminChat::postCard($lead, $open);
        } else {
            AdminChat::post(sprintf('🔁 Повторная заявка №%d: %s, %s. Статус: %s.',
                $lead['id'], $lead['name'], Phone::format($lead['phone']), Status::label($lead['status'])), (int) $lead['id'], null, $open);
        }

        if (($data['chat_id'] ?? null) !== null) {
            Chat::linkLead((int) $lead['id'], $data['chat_id']);
        }

        if (($data['visitor_id'] ?? null) !== null || ($data['visit_id'] ?? null) !== null) {
            Visits::linkLead((int) $lead['id'], $data['visitor_id'] ?? null, $data['visit_id'] ?? null);
        }

        $lead = Leads::find((int) $lead['id']);

        if ($notify) {
            Notifier::leadAccepted($lead, $created, $data);
        }

        return ['lead' => $lead, 'created' => $created, 'call_at' => $callAt];
    }

    /** «Беру» — заявку взяли в работу. */
    public static function take(array $lead, Actor $actor): array
    {
        $now = Clock::sql();
        $fields = [
            'taken_at' => $lead['taken_at'] ?? $now,
            'first_action_at' => $lead['first_action_at'] ?? $now,
            'assigned_to' => $actor->staffId ?? $lead['assigned_to'],
            'notified_at' => $now,
        ];

        if ($lead['status'] === Status::NEW) {
            $fields['status'] = Status::IN_WORK;
        }

        $lead = Leads::update((int) $lead['id'], $fields);
        Leads::event((int) $lead['id'], 'taken', $actor->name . ' взял(а) в работу', $actor);
        AdminChat::refresh($lead);

        return $lead;
    }

    /** «Не дозвонился»: считаем попытку, планируем следующую, после первой — сообщение клиенту. */
    public static function miss(array $lead, Actor $actor): array
    {
        $now = Clock::now();
        $schedule = Schedule::fromSettings();
        $misses = (int) $lead['attempt'] + 1;
        $plan = $schedule->afterMiss($misses, $now, new DateTimeImmutable($lead['created_at']));

        $lead = Leads::update((int) $lead['id'], [
            'status' => Status::NO_ANSWER,
            'attempt' => $misses,
            'taken_at' => $lead['taken_at'] ?? Clock::sql($now),
            'first_action_at' => $lead['first_action_at'] ?? Clock::sql($now),
            'assigned_to' => $lead['assigned_to'] ?? $actor->staffId,
            'next_action' => $plan['action'],
            'next_action_at' => Clock::sql($plan['at']),
            'notified_at' => null,
        ]);

        // В историю — точная дата: «сегодня» через неделю читалось бы неверно.
        $next = ($plan['action'] === 'call' ? 'Следующий звонок ' : 'Попытки закончились, последнее сообщение клиенту ')
            . $plan['at']->format('d.m в H:i') . '.';
        Leads::event((int) $lead['id'], 'call_miss', "Не дозвонились ($misses из {$schedule->maxAttempts}). $next", $actor,
            ['attempt' => $misses, 'next_action_at' => Clock::sql($plan['at'])]);

        if ($misses === 1) {
            self::sendMissedMessage($lead);
        }

        AdminChat::refresh($lead);

        return $lead;
    }

    /** Смена статуса: записан, отказ, думает, переписка, мусор, снова новая. */
    public static function setStatus(array $lead, string $status, Actor $actor, ?string $reason = null): array
    {
        if (!isset(Status::LABELS[$status])) {
            throw new \InvalidArgumentException("Неизвестный статус $status");
        }

        $now = Clock::sql();
        $fields = ['status' => $status];

        if (in_array($status, Status::CONTACTED, true)) {
            $fields['contacted_at'] = $lead['contacted_at'] ?? $now;
            $fields['contacted_via'] = $status === Status::CHAT ? 'max' : ($lead['contacted_via'] ?? 'call');
            $fields['first_action_at'] = $lead['first_action_at'] ?? $now;
            $fields['taken_at'] = $lead['taken_at'] ?? $now;
            $fields['assigned_to'] = $lead['assigned_to'] ?? $actor->staffId;
        }

        if (Status::isClosed($status)) {
            $fields['closed_at'] = $now;
            $fields['next_action'] = null;
            $fields['next_action_at'] = null;
        } else {
            $fields['closed_at'] = null;
        }

        if (in_array($status, [Status::TALKED, Status::CONTRACT, Status::CHAT], true)) {
            $fields['next_action'] = null;
            $fields['next_action_at'] = null;
        }

        if ($status === Status::NEW) {
            $fields += ['attempt' => 0, 'reminded_level' => 0, 'next_action' => 'call', 'next_action_at' => $now, 'notified_at' => null];
        }

        $fields['refusal_reason'] = $status === Status::REFUSED ? ($reason !== null && $reason !== '' ? mb_substr($reason, 0, 150) : 'Не указана') : null;

        $lead = Leads::update((int) $lead['id'], $fields);
        Leads::event((int) $lead['id'], 'status', Status::label($status) . ($status === Status::REFUSED ? ': ' . $lead['refusal_reason'] : ''), $actor,
            ['status' => $status]);
        AdminChat::refresh($lead);

        return $lead;
    }

    public static function comment(array $lead, Actor $actor, string $text): void
    {
        Leads::event((int) $lead['id'], 'comment', mb_substr(trim($text), 0, 2000), $actor);
    }

    public static function scheduleCall(array $lead, DateTimeImmutable $at, Actor $actor, string $action = 'call'): array
    {
        $lead = Leads::update((int) $lead['id'], [
            'next_action' => $action,
            'next_action_at' => Clock::sql($at),
            'notified_at' => null,
        ]);
        $what = $action === 'message' ? 'Написать в MAX' : 'Звонок';
        Leads::event((int) $lead['id'], 'scheduled', "$what " . $at->format('d.m H:i'), $actor);

        return $lead;
    }

    public static function assign(array $lead, ?int $staffId, Actor $actor): array
    {
        $lead = Leads::update((int) $lead['id'], ['assigned_to' => $staffId]);
        Leads::event((int) $lead['id'], 'assigned', Leads::staffName($staffId) ?? 'не назначен', $actor);
        AdminChat::refresh($lead);

        return $lead;
    }

    /** Клиент выбрал удобное время или переписку кнопкой в MAX. */
    public static function clientChoice(array $lead, string $choice): array
    {
        $now = Clock::now();
        $schedule = Schedule::fromSettings();

        if ($choice === 'chat') {
            $at = $schedule->nextOpen($now);
            $lead = Leads::update((int) $lead['id'], [
                'channel' => 'max',
                'next_action' => 'message',
                'next_action_at' => Clock::sql($at),
                'notified_at' => null,
                'status' => Status::isClosed($lead['status']) ? Status::NEW : $lead['status'],
            ]);
            Leads::event((int) $lead['id'], 'client_choice', 'Клиент просит писать в MAX вместо звонка', Actor::client($lead['name']));
        } else {
            $at = $schedule->chosenTime($now, $choice);
            $fields = ['next_action' => 'call', 'next_action_at' => Clock::sql($at), 'notified_at' => null];

            if (in_array($choice, Schedule::SLOTS, true)) {
                $fields['contact_time'] = $choice;
            }

            if (Status::isClosed($lead['status'])) {
                $fields['status'] = Status::NEW;
            }

            $lead = Leads::update((int) $lead['id'], $fields);
            Leads::event((int) $lead['id'], 'client_choice', 'Клиент просит позвонить ' . $schedule->describe($at, $now), Actor::client($lead['name']));
        }

        AdminChat::post(sprintf('🕑 №%d %s: %s.', $lead['id'], $lead['name'],
            $choice === 'chat' ? 'просит писать в MAX' : 'просит позвонить ' . $schedule->describe($at, $now)), (int) $lead['id']);
        AdminChat::refresh($lead);

        return ['lead' => $lead, 'at' => $at];
    }

    /** Сообщение клиента из MAX. */
    public static function maxIncoming(array $lead, string $text): void
    {
        Leads::event((int) $lead['id'], 'max_in', mb_substr($text, 0, 4000), Actor::client($lead['name']));
        AdminChat::post("💬 №{$lead['id']} {$lead['name']}: " . mb_substr($text, 0, 1500)
            . "\n\nОтветьте на это сообщение — ответ уйдёт клиенту.", (int) $lead['id']);
    }

    /** Ответ клиенту в MAX от имени бота (из админки или ответом в чате администраторов). */
    public static function maxReply(array $lead, Actor $actor, string $text): bool
    {
        if (($lead['max_user_id'] ?? '') === '') {
            return false;
        }

        $mid = Max::api()->send(['user_id' => $lead['max_user_id']], $text);

        if ($mid === null) {
            return false;
        }

        Leads::event((int) $lead['id'], 'max_out', mb_substr($text, 0, 4000), $actor);
        $fields = [
            'contacted_at' => $lead['contacted_at'] ?? Clock::sql(),
            'contacted_via' => $lead['contacted_via'] ?? 'max',
            'first_action_at' => $lead['first_action_at'] ?? Clock::sql(),
            'taken_at' => $lead['taken_at'] ?? Clock::sql(),
            'assigned_to' => $lead['assigned_to'] ?? $actor->staffId,
        ];

        if (in_array($lead['status'], [Status::NEW, Status::IN_WORK, Status::NO_ANSWER], true)) {
            $fields += ['status' => Status::CHAT, 'next_action' => null, 'next_action_at' => null];
        }

        $lead = Leads::update((int) $lead['id'], $fields);
        AdminChat::refresh($lead);

        return true;
    }

    /** Клиент открыл бота по ссылке из заявки. */
    public static function linkMax(array $lead, string $userId, ?string $chatId, string $maxName = ''): array
    {
        $fields = [
            'max_user_id' => $userId,
            'max_chat_id' => $chatId,
            'max_started_at' => $lead['max_started_at'] ?? Clock::sql(),
        ];

        // Заявка без имени: берём имя из профиля MAX.
        if (!Leads::hasName($lead['name']) && trim($maxName) !== '') {
            $fields['name'] = mb_substr(trim($maxName), 0, 100);
            $fields['search'] = Leads::searchString($fields['name'], $lead['phone'], $lead['service']);
        }

        $lead = Leads::update((int) $lead['id'], $fields);
        Leads::event((int) $lead['id'], 'max_started', null, Actor::client($lead['name']));

        return $lead;
    }

    /** Последнее сообщение «Заявка ещё актуальна?» (планировщик). */
    public static function sendFinal(array $lead): array
    {
        $schedule = Schedule::fromSettings();

        $sent = true;
        if (($lead['max_user_id'] ?? '') !== '') {
            ClientMessages::finalQuestion($lead);
        } else {
            $sent = self::sms($lead, 'final', Texts::render(Settings::get('sms_final')));
        }

        Leads::event((int) $lead['id'], 'final', $sent ? 'Клиенту отправлено «Заявка ещё актуальна?»'
            : 'Последняя попытка: сообщение клиенту не отправлено (нет MAX и SMS) — позвоните ещё раз', Actor::system());

        return Leads::update((int) $lead['id'], [
            'next_action' => 'give_up',
            'next_action_at' => Clock::sql($schedule->giveUpAt(Clock::now())),
        ]);
    }

    /** Отправляет SMS по заявке и пишет его в историю. */
    public static function sms(array $lead, string $kind, string $text): bool
    {
        // SMS не подключены или номер городской — ничего не отправляем и не пишем в историю «SMS отправлено»
        if (!Sms::enabled() || !Phone::isMobile((string) $lead['phone'])) {
            return false;
        }

        $result = Sms::send($lead['phone'], $text, $kind, (int) $lead['id']);
        Leads::event((int) $lead['id'], $result['ok'] ? 'sms' : 'sms_failed',
            $result['ok'] ? $text : $text . ' — ' . $result['error'], Actor::system());

        return $result['ok'];
    }

    private static function sendAcceptedSms(array $lead, Schedule $schedule, DateTimeImmutable $callAt, DateTimeImmutable $now): void
    {
        if (Sms::sentRecently($lead['phone'], ['accepted', 'max_invite'])) {
            return;
        }

        if ($lead['channel'] === 'max' && Max::enabled()) {
            self::sms($lead, 'max_invite', Texts::render(Settings::get('sms_max_invite'), ['max_link' => Max::shortLink()]));

            return;
        }

        self::sms($lead, 'accepted', Texts::smsAccepted($schedule, $callAt, $now));
    }

    private static function sendMissedMessage(array $lead): void
    {
        if (($lead['max_user_id'] ?? '') !== '') {
            ClientMessages::missedCall($lead);

            return;
        }

        if (!Sms::sentRecently($lead['phone'], ['missed'])) {
            self::sms($lead, 'missed', Texts::render(Settings::get('sms_missed')));
        }
    }

    private static function describeRequest(array $data): string
    {
        $parts = [];

        if (($data['service'] ?? '') !== '') {
            $parts[] = $data['service'];
        }

        $parts[] = 'удобно ' . (Source::CONTACT_TIMES[$data['contact_time']] ?? 'сейчас');
        $parts[] = 'связь: ' . (Source::CHANNELS[$data['channel']] ?? 'звонок');

        if (($data['page_url'] ?? '') !== '') {
            $parts[] = 'страница ' . $data['page_url'];
        }

        return implode(', ', $parts) . '.';
    }

    private static function requestData(array $data): array
    {
        return array_filter(array_intersect_key($data, array_flip([
            'service', 'page_url', 'contact_time', 'channel', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'yclid',
        ])), static fn ($v): bool => $v !== null && $v !== '');
    }
}
