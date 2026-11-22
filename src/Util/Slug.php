<?php

namespace App\Util;

use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * URL-Slugs. Umlaute werden unabhängig von ext-intl immer gleich umgeschrieben (ä → ae),
 * damit Links auf jedem Server identisch sind.
 */
final class Slug
{
    private const MAP = [
        'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss',
        '½' => '1-2', '¼' => '1-4', '¾' => '3-4',
    ];

    private static ?AsciiSlugger $slugger = null;

    public static function make(?string $text, string $fallback = 'n-a'): string
    {
        $text = strtr(trim((string) $text), self::MAP);
        if ('' === $text) {
            return $fallback;
        }

        self::$slugger ??= new AsciiSlugger('en');
        $slug = self::$slugger->slug($text)->lower()->toString();
        $slug = substr($slug, 0, 80);

        return '' === trim($slug, '-') ? $fallback : rtrim($slug, '-');
    }
}
