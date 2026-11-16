<?php

declare(strict_types=1);

namespace App\Chat;

use App\Leads\Clock;
use App\Leads\Leads;
use App\Leads\Log;
use App\Leads\Notify\Telegram;
use App\Leads\Phone;
use App\Leads\RateLimiter;
use App\Leads\Settings;
use App\Support\Db;
use App\Support\Env;
use DateTimeImmutable;

/**
 * Чат-бот поддержки на сайте (docs/chat-api.md): лимиты, вырезание персональных данных,
 * вызов DeepSeek, признак «нужен оператор», хранение переписки, связь с заявкой.
 */
final class Chat
{
    public const OPERATOR_TAG = '[ОПЕРАТОР]';
    public const MAX_LENGTH = 500;
    public const HISTORY = 10;
    public const PER_CHAT = 20;
    public const PER_IP_5MIN = 10;
    public const PER_IP_DAY = 40;

    private static ?object $model = null;
    private static bool $modelSet = false;

    /** Заглушка вместо DeepSeek в тестах: объект с методом complete(array $messages): array. null — снова из .env. */
    public static function useModel(?object $model, bool $set = true): void
    {
        self::$model = $model;
        self::$modelSet = $set && $model !== null;
    }

    private static function model(): ?object
    {
        return self::$modelSet ? self::$model : DeepSeek::fromEnv();
    }

    /**
     * Обработать сообщение посетителя.
     *
     * @return array{0: int, 1: array{reply: string, operator: bool}}
     */
    public static function handle(string $chatId, string $message, string $ip): array
    {
        $model = self::model();

        // без ключа DeepSeek или без справки с сайта ИИ не отвечает — на сайте отвечают готовые ответы
        if ($model === null || self::systemPrompt() === '') {
            return [503, self::fallback('Чат сейчас не работает.')];
        }

        $message = trim($message);

        if (!preg_match('/^[A-Za-z0-9_-]{16,40}$/', $chatId) || $message === '') {
            return [422, ['reply' => 'Напишите вопрос текстом.', 'operator' => false]];
        }

        $message = Redactor::clean(mb_substr($message, 0, self::MAX_LENGTH));
        $chat = self::findOrCreate($chatId, $message);

        if (self::userMessages((int) $chat['id']) >= self::PER_CHAT
            || !RateLimiter::hit('chat_ip5:' . $ip, self::PER_IP_5MIN, 300)
            || !RateLimiter::hit('chat_ipday:' . $ip, self::PER_IP_DAY, 86400)
            || self::siteLimitReached()) {
            return [429, self::fallback('Сообщений слишком много.')];
        }

        self::add($chat, 'user', $message, false);
        $result = $model->complete(self::conversation((int) $chat['id']));

        if (!$result['ok']) {
            Log::error('Чат-бот: DeepSeek не ответил (HTTP ' . $result['status'] . '): ' . $result['error']);

            if ($result['status'] === 402) {
                self::warnNoBalance();
            }

            $reply = self::fallback('Не получилось ответить.');
            self::add($chat, 'system', $reply['reply'], true);

            return [200, $reply];
        }

        [$text, $operator] = self::splitOperator($result['text']);
        self::add($chat, 'bot', $text, $operator);

        if ($operator) {
            self::notifyOperator($chat);
        }

        return [200, ['reply' => $text, 'operator' => $operator]];
    }

    /** «…текст [ОПЕРАТОР]» → ['…текст', true]. */
    public static function splitOperator(string $text): array
    {
        $operator = mb_stripos($text, self::OPERATOR_TAG) !== false;
        $text = trim(str_ireplace(self::OPERATOR_TAG, '', $text));

        return [$text !== '' ? $text : 'Позову администратора — нажмите «Позвоните мне», и мы перезвоним.', $operator];
    }

    /**
     * Что уходит в DeepSeek: справка о школе и последние реплики этого чата (уже без персональных данных).
     * Никаких IP, chat_id, cookie и данных заявок.
     *
     * @return list<array{role: string, content: string}>
     */
    public static function conversation(int $chatRef): array
    {
        $stmt = Db::pdo()->prepare("SELECT role, text FROM chat_messages WHERE chat_ref = ? AND role IN ('user', 'bot') ORDER BY id DESC LIMIT " . self::HISTORY);
        $stmt->execute([$chatRef]);
        $history = array_reverse($stmt->fetchAll());

        $messages = [['role' => 'system', 'content' => self::systemPrompt()]];

        foreach ($history as $row) {
            $messages[] = ['role' => $row['role'] === 'bot' ? 'assistant' : 'user', 'content' => $row['text']];
        }

        return $messages;
    }

    /** Справка для ИИ: правила и факты с сайта (Knowledge); пустая строка — справки нет, чат не отвечает. */
    public static function systemPrompt(): string
    {
        return Knowledge::systemPrompt() ?? '';
    }

    /** Заявка из чата («Позвоните мне»): связать чат и заявку. */
    public static function linkLead(int $leadId, string $chatId): void
    {
        if (!preg_match('/^[A-Za-z0-9_-]{16,40}$/', $chatId)) {
            return;
        }

        Db::pdo()->prepare('UPDATE chats SET lead_id = ? WHERE chat_id = ? AND lead_id IS NULL')->execute([$leadId, $chatId]);
        $lead = Leads::find($leadId);

        if ($lead !== null && ($lead['chat_id'] ?? null) === null) {
            Leads::update($leadId, ['chat_id' => $chatId]);
        }
    }

    public static function find(int $id): ?array
    {
        $stmt = Db::pdo()->prepare('SELECT * FROM chats WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    public static function findByChatId(string $chatId): ?array
    {
        $stmt = Db::pdo()->prepare('SELECT * FROM chats WHERE chat_id = ?');
        $stmt->execute([$chatId]);

        return $stmt->fetch() ?: null;
    }

    /** @return list<array<string, mixed>> */
    public static function messages(int $chatRef): array
    {
        $stmt = Db::pdo()->prepare('SELECT * FROM chat_messages WHERE chat_ref = ? ORDER BY id');
        $stmt->execute([$chatRef]);

        return $stmt->fetchAll();
    }

    /** @return array{rows: list<array>, total: int} */
    public static function search(?DateTimeImmutable $from, ?DateTimeImmutable $to, bool $operatorOnly, int $page, int $perPage = 50): array
    {
        $where = ['1 = 1'];
        $params = [];

        if ($from !== null) {
            $where[] = 'created_at >= ?';
            $params[] = Clock::sql($from);
        }

        if ($to !== null) {
            $where[] = 'created_at < ?';
            $params[] = Clock::sql($to);
        }

        if ($operatorOnly) {
            $where[] = 'operator = 1';
        }

        $sql = implode(' AND ', $where);
        $count = Db::pdo()->prepare("SELECT COUNT(*) FROM chats WHERE $sql");
        $count->execute($params);
        $stmt = Db::pdo()->prepare("SELECT * FROM chats WHERE $sql ORDER BY last_at DESC, id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage));
        $stmt->execute($params);

        return ['rows' => $stmt->fetchAll(), 'total' => (int) $count->fetchColumn()];
    }

    /** Раз в сутки: чаты без заявки старше chats_retention_months (3 месяца). */
    public static function cleanup(DateTimeImmutable $now): int
    {
        $border = Clock::sql($now->modify('-' . max(1, Settings::int('chats_retention_months')) . ' months'));
        $stmt = Db::pdo()->prepare('DELETE FROM chats WHERE lead_id IS NULL AND last_at < ?');
        $stmt->execute([$border]);

        return $stmt->rowCount();
    }

    private static function findOrCreate(string $chatId, string $firstText): array
    {
        $chat = self::findByChatId($chatId);

        if ($chat !== null) {
            return $chat;
        }

        $now = Clock::sql();
        Db::pdo()->prepare('INSERT INTO chats (chat_id, created_at, last_at, first_text) VALUES (?, ?, ?, ?)')
            ->execute([$chatId, $now, $now, mb_substr($firstText, 0, 200)]);

        return self::findByChatId($chatId);
    }

    private static function add(array $chat, string $role, string $text, bool $operator): void
    {
        $now = Clock::sql();
        Db::pdo()->prepare('INSERT INTO chat_messages (chat_ref, created_at, role, text, operator) VALUES (?, ?, ?, ?, ?)')
            ->execute([$chat['id'], $now, $role, $text, $operator ? 1 : 0]);
        Db::pdo()->prepare('UPDATE chats SET messages = messages + 1, last_at = ?' . ($operator ? ', operator = 1' : '') . ' WHERE id = ?')
            ->execute([$now, $chat['id']]);
    }

    private static function userMessages(int $chatRef): int
    {
        $stmt = Db::pdo()->prepare("SELECT COUNT(*) FROM chat_messages WHERE chat_ref = ? AND role = 'user'");
        $stmt->execute([$chatRef]);

        return (int) $stmt->fetchColumn();
    }

    /** CHAT_DAILY_LIMIT ответов ИИ в сутки на весь сайт (с полуночи по Томску). */
    private static function siteLimitReached(): bool
    {
        $limit = (int) (Env::get('CHAT_DAILY_LIMIT') ?: 500);
        $stmt = Db::pdo()->prepare("SELECT COUNT(*) FROM chat_messages WHERE role = 'bot' AND created_at >= ?");
        $stmt->execute([Clock::sql(Clock::now()->setTime(0, 0))]);

        return (int) $stmt->fetchColumn() >= $limit;
    }

    /**
     * Ответ, когда ИИ не ответил. fallback: true — сайт вместо этого текста показывает свой готовый ответ.
     *
     * @return array{reply: string, operator: bool, fallback: bool}
     */
    private static function fallback(string $reason): array
    {
        return [
            'reply' => "$reason Позвоните нам: " . Phone::format(Settings::get('school_phone')) . ' или оставьте телефон — перезвоним.',
            'operator' => true,
            'fallback' => true,
        ];
    }

    /** DeepSeek вернул 402 — на балансе нет денег. Руководителю в Telegram, раз в сутки, без текстов чата. */
    private static function warnNoBalance(): void
    {
        $today = Clock::now()->format('Y-m-d');

        if (Settings::get('cron.deepseek_402') === $today) {
            return;
        }

        Settings::set('cron.deepseek_402', $today);

        try {
            Telegram::send('⚠️ ИИ-помощник на сайте Виража не отвечает: на балансе DeepSeek закончились деньги (ошибка 402). '
                . 'Пока посетителям отвечают готовые ответы помощника. Пополните баланс на platform.deepseek.com (ключ общий с сайтом «Сто дорог»).');
        } catch (\Throwable $e) {
            Log::error('Telegram о балансе DeepSeek', $e);
        }
    }

    /** «В чате нужен оператор» — один раз на чат, без текста и персональных данных. */
    private static function notifyOperator(array $chat): void
    {
        if ((int) $chat['operator'] === 1) {
            return;
        }

        try {
            Telegram::send("💬 В чате на сайте нужен оператор (чат №{$chat['id']}). Если посетитель оставит телефон, придёт заявка.\n"
                . 'Открыть: ' . rtrim((string) Env::get('APP_URL', ''), '/') . '/crm/chat/' . $chat['id']);
        } catch (\Throwable $e) {
            Log::error('Telegram о чате', $e);
        }
    }
}
