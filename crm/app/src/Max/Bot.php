<?php

declare(strict_types=1);

namespace App\Max;

use App\Leads\Actor;
use App\Leads\Audit;
use App\Leads\Clock;
use App\Leads\LeadForm;
use App\Leads\Leads;
use App\Leads\LeadService;
use App\Leads\Log;
use App\Leads\Phone;
use App\Leads\Schedule;
use App\Leads\Settings;
use App\Leads\Status;
use App\Leads\Texts;
use App\Support\Db;

/**
 * Обработка событий от MAX (вебхук): клиент открыл бота, написал, нажал кнопку;
 * администратор нажал кнопку в карточке или ответил на сообщение в чате.
 */
final class Bot
{
    private const FAQ = ['prices' => 'faq_prices', 'addresses' => 'faq_addresses', 'groups' => 'faq_groups'];

    public static function handle(array $update): void
    {
        match ($update['update_type'] ?? '') {
            'bot_started' => self::onStart($update),
            'message_created' => self::onMessage($update['message'] ?? []),
            'message_callback' => self::onCallback($update['callback'] ?? [], $update['message'] ?? null),
            'bot_added' => self::onAdded($update),
            default => null,
        };
    }

    private static function onStart(array $update): void
    {
        $user = $update['user'] ?? [];
        $userId = (string) ($user['user_id'] ?? '');

        if ($userId === '') {
            return;
        }

        $chatId = isset($update['chat_id']) ? (string) $update['chat_id'] : null;
        $target = Max::parsePayload($update['payload'] ?? null);

        if ($target !== null && $target['type'] === 'staff') {
            self::linkStaff($target['id'], $userId);

            return;
        }

        $lead = $target !== null ? Leads::find($target['id']) : Leads::findByMaxUser($userId);

        if ($lead === null) {
            self::greetUnknown($userId);

            return;
        }

        $firstTime = ($lead['max_user_id'] ?? '') !== $userId;
        $lead = LeadService::linkMax($lead, $userId, $chatId, self::userName($user));

        if ($firstTime) {
            AdminChat::post("📲 №{$lead['id']} {$lead['name']} открыл(а) бота MAX — можно писать в переписке.", (int) $lead['id']);
            AdminChat::refresh($lead);
        }

        ClientMessages::greeting($lead);
    }

    private static function onMessage(array $message): void
    {
        $sender = $message['sender'] ?? [];
        $recipient = $message['recipient'] ?? [];
        $text = trim((string) ($message['body']['text'] ?? ''));

        if (($sender['is_bot'] ?? false) === true || !isset($sender['user_id'])) {
            return;
        }

        $userId = (string) $sender['user_id'];
        $chatId = isset($recipient['chat_id']) ? (string) $recipient['chat_id'] : '';

        if (in_array(mb_strtolower($text), ['/id', '/chatid'], true)) {
            Max::api()->send($chatId !== '' ? ['chat_id' => $chatId] : ['user_id' => $userId],
                "ID чата: $chatId\nВаш ID пользователя: $userId");

            return;
        }

        if (($recipient['chat_type'] ?? '') !== 'dialog') {
            if ($chatId !== '' && $chatId === Max::adminChatId()) {
                self::adminReply($message, $userId, $text);
            }

            return;
        }

        $lead = Leads::findByMaxUser($userId);
        $contactPhone = self::contactPhone($message['body']['attachments'] ?? []);

        if ($contactPhone !== null) {
            self::onContact($userId, $chatId, self::userName($sender), $contactPhone);

            return;
        }

        if ($lead === null) {
            self::greetUnknown($userId);

            return;
        }

        if ($text !== '') {
            LeadService::maxIncoming($lead, $text);
        }
    }

    /** Ответ администратора в чате на сообщение бота уходит клиенту. */
    private static function adminReply(array $message, string $userId, string $text): void
    {
        $link = $message['link'] ?? null;

        if ($text === '' || ($link['type'] ?? '') !== 'reply') {
            return;
        }

        $leadId = AdminChat::leadIdByMessage((string) ($link['message']['mid'] ?? ''));
        $lead = $leadId !== null ? Leads::find($leadId) : null;

        if ($lead === null) {
            return;
        }

        $actor = self::actorFor($userId, self::userName($message['sender'] ?? []));

        if (!LeadService::maxReply($lead, $actor, $text)) {
            AdminChat::post("⚠️ №{$lead['id']}: клиент не открывал бота MAX, написать ему нельзя. Позвоните: " . Phone::format($lead['phone']));
        }
    }

    private static function onCallback(array $callback, ?array $message): void
    {
        $payload = (string) ($callback['payload'] ?? '');
        $callbackId = (string) ($callback['callback_id'] ?? '');
        $user = $callback['user'] ?? [];
        $userId = (string) ($user['user_id'] ?? '');

        if ($callbackId === '' || $userId === '') {
            return;
        }

        $parts = explode(':', $payload);

        if ($parts[0] === 'a') {
            $chatId = (string) ($message['recipient']['chat_id'] ?? '');
            $isStaff = self::staffByMaxUser($userId) !== null;

            if (!$isStaff && ($chatId === '' || $chatId !== Max::adminChatId())) {
                Max::api()->answer($callbackId, 'Нет доступа');

                return;
            }

            self::adminCallback($callbackId, $parts, self::actorFor($userId, self::userName($user)));

            return;
        }

        if ($parts[0] === 'c') {
            self::clientCallback($callbackId, $parts, $userId);
        }
    }

    /**
     * Кнопки карточки: a:take:148, a:miss:148, a:talked:148, a:contract:148, a:enrolled:148,
     * a:refuse:148 (показать причины), a:refuse:148:2 (причина №2), a:back:148.
     */
    public static function adminCallback(string $callbackId, array $parts, Actor $actor): void
    {
        $action = $parts[1] ?? '';
        $lead = Leads::find((int) ($parts[2] ?? 0));

        if ($lead === null) {
            Max::api()->answer($callbackId, 'Заявка не найдена — возможно, удалена');

            return;
        }

        if (Status::isClosed($lead['status']) && $action !== 'back') {
            Max::api()->answer($callbackId, 'Заявка уже закрыта: ' . Status::label($lead['status']), self::cardMessage($lead));

            return;
        }

        $notice = null;

        switch ($action) {
            case 'take':
                if ($lead['status'] !== Status::NEW) {
                    $who = Leads::staffName($lead['assigned_to'] !== null ? (int) $lead['assigned_to'] : null);
                    $notice = 'Заявку уже взяли' . ($who ? ": $who" : '');
                    break;
                }
                $lead = LeadService::take($lead, $actor);
                $notice = 'Заявка ваша — звоните';
                break;

            case 'miss':
                $lead = LeadService::miss($lead, $actor);
                $notice = 'Записали недозвон (звонок ' . $lead['attempt'] . ')'
                    . ($lead['next_action'] === 'give_up' ? ' — клиенту ушло сообщение «не дозвонились»' : '');
                break;

            case 'talked':
                $lead = LeadService::setStatus($lead, Status::TALKED, $actor);
                $notice = 'Статус: думает';
                break;

            case 'contract':
                $lead = LeadService::setStatus($lead, Status::CONTRACT, $actor);
                $notice = 'Статус: придёт на договор';
                break;

            case 'enrolled':
                $lead = LeadService::setStatus($lead, Status::ENROLLED, $actor);
                $notice = 'Записан 🎉';
                break;

            case 'refuse':
                if (!isset($parts[3])) {
                    Max::api()->answer($callbackId, null, [
                        'text' => AdminChat::cardText($lead, '🔔 Заявка') . "\n\nПричина отказа?",
                        'keyboard' => AdminChat::refusalKeyboard((int) $lead['id']),
                    ]);

                    return;
                }
                $reasons = Settings::lines('refusal_reasons');
                $lead = LeadService::setStatus($lead, Status::REFUSED, $actor, $reasons[(int) $parts[3]] ?? 'Другое');
                $notice = 'Отказ записан';
                break;

            case 'back':
                break;

            default:
                $notice = 'Неизвестная кнопка';
        }

        Audit::log($actor, 'max_button', (int) $lead['id'], "Кнопка в MAX: $action");
        Max::api()->answer($callbackId, $notice, self::cardMessage($lead));
    }

    /** Кнопки клиента: c:faq:prices, c:time:148:evening, c:actual:148:yes. */
    public static function clientCallback(string $callbackId, array $parts, string $userId): void
    {
        $kind = $parts[1] ?? '';

        if ($kind === 'faq') {
            $text = Texts::render(Settings::get(self::FAQ[$parts[2] ?? ''] ?? 'faq_prices'),
                ['work_time' => Schedule::fromSettings()->workTimeText()]);
            Max::api()->answer($callbackId);
            $lead = Leads::findByMaxUser($userId);
            $lead !== null ? ClientMessages::send($lead, $text) : Max::api()->send(['user_id' => $userId], $text);

            return;
        }

        $lead = Leads::find((int) ($parts[2] ?? 0));

        if ($lead === null || (string) $lead['max_user_id'] !== $userId) {
            Max::api()->answer($callbackId, 'Заявка не найдена');

            return;
        }

        $choice = $parts[3] ?? '';
        $schedule = Schedule::fromSettings();

        if ($kind === 'actual' && $choice === 'no') {
            LeadService::setStatus($lead, Status::REFUSED, Actor::client($lead['name']), 'Уже не нужно (ответ в MAX)');
            Max::api()->answer($callbackId, 'Спасибо за ответ');
            ClientMessages::send($lead, Texts::render(Settings::get('bot_actual_no')));

            return;
        }

        if ($kind === 'actual') {
            $choice = 'now';
        }

        if (!in_array($choice, ['now', 'hour', 'tomorrow', 'chat', ...Schedule::SLOTS], true)) {
            Max::api()->answer($callbackId);

            return;
        }

        $result = LeadService::clientChoice($lead, $choice);
        $when = $schedule->describe($result['at'], Clock::now());
        $template = match (true) {
            $choice === 'chat' => 'bot_chat_ok',
            $kind === 'actual' => 'bot_actual_yes',
            default => 'bot_time_ok',
        };

        Max::api()->answer($callbackId, 'Готово');
        ClientMessages::send($result['lead'], Texts::render(Settings::get($template), ['when' => $when]));
    }

    /** Клиент поделился номером: находим его заявку или создаём новую из MAX. */
    private static function onContact(string $userId, string $chatId, string $name, string $phone): void
    {
        $since = Clock::sql(Clock::now()->modify('-' . max(1, Settings::int('dedupe_days')) . ' days'));
        $lead = Leads::findRecentByPhone($phone, $since);

        if ($lead === null) {
            $data = array_merge(LeadForm::empty(), [
                'name' => mb_substr($name !== '' ? $name : 'Клиент из MAX', 0, 100),
                'phone' => $phone,
                'phone_raw' => $phone,
                'channel' => 'max',
                'utm_source' => 'max',
            ]);
            $lead = LeadService::accept($data, sendSms: false)['lead'];
        }

        $lead = LeadService::linkMax($lead, $userId, $chatId !== '' ? $chatId : null, $name);
        AdminChat::refresh($lead);
        ClientMessages::greeting($lead);
    }

    private static function greetUnknown(string $userId): void
    {
        $schedule = Schedule::fromSettings();
        $now = Clock::now();
        $when = $schedule->isOpen($now)
            ? 'в течение ' . Settings::get('answer_minutes') . ' минут'
            : $schedule->describe($schedule->nextOpen($now), $now);

        Max::api()->send(['user_id' => $userId], Texts::render(Settings::get('bot_greeting_unknown'), ['when' => $when]), [
            [Keyboard::contact('Поделиться номером')],
            ...Keyboard::faq(),
        ]);
    }

    private static function linkStaff(int $staffId, string $userId): void
    {
        $stmt = Db::pdo()->prepare('SELECT * FROM staff WHERE id = ? AND active = 1');
        $stmt->execute([$staffId]);
        $staff = $stmt->fetch();

        if (!$staff) {
            return;
        }

        Db::pdo()->prepare('UPDATE staff SET max_user_id = ? WHERE id = ?')->execute([$userId, $staffId]);
        Audit::log(Actor::staff($staff), 'max_linked', null, 'Привязан MAX');
        Max::api()->send(['user_id' => $userId], "Готово, {$staff['name']}! Бот подключён: сюда будут приходить личные уведомления и отчёты.");
    }

    private static function onAdded(array $update): void
    {
        $chatId = (string) ($update['chat_id'] ?? '');

        if ($chatId !== '') {
            Log::write('max.log', "Бота добавили в чат $chatId");
            Max::api()->send(['chat_id' => $chatId], "Бот автошколы подключён. ID этого чата: $chatId\nУкажите его в настройках админки, чтобы сюда приходили заявки.");
        }
    }

    private static function cardMessage(array $lead): array
    {
        return ['text' => AdminChat::cardText($lead, '🔔 Заявка'), 'keyboard' => AdminChat::keyboard($lead) ?? []];
    }

    private static function actorFor(string $maxUserId, string $name): Actor
    {
        $staff = self::staffByMaxUser($maxUserId);

        return $staff !== null ? Actor::staff($staff) : new Actor(null, $name !== '' ? $name : 'Участник чата MAX');
    }

    private static function staffByMaxUser(string $maxUserId): ?array
    {
        $stmt = Db::pdo()->prepare('SELECT * FROM staff WHERE max_user_id = ? AND active = 1');
        $stmt->execute([$maxUserId]);

        return $stmt->fetch() ?: null;
    }

    private static function userName(array $user): string
    {
        $name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));

        return $name !== '' ? $name : trim((string) ($user['name'] ?? ''));
    }

    /** Номер из вложения «контакт» (vCard: TEL…:79131234567). */
    private static function contactPhone(array $attachments): ?string
    {
        foreach ($attachments as $attachment) {
            if (($attachment['type'] ?? '') !== 'contact') {
                continue;
            }

            $vcf = (string) ($attachment['payload']['vcf_info'] ?? '');

            if (preg_match('/TEL[^:\r\n]*:([+\d\s()\-]+)/i', $vcf, $m)) {
                return Phone::normalize($m[1]);
            }
        }

        return null;
    }
}
