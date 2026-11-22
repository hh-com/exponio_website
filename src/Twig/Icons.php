<?php

namespace App\Twig;

/**
 * Schlanker Inline-SVG-Iconsatz (24×24, Strich). Gleicher Satz in assets/lib/icons.ts für Vue.
 */
final class Icons
{
    public const PATHS = [
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
        'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'x' => '<path d="M18 6 6 18M6 6l12 12"/>',
        'heart' => '<path d="M19 14c1.5-1.5 3-3.2 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.8 0-3 .5-4.5 2-1.5-1.5-2.7-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4 3 5.5l7 7Z"/>',
        'chevron-right' => '<path d="m9 18 6-6-6-6"/>',
        'chevron-left' => '<path d="m15 18-6-6 6-6"/>',
        'chevron-down' => '<path d="m6 9 6 6 6-6"/>',
        'arrow-right' => '<path d="M5 12h14M13 5l7 7-7 7"/>',
        'filter' => '<path d="M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3M1 14h6M9 8h6M17 16h6"/>',
        'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
        'stamp' => '<rect x="4" y="3" width="16" height="18" rx="1" stroke-dasharray="2 2.2"/><rect x="7.5" y="6.5" width="9" height="9" rx=".5"/><path d="m8 14 3-3 2 2 3.5-3.5M8 18.5h8"/>',
        'envelope' => '<rect x="2.5" y="5" width="19" height="14" rx="2"/><path d="m2.5 7.5 8 5.5h3l8-5.5"/><rect x="16" y="8" width="3" height="3.4" rx=".3"/>',
        'coin' => '<circle cx="12" cy="12" r="8.5"/><circle cx="12" cy="12" r="5.5"/><path d="M12 9.5v5"/>',
        'banknote' => '<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.6"/><path d="M6 12h.01M18 12h.01"/>',
        'postcard' => '<rect x="2.5" y="5" width="19" height="14" rx="2"/><path d="M12 8v8M15 8.5h3.5V12H15zM5.5 9h3.5M5.5 12h3.5M5.5 15h3.5"/>',
        'box' => '<path d="M21 8 12 3 3 8v8l9 5 9-5V8Z"/><path d="m3 8 9 5 9-5M12 13v8"/>',
        'globe' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
        'tag' => '<path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8Z"/><circle cx="7.5" cy="7.5" r="1.5"/>',
        'grid' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'zoom-in' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5M11 8v6M8 11h6"/>',
        'check' => '<path d="M20 6 9 17l-5-5"/>',
        'info' => '<circle cx="12" cy="12" r="9"/><path d="M12 16v-4M12 8h.01"/>',
        'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
        'send' => '<path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/>',
        'trash' => '<path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6"/>',
        'sparkle' => '<path d="M12 3v3M12 18v3M3 12h3M18 12h3M5.6 5.6l2.1 2.1M16.3 16.3l2.1 2.1M5.6 18.4l2.1-2.1M16.3 7.7l2.1-2.1"/>',
    ];

    public static function svg(string $name, string $class = 'size-5'): string
    {
        $paths = self::PATHS[$name] ?? self::PATHS['box'];

        return sprintf(
            '<svg class="%s" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">%s</svg>',
            htmlspecialchars($class),
            $paths,
        );
    }
}
