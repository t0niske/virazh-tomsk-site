<?php

declare(strict_types=1);

namespace App\Leads;

/** Статусы заявки. */
final class Status
{
    public const NEW = 'new';
    public const IN_WORK = 'in_work';
    public const NO_ANSWER = 'no_answer';
    public const TALKED = 'talked';
    /** Договорились: клиент придёт в офис заключать договор. Заявка открыта, пока не «Записан». */
    public const CONTRACT = 'contract';
    public const CHAT = 'chat';
    public const ENROLLED = 'enrolled';
    public const REFUSED = 'refused';
    public const UNREACHABLE = 'unreachable';
    public const JUNK = 'junk';

    public const LABELS = [
        self::NEW => 'Новая',
        self::IN_WORK => 'В работе',
        self::NO_ANSWER => 'Недозвон',
        self::TALKED => 'Думает',
        self::CONTRACT => 'Придёт на договор',
        self::CHAT => 'Переписка',
        self::ENROLLED => 'Записан',
        self::REFUSED => 'Отказ',
        self::UNREACHABLE => 'Не дозвонились',
        self::JUNK => 'Мусор',
    ];

    /** Заявка закрыта: по ней больше не звонят и не напоминают. */
    public const CLOSED = [self::ENROLLED, self::REFUSED, self::UNREACHABLE, self::JUNK];

    /** Статусы, после которых считается, что с клиентом связались. */
    public const CONTACTED = [self::TALKED, self::CONTRACT, self::CHAT, self::ENROLLED, self::REFUSED];

    public static function label(string $status): string
    {
        return self::LABELS[$status] ?? $status;
    }

    public static function isClosed(string $status): bool
    {
        return in_array($status, self::CLOSED, true);
    }
}
