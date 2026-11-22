<?php

namespace App\Translation;

/**
 * Prüft, ob eine Übersetzung alle technischen Bestandteile des Originals behalten hat:
 * Platzhalter (%name%, {0}), Mehrzahl-Bereiche (]1,Inf[), HTML-Tags und die Anzahl der "|".
 * Fehlt etwas, wird die Übersetzung verworfen und beim nächsten Lauf erneut versucht.
 */
final class PlaceholderGuard
{
    private const TOKEN = '/%[A-Za-z0-9_]+%|\{[^{}]*\}|[\]\[]-?\d+,\s*(?:-?Inf|-?\d+)[\]\[]|<\/?[a-zA-Z][a-zA-Z0-9]*/';

    public static function isValid(string $source, string $translation): bool
    {
        if ('' === trim($translation)) {
            return false;
        }
        if (substr_count($source, '|') !== substr_count($translation, '|')) {
            return false;
        }

        return self::tokens($source) === self::tokens($translation);
    }

    /** @return list<string> */
    private static function tokens(string $text): array
    {
        preg_match_all(self::TOKEN, $text, $matches);
        $tokens = array_map(static fn (string $t): string => strtolower((string) preg_replace('/\s+/', '', $t)), $matches[0]);
        sort($tokens);

        return $tokens;
    }
}
