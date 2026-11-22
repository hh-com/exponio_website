<?php

namespace App\Cache;

use Symfony\Component\HttpKernel\CacheClearer\CacheClearerInterface;

/**
 * Läuft bei "php bin/console cache:clear" (also bei jedem Deployment):
 * leert den Seiten-Cache und erhöht die Inhaltsversion.
 *
 * Sonst würden gecachte Seiten nach einem Update noch auf alte, inzwischen gelöschte
 * JS-/CSS-Dateien verweisen (deren Namen enthalten einen Hash), und Browser bekämen für
 * veraltete Seiten weiter „304 Not Modified“.
 */
final class DeployCacheClearer implements CacheClearerInterface
{
    public function __construct(private readonly ContentVersion $version)
    {
    }

    public function clear(string $cacheDir): void
    {
        $this->version->bump();
        $this->version->clearPageCache();
    }
}
