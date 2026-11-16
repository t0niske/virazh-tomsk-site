<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Запись значений в .env на месте: строка KEY=… заменяется, новой — дописывается в конец.
 * Остальные строки и комментарии не трогаются, права файла — 600 (там ключи и токены).
 */
final class EnvFile
{
    /** @param array<string, string> $values */
    public static function set(string $path, array $values): void
    {
        $text = is_file($path) ? (string) file_get_contents($path) : '';

        foreach ($values as $key => $value) {
            if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $key) || preg_match('/[\r\n]/', $value)) {
                throw new \InvalidArgumentException("Недопустимое значение для $key");
            }

            $line = $key . '=' . $value;
            $count = 0;
            $text = preg_replace_callback('/^' . $key . '=.*$/m', static fn (): string => $line, $text, 1, $count) ?? $text;

            if ($count === 0) {
                $text = rtrim($text, "\n") . ($text === '' ? '' : "\n") . $line . "\n";
            }
        }

        $tmp = $path . '.tmp';
        file_put_contents($tmp, $text);
        @chmod($tmp, 0600);
        rename($tmp, $path);
    }
}
