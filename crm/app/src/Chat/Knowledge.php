<?php

declare(strict_types=1);

namespace App\Chat;

use App\Support\Env;

/**
 * Справка для ИИ-помощника на сайте «Авто-Вираж»: правила (content/chat-rules.txt) и факты.
 *
 * Факты — та же база, по которой отвечает помощник на сайте без ИИ: assets/js/bot-kb.json выложенного сайта
 * (её собирает tools/build.pl из bot/answers.md и блоков «Частые вопросы» на страницах). Поэтому в чате те же цены,
 * адреса и сроки, что на сайте, и меняются они вместе с сайтом. Персональных данных в справке нет.
 *
 * Где сайт: константа VIRAZH_SITE (её задаёт crm.php в корне сайта), иначе переменная окружения VIRAZH_SITE.
 * Справка одинакова в течение дня — DeepSeek кэширует её начало, повторные запросы дешёвые.
 */
final class Knowledge
{
    private static ?string $kbFile = null;

    /** Для тестов: свой файл базы вместо сайта. null — снова с сайта. */
    public static function useKb(?string $file): void
    {
        self::$kbFile = $file;
    }

    /** Текст справки или null, если базы сайта нет (тогда чат не отвечает, на сайте работают готовые ответы). */
    public static function systemPrompt(): ?string
    {
        $items = self::items();

        if ($items === []) {
            return null;
        }

        $rules = trim((string) @file_get_contents(APP_ROOT . '/content/chat-rules.txt'));
        $facts = [];

        foreach ($items as $it) {
            $q = self::plain((string) ($it['q'] ?? ''));
            $a = self::plain((string) ($it['a'] ?? ''));

            if ($q !== '' && $a !== '') {
                $facts[] = "В: $q\nО: $a";
            }
        }

        return $rules . "\n\nСегодня " . date('d.m.Y') . ".\n\nСПРАВКА (сведения с сайта virazh-tomsk.ru)\n\n" . implode("\n\n", $facts);
    }

    /** @return list<array{q?: string, a?: string}> */
    private static function items(): array
    {
        $file = self::$kbFile ?? self::siteRoot() . '/assets/js/bot-kb.json';
        $json = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($json['items'] ?? null) ? array_values($json['items']) : [];
    }

    private static function siteRoot(): string
    {
        return rtrim(defined('VIRAZH_SITE') ? (string) constant('VIRAZH_SITE') : (string) Env::get('VIRAZH_SITE', ''), '/');
    }

    /** HTML ответа → текст: ссылки на страницы сайта — [текст](/адрес), кнопки и разметка — прочь. */
    public static function plain(string $html): string
    {
        $html = preg_replace('~<button\b[^>]*>.*?</button>~su', '', $html) ?? $html;
        $html = preg_replace_callback('~<a\b[^>]*href="(/[^"#]*)"[^>]*>(.*?)</a>~su', static fn (array $m): string => '[' . strip_tags($m[2]) . '](' . $m[1] . ')', $html) ?? $html;
        $html = preg_replace('~<a\b[^>]*>(.*?)</a>~su', '$1', $html) ?? $html;
        $html = preg_replace('~</p>\s*<p>|<br\s*/?>~u', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\u{00A0}", "\u{202F}", "\u{2009}", "\u{00AD}"], [' ', ' ', ' ', ''], $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;

        return trim(preg_replace('/\s*\n\s*/u', "\n", $text) ?? $text);
    }
}
