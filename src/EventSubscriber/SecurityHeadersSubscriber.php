<?php

namespace App\EventSubscriber;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Sicherheits-Header (Art. 32 DSGVO: Sicherheit der Verarbeitung).
 *
 * Das Frontend lädt nur eigene Dateien – die Content-Security-Policy verbietet fremde Skripte,
 * Schriften, Frames und Verbindungen. Inline-Skripte gibt es nicht (JSON-Daten in
 * <script type="application/json"> werden nicht ausgeführt und sind deshalb erlaubt).
 */
final class SecurityHeadersSubscriber implements EventSubscriberInterface
{
    private const ADMIN_CSP = "object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'";
    private const CSP = "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; manifest-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'";

    public function __construct(
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
        #[Autowire('%env(VITE_DEV_SERVER)%')]
        private readonly string $viteDevServer,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onResponse', -20]];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $headers = $event->getResponse()->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        $headers->set('X-Frame-Options', 'DENY');
        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        // Entwicklung (Web-Profiler, Vite-Dev-Server) ohne CSP
        if ('dev' === $this->environment || '' !== trim($this->viteDevServer)) {
            return;
        }
        // Backend: EasyAdmin braucht Inline-Skripte, deshalb nur die Teile der Policy, die es nicht stört
        $isAdmin = str_starts_with($request->getPathInfo(), '/admin');
        $csp = $isAdmin ? self::ADMIN_CSP : self::CSP;
        $headers->set('Content-Security-Policy', $csp.($request->isSecure() ? '; upgrade-insecure-requests' : ''));
    }
}
