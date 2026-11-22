<?php

namespace App\Cache;

use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * Anwendungs-Cache für teure Berechnungen (Zähler, Facetten, Sitemaps, Sprachlisten …).
 *
 * Alle Einträge tragen das Tag "content" und werden bei jeder Inhaltsänderung gemeinsam
 * gelöscht (siehe ContentVersion). Gespeichert werden nur einfache Werte (Arrays, Strings),
 * nie Doctrine-Objekte.
 */
final class ContentCache
{
    public const TAG = 'content';
    private const TTL = 86400;

    public function __construct(private readonly TagAwareCacheInterface $contentCache)
    {
    }

    /**
     * @template T
     *
     * @param callable(): T $compute
     *
     * @return T
     */
    public function get(string $key, callable $compute): mixed
    {
        return $this->contentCache->get(self::safeKey($key), static function (ItemInterface $item) use ($compute): mixed {
            $item->tag(self::TAG);
            $item->expiresAfter(self::TTL);

            return $compute();
        });
    }

    public function invalidate(): void
    {
        $this->contentCache->invalidateTags([self::TAG]);
    }

    /** PSR-6 erlaubt nicht alle Zeichen in Schlüsseln */
    private static function safeKey(string $key): string
    {
        return preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $key) ? $key : 'k'.sha1($key);
    }
}
