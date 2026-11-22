<?php

namespace App\EventSubscriber;

use App\Cache\ContentVersion;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Macht öffentliche Seiten cachebar.
 *
 * - Cache-Control: public, max-age=0, s-maxage=86400, stale-while-revalidate=60
 *   → Browser fragen jedes Mal nach, der Seiten-Cache (Symfony HttpCache) hält die Seite einen Tag.
 * - ETag aus Inhaltsversion, Adresse und Accept-Header
 *   → Hat der Browser die aktuelle Version, kommt nur „304 Not Modified“ ohne Inhalt zurück.
 *
 * Ändert sich etwas im Backend, steigt die Inhaltsversion und der Seiten-Cache wird geleert.
 * Nicht gecacht werden: Backend, POST, Fehlerseiten, private Antworten (z. B. Vorschau) und
 * alles, was eine Session benutzt (Symfony setzt solche Antworten danach automatisch auf private).
 */
final class HttpCacheSubscriber implements EventSubscriberInterface
{
    public const SHARED_MAX_AGE = 86400;
    private const SITE_ROUTES = ['root', 'sitemap', 'sitemap_locale', 'robots', 'llms_txt', 'site_manifest'];

    public function __construct(private readonly ContentVersion $version)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onResponse', -10]];
    }

    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $response = $event->getResponse();
        if (!$event->isMainRequest() || !$request->isMethodCacheable()) {
            return;
        }
        $isPublicRoute = true === $request->attributes->get('_front') || \in_array($request->attributes->get('_route'), self::SITE_ROUTES, true);
        if (!$isPublicRoute || !\in_array($response->getStatusCode(), [200, 301, 302], true)) {
            return;
        }
        // bewusst ausgeschlossen (z. B. Vorschau unveröffentlichter Objekte); "private" setzt Symfony
        // bei jeder Antwort ohne Cache-Header von selbst und taugt deshalb nicht als Merkmal
        if ($response->headers->hasCacheControlDirective('no-store')) {
            return;
        }

        $response->setPublic();
        $response->setMaxAge(0);
        $response->setSharedMaxAge(self::SHARED_MAX_AGE);
        $response->headers->addCacheControlDirective('stale-while-revalidate', '60');

        if (200 === $response->getStatusCode()) {
            $response->setEtag(sha1(implode('|', [
                $this->version->get(),
                $request->getLocale(),
                $request->getRequestUri(),
                $request->headers->get('Accept', ''),
            ])));
            $response->isNotModified($request);
        }
    }
}
