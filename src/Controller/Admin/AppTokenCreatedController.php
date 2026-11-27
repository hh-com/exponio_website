<?php

namespace App\Controller\Admin;

use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Zeigt nach dem Anlegen eines App-Zugangs Website-Adresse und Token zum Kopieren – genau einmal.
 * Eigener Controller, weil EasyAdmin Routen eines CRUD-Controllers nur über eine Aktion freigibt.
 */
#[IsGranted('ROLE_ADMIN')]
#[AdminRoute('/app-token-created', name: 'app_token_created')]
final class AppTokenCreatedController extends AbstractController
{
    public const SESSION_KEY = 'app_token_created';

    #[AdminRoute('/', name: 'show', options: ['methods' => ['GET']])]
    public function show(Request $request): Response
    {
        $created = $request->getSession()->remove(self::SESSION_KEY);

        $response = $this->render('admin/app_token_created.html.twig', [
            'created' => \is_array($created) ? $created : null,
            'url' => self::siteUrl($request),
        ]);
        // das Token darf in keinem Cache landen
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }

    /**
     * Adresse der Website für die App: dieselbe, unter der das Backend gerade läuft, aber immer mit https:// – die App
     * verbindet sich nur verschlüsselt, und hinter einem TLS-Proxy hält Symfony die Anfrage womöglich für http.
     * Port 80/443 entfällt (ein Proxy meldet sonst z. B. „:443“ bei http), ein Unterordner bleibt, „/index.php“ nicht.
     */
    public static function siteUrl(Request $request): string
    {
        $port = (int) $request->getPort();
        $path = (string) preg_replace('#/[^/]+\.php$#', '', $request->getBaseUrl());

        return 'https://'.$request->getHost().(\in_array($port, [0, 80, 443], true) ? '' : ':'.$port).$path;
    }
}
