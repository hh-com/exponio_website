<?php

namespace App\Ai;

/**
 * Liest Token-Zahlen aus dem "usage"-Teil einer Antwort; jeder Anbieter nennt die Felder anders.
 */
final class AiUsage
{
    /**
     * Summe der genannten Felder; null, wenn keines davon eine Zahl ist.
     *
     * @param array<mixed> $usage
     */
    public static function sum(array $usage, string ...$fields): ?int
    {
        $total = null;
        foreach ($fields as $field) {
            $value = $usage[$field] ?? null;
            if (\is_int($value) || (\is_string($value) && ctype_digit($value))) {
                $total = ($total ?? 0) + (int) $value;
            }
        }

        return $total;
    }
}
