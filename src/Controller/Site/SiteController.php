<?php

namespace App\Controller\Site;

use App\Localization\Locales;
use App\Media\BrandAssets;
use App\Seo\LlmsTxtBuilder;
use App\Seo\SitemapBuilder;
use App\Service\SettingsProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Routen ohne Sprachpräfix: Startseiten-Weiterleitung, Sitemaps, robots.txt, llms.txt und Web-App-Manifest.
 * Alle Antworten sind öffentlich cachebar (siehe App\EventSubscriber\HttpCacheSubscriber).
 */
final class SiteController extends AbstractController
{
    /** Bekannte KI-Crawler; gesperrt, wenn das im Backend so eingestellt ist */
    private const AI_CRAWLERS = ['GPTBot', 'OAI-SearchBot', 'ChatGPT-User', 'ClaudeBot', 'Claude-SearchBot', 'anthropic-ai', 'Google-Extended', 'PerplexityBot', 'CCBot', 'Applebot-Extended', 'meta-externalagent', 'Bytespider', 'Amazonbot'];

    public function __construct(private readonly Locales $locales)
    {
    }

    /** Startseite: Weiterleitung auf die bevorzugte Sprache des Browsers */
    #[Route('/', name: 'root', methods: ['GET', 'HEAD'])]
    public function root(Request $request): RedirectResponse
    {
        $locale = $request->getPreferredLanguage($this->locales->codes()) ?? 'de';
        $response = $this->redirectToRoute('home', ['_locale' => $locale]);
        $response->setVary('Accept-Language');

        return $response;
    }

    /** Sitemap-Index: eine Sitemap je öffentlicher Sprache */
    #[Route('/sitemap.xml', name: 'sitemap', methods: ['GET', 'HEAD'])]
    public function sitemapIndex(): Response
    {
        return $this->xml('site/sitemap_index.xml.twig', ['locales' => $this->locales->codes()]);
    }

    #[Route('/sitemap-{locale}.xml', name: 'sitemap_locale', requirements: ['locale' => '[a-z]{2}'], methods: ['GET', 'HEAD'])]
    public function sitemap(string $locale, SitemapBuilder $builder): Response
    {
        if (!$this->locales->isPublic($locale)) {
            throw $this->createNotFoundException();
        }
        $entries = array_values(array_filter($builder->entries(), static fn (array $e): bool => isset($e['urls'][$locale])));

        return $this->xml('site/sitemap.xml.twig', ['entries' => $entries, 'locale' => $locale]);
    }

    #[Route('/robots.txt', name: 'robots', methods: ['GET', 'HEAD'])]
    public function robots(SettingsProvider $settings): Response
    {
        $lines = ['User-agent: *', 'Disallow: /admin', 'Disallow: /*/api/', 'Disallow: /de/merkliste', 'Disallow: /*/watchlist', ''];
        if (!$settings->get()->isAllowAiCrawlers()) {
            foreach (self::AI_CRAWLERS as $bot) {
                array_push($lines, 'User-agent: '.$bot, 'Disallow: /', '');
            }
        }
        $lines[] = 'Sitemap: '.$this->generateUrl('sitemap', [], UrlGeneratorInterface::ABSOLUTE_URL);

        return new Response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /** Übersicht für KI-Suchsysteme (https://llmstxt.org) */
    #[Route('/llms.txt', name: 'llms_txt', methods: ['GET', 'HEAD'])]
    public function llmsTxt(LlmsTxtBuilder $builder): Response
    {
        return new Response($builder->build(), 200, ['Content-Type' => 'text/markdown; charset=UTF-8']);
    }

    /** Web-App-Manifest: Name und Symbole kommen aus den Einstellungen (Backend → Einstellungen → Website) */
    #[Route('/site.webmanifest', name: 'site_manifest', methods: ['GET', 'HEAD'])]
    public function manifest(SettingsProvider $settings, BrandAssets $brand): JsonResponse
    {
        $name = $settings->get()->getSiteName();
        $response = new JsonResponse([
            'name' => $name,
            'short_name' => $name,
            'icons' => [
                ['src' => $brand->url('icon-192'), 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => $brand->url('icon-512'), 'sizes' => '512x512', 'type' => 'image/png'],
            ],
            'theme_color' => '#1A2A43',
            'background_color' => '#F1EFEA',
            'display' => 'browser',
        ]);
        $response->setEncodingOptions(\JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        $response->headers->set('Content-Type', 'application/manifest+json');

        return $response;
    }

    /** @param array<string, mixed> $context */
    private function xml(string $template, array $context): Response
    {
        $response = $this->render($template, $context);
        $response->headers->set('Content-Type', 'application/xml; charset=UTF-8');

        return $response;
    }
}
