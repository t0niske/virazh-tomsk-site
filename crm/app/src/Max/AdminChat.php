<?php

declare(strict_types=1);

namespace App\Max;

use App\Leads\Clock;
use App\Leads\Leads;
use App\Leads\Log;
use App\Leads\Phone;
use App\Leads\Schedule;
use App\Leads\Settings;
use App\Leads\Source;
use App\Leads\Status;
use App\Support\Db;
use App\Support\Env;
use DateTimeImmutable;

/** Рабочий чат «Заявки» в MAX: карточки заявок с кнопками, напоминания, сообщения руководителю. */
final class AdminChat
{
    private const DAYS = [1 => 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];

    /** Карточка новой (или снова открытой) заявки. */
    public static function postCard(array $lead, bool $notify = true): void
    {
        $mid = self::send(self::cardText($lead, '🔔 Новая заявка'), self::keyboard($lead), $notify);

        if ($mid !== null) {
            Leads::update((int) $lead['id'], ['card_mid' => $mid]);
            self::remember($mid, (int) $lead['id']);
        }
    }

    /** Обновляет карточку после любого изменения — в чате и админке одно и то же. */
    public static function refresh(array $lead): void
    {
        if (($lead['card_mid'] ?? '') === '') {
            return;
        }

        Max::api()->edit($lead['card_mid'], self::cardText($lead, '🔔 Заявка'), self::keyboard($lead));
    }

    /** Сообщение в чат. Если указан $leadId, ответ на это сообщение в чате уйдёт клиенту. */
    public static function post(string $text, ?int $leadId = null, ?array $keyboard = null, bool $notify = true): ?string
    {
        $mid = self::send($text, $keyboard, $notify);

        if ($mid !== null && $leadId !== null) {
            self::remember($mid, $leadId);
        }

        return $mid;
    }

    /** Личное сообщение руководителям, подключённым к боту. Если таких нет — в общий чат. */
    public static function toManagers(string $text): void
    {
        $ids = Db::pdo()->query("SELECT max_user_id FROM staff WHERE role = 'manager' AND active = 1 AND max_user_id IS NOT NULL AND max_user_id <> ''")
            ->fetchAll(\PDO::FETCH_COLUMN);

        if ($ids === []) {
            self::post("Руководителю: $text");

            return;
        }

        foreach ($ids as $userId) {
            Max::api()->send(['user_id' => $userId], $text);
        }
    }

    public static function leadIdByMessage(string $mid): ?int
    {
        $stmt = Db::pdo()->prepare('SELECT lead_id FROM max_messages WHERE mid = ?');
        $stmt->execute([$mid]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    public static function cardText(array $lead, string $title): string
    {
        $created = new DateTimeImmutable($lead['created_at']);
        $when = $created->format('Y-m-d') === Clock::now()->format('Y-m-d')
            ? $created->format('H:i')
            : self::DAYS[(int) $created->format('N')] . ' ' . $created->format('d.m H:i');

        $lines = [
            "$title №{$lead['id']} · $when",
            '',
            $lead['name'] . ', ' . Phone::format($lead['phone']),
        ];

        if (($lead['suspect'] ?? null) !== null) {
            array_splice($lines, 1, 0, ["⚠️ Проверьте, не робот ли: {$lead['suspect']}." . (\App\Sms\Sms::enabled() ? ' SMS клиенту не отправлено.' : '')]);
        }

        if (($lead['service'] ?? '') !== '') {
            $lines[] = $lead['service'];
        }

        $lines[] = 'Удобно: ' . (Source::CONTACT_TIMES[$lead['contact_time']] ?? 'сейчас')
            . ' · Связь: ' . (Source::CHANNELS[$lead['channel']] ?? 'звонок')
            . (($lead['max_user_id'] ?? '') !== '' ? ' · в MAX' : '');
        $lines[] = 'Откуда: ' . $lead['source'];

        if ((int) $lead['request_count'] > 1) {
            $lines[] = 'Заявок с этого номера: ' . $lead['request_count'];
        }

        $lines[] = '';
        $lines[] = self::statusLine($lead);
        $lines[] = rtrim((string) Env::get('APP_URL', ''), '/') . '/crm/lead/' . $lead['id'];

        return implode("\n", $lines);
    }

    public static function statusLine(array $lead): string
    {
        $status = $lead['status'];
        $line = 'Статус: ' . Status::label($status);
        $who = Leads::staffName($lead['assigned_to'] !== null ? (int) $lead['assigned_to'] : null);

        if ($status === Status::NO_ANSWER) {
            $line .= ' · звонков: ' . $lead['attempt'];
        }

        if ($status === Status::REFUSED && ($lead['refusal_reason'] ?? '') !== '') {
            $line .= ' · ' . $lead['refusal_reason'];
        }

        if ($status === Status::CONTRACT && ($lead['contract_note'] ?? '') !== '') {
            $line .= ' · ждём: ' . $lead['contract_note'];
        }

        if ($who !== null) {
            $line .= ' · ' . $who;
        }

        if ($status === Status::NO_ANSWER && ($lead['next_action'] ?? null) === 'give_up') {
            $line .= "\nЗвонки закончились, клиенту отправлено «не дозвонились» — ждём, что перезвонит";
        }

        if (!Status::isClosed($status) && ($lead['next_action_at'] ?? null) !== null && $lead['next_action'] !== 'give_up') {
            $schedule = Schedule::fromSettings();
            $at = new DateTimeImmutable($lead['next_action_at']);

            if ($at > Clock::now()) {
                $line .= "\n" . ($lead['next_action'] === 'final_message' ? 'Последнее сообщение клиенту ' : 'Следующий звонок ')
                    . $schedule->describe($at, Clock::now());
            }
        }

        return $line;
    }

    /** Кнопки карточки зависят от статуса. Нажатия обрабатывает Bot::adminCallback(). */
    public static function keyboard(array $lead): ?array
    {
        $id = $lead['id'];

        if (Status::isClosed($lead['status'])) {
            return null;
        }

        $first = $lead['status'] === Status::NEW
            ? [Keyboard::callback('Беру', "a:take:$id"), Keyboard::callback('Не дозвонился', "a:miss:$id")]
            : [Keyboard::callback('Не дозвонился', "a:miss:$id"), Keyboard::callback('Думает', "a:talked:$id")];

        return [
            $first,
            [Keyboard::callback('Придёт на договор', "a:contract:$id")],
            [Keyboard::callback('Записан', "a:enrolled:$id"), Keyboard::callback('Отказ', "a:refuse:$id")],
        ];
    }

    /** Кнопки выбора причины отказа. */
    public static function refusalKeyboard(int $id): array
    {
        $rows = [];

        foreach (Settings::lines('refusal_reasons') as $i => $reason) {
            $rows[] = [Keyboard::callback($reason, "a:refuse:$id:$i")];
        }

        $rows[] = [Keyboard::callback('← Назад', "a:back:$id")];

        return $rows;
    }

    private static function send(string $text, ?array $keyboard, bool $notify): ?string
    {
        $chatId = Max::adminChatId();

        if ($chatId === '') {
            Log::write('max.log', 'Не задан чат администраторов (MAX_ADMIN_CHAT_ID), сообщение не отправлено: ' . mb_substr($text, 0, 200));

            return null;
        }

        return Max::api()->send(['chat_id' => $chatId], $text, $keyboard, $notify);
    }

    private static function remember(string $mid, int $leadId): void
    {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM max_messages WHERE mid = ?');
        $stmt->execute([$mid]);

        if ((int) $stmt->fetchColumn() === 0) {
            $pdo->prepare('INSERT INTO max_messages (mid, lead_id, created_at) VALUES (?, ?, ?)')->execute([$mid, $leadId, Clock::sql()]);
        }
    }
}
