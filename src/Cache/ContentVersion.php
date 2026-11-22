<?php

namespace App\Cache;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Versionsnummer aller öffentlichen Inhalte.
 *
 * - Sie steckt im ETag jeder Seite: Ändert sie sich, bekommen Browser beim nächsten Besuch
 *   die neue Seite statt „304 Not Modified“.
 * - bump() wird nach jeder Änderung im Backend aufgerufen (ContentChangeListener). Dabei werden
 *   auch der Seiten-Cache (Symfony HttpCache, var/share/<env>/http_cache) und der
 *   Anwendungs-Cache (ContentCache) geleert. Bei einer privaten Sammlung sind Änderungen
 *   selten – einfach alles zu leeren ist sicherer als gezielte Invalidierung.
 * - Version und Anwendungs-Cache wechseln sofort. Den Seiten-Cache (viele Dateien) löscht
 *   clearPageCache() erst, wenn die Antwort schon beim Browser ist (kernel.terminate) bzw. am Ende
 *   eines Konsolenbefehls – einmal, auch wenn eine Anfrage oder ein Cronjob mehrmals speichert.
 *   So wartet im Backend niemand auf das Löschen.
 */
final class ContentVersion
{
    private const KEY = 'content_version';

    private ?string $current = null;
    private bool $pageCacheStale = false;

    public function __construct(
        private readonly CacheInterface $cacheApp,
        private readonly ContentCache $contentCache,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.share_dir%/http_cache')]
        private readonly string $httpCacheDir,
    ) {
    }

    public function get(): string
    {
        return $this->current ??= $this->cacheApp->get(self::KEY, static fn (): string => bin2hex(random_bytes(6)));
    }

    public function bump(): void
    {
        $this->cacheApp->delete(self::KEY);
        $this->current = null;
        $this->contentCache->invalidate();
        $this->pageCacheStale = true;
    }

    #[AsEventListener(event: KernelEvents::TERMINATE)]
    #[AsEventListener(event: ConsoleEvents::TERMINATE)]
    public function clearPageCache(): void
    {
        if (!$this->pageCacheStale) {
            return;
        }
        $this->pageCacheStale = false;
        try {
            (new Filesystem())->remove($this->httpCacheDir);
        } catch (\Throwable $e) {
            $this->logger->warning('Seiten-Cache konnte nicht geleert werden: {message}', ['message' => $e->getMessage()]);
        }
    }
}
