<?php

namespace App\Tests\Functional;

use App\Entity\Inquiry;
use App\Entity\Item;
use App\Enum\ItemStatus;
use App\Repository\CategoryRepository;
use App\Repository\CollectionAreaRepository;
use App\Repository\ItemRepository;
use App\Repository\TopicRepository;
use App\Twig\AppExtension;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class FrontendTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testRootRedirectsToPreferredLanguage(): void
    {
        $this->client->request('GET', '/', server: ['HTTP_ACCEPT_LANGUAGE' => 'en-GB,en;q=0.9']);
        self::assertResponseRedirects('/en/');
        $this->client->request('GET', '/', server: ['HTTP_ACCEPT_LANGUAGE' => 'de-DE,de;q=0.9']);
        self::assertResponseRedirects('/de/');
        $this->client->request('GET', '/', server: ['HTTP_ACCEPT_LANGUAGE' => 'ja']);
        self::assertResponseRedirects('/de/', null, 'nicht freigeschaltete Sprache → Deutsch');
    }

    public function testPublicPagesRender(): void
    {
        foreach ([
            '/de/', '/en/', '/de/katalog', '/en/catalog?sort=year_asc', '/de/katalog?q=Deutsch&page=2',
            '/de/laender', '/en/countries', '/de/themen', '/en/topics', '/de/kontakt', '/en/watchlist',
            '/de/sprachen', '/en/languages', '/sitemap.xml', '/sitemap-de.xml', '/sitemap-en.xml', '/robots.txt', '/llms.txt',
        ] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful($url);
        }
    }

    public function testOldAndForeignPathsAre404(): void
    {
        foreach (['/de/gibt-es-nicht', '/de/objekt/999999-nix', '/de/catalog', '/de/gebiete', '/de/sammelgebiet/1-briefmarken', '/fr/catalog', '/xx/'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(404, $url);
        }
    }

    public function testNavigationUsesNewTerms(): void
    {
        $crawler = $this->client->request('GET', '/de/');
        $nav = $crawler->filter('header nav[aria-label="Hauptnavigation"]');
        self::assertSame(['Katalog', 'Länder', 'Themen', 'Kontakt'], $nav->filter('[data-disclosure-toggle], nav > a')->each(static fn ($n): string => trim((string) preg_replace('/\s+/', ' ', $n->text()))));
        $areas = static::getContainer()->get(CollectionAreaRepository::class)->findActive();
        self::assertCount(\count($areas) + 1, $crawler->filter('#nav-catalog a'), 'Alle Objekte + jede aktive Objektart');
        self::assertSame('false', $crawler->filter('[aria-controls="nav-catalog"]')->attr('aria-expanded'));
        self::assertStringNotContainsString('Sammelgebiet', (string) $this->client->getResponse()->getContent());
    }

    public function testCatalogJsonWithFacetsAndFilters(): void
    {
        $this->client->request('GET', '/de/katalog', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseIsSuccessful();
        $all = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertGreaterThan(0, $all['total']);
        self::assertNotEmpty($all['facets']['areas']);
        self::assertNotEmpty($all['facets']['regions']);
        self::assertArrayNotHasKey('available', $all['facets']);
        self::assertStringContainsString('<article', $all['html']);

        $area = $all['facets']['areas'][0]['id'];
        $this->client->request('GET', '/de/katalog?area='.$area, server: ['HTTP_ACCEPT' => 'application/json']);
        $filtered = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame($all['facets']['areas'][0]['count'], $filtered['total']);

        $attribute = $filtered['facets']['attributes'][0] ?? null;
        self::assertNotNull($attribute, 'Objektart liefert Merkmal-Facetten');
        $option = $attribute['options'][0];
        $this->client->request('GET', sprintf('/de/katalog?area=%d&attr[%s]=%s', $area, $attribute['code'], $option['value']), server: ['HTTP_ACCEPT' => 'application/json']);
        $byAttribute = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame($option['count'], $byAttribute['total']);
    }

    public function testItemPageCanonicalRedirectStructuredDataAndSimilar(): void
    {
        $item = $this->firstPublished();
        $this->client->request('GET', '/de/objekt/'.$item->getId().'-falscher-slug');
        self::assertResponseStatusCodeSame(301);
        $canonical = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringEndsWith('/de/objekt/'.$item->getId().'-'.$item->getSlug('de'), $canonical);

        $crawler = $this->client->request('GET', $canonical);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', $item->getTitle('de'));
        self::assertGreaterThan(0, $crawler->filter('#similar-title + ul article')->count());
        self::assertSelectorExists('link[hreflang=en]');
        self::assertSelectorExists('link[hreflang=x-default]');
        self::assertSelectorExists('dl');

        $jsonLd = implode('', $crawler->filter('script[type="application/ld+json"]')->each(static fn ($n): string => $n->text()));
        self::assertStringContainsString('"ItemPage"', $jsonLd);
        self::assertStringNotContainsString('"Offer"', $jsonLd);
        self::assertStringNotContainsString('"Product"', $jsonLd);

        $this->client->request('GET', '/en/item/'.$item->getId().'-'.$item->getSlug('en'));
        self::assertResponseIsSuccessful();
    }

    public function testEstimateIsOnlyShownWhenReleased(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $item = $this->firstPublished();
        $item->setCatalogValue(12.5)->setShowEstimate(false);
        $em->flush();
        $this->client->request('GET', '/de/objekt/'.$item->getId().'-'.$item->getSlug('de'));
        self::assertStringNotContainsString('kein Verkaufsangebot', (string) $this->client->getResponse()->getContent());

        $item->setShowEstimate(true);
        $em->flush();
        $this->client->request('GET', '/de/objekt/'.$item->getId().'-'.$item->getSlug('de'));
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Schätzwert', $html);
        self::assertStringContainsString('12,50', $html);
        self::assertStringContainsString('kein Verkaufsangebot', $html);
    }

    public function testAreaRegionAndTopicPages(): void
    {
        $container = static::getContainer();
        $app = $container->get(AppExtension::class);
        $area = $container->get(CollectionAreaRepository::class)->findActive()[0];
        $url = $app->entityUrl('area', $area->getId(), $area->getSlug('de'), false, 'de');
        self::assertStringStartsWith('/de/katalog/', $url);
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        $category = $container->get(CategoryRepository::class)->findOneByName('Deutschland');
        self::assertNotNull($category);
        $url = $app->entityUrl('region', $category->getId(), $category->getSlug('de'), false, 'de');
        self::assertStringStartsWith('/de/land/', $url);
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Deutschland');

        $this->client->request('GET', $app->entityUrl('region', $category->getId(), $category->getSlug('en'), false, 'en'));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Germany');

        $topic = $container->get(TopicRepository::class)->findAllOrdered()[0];
        $this->client->request('GET', $app->entityUrl('topic', $topic->getId(), $topic->getSlug('en'), false, 'en'));
        self::assertResponseIsSuccessful();
    }

    public function testSuggestAndWatchlistCards(): void
    {
        $this->client->request('GET', '/de/api/suggest?q=Deutsch');
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertNotEmpty($data['regions']);

        $item = $this->firstPublished();
        $this->client->request('GET', '/de/api/cards?ids='.$item->getId().',999999');
        self::assertResponseIsSuccessful();
        self::assertSame(1, substr_count((string) $this->client->getResponse()->getContent(), '<article'));
    }

    public function testContactMessageIsValidatedStoredAndHoneypotted(): void
    {
        $crawler = $this->client->request('GET', '/de/kontakt');
        $props = json_decode((string) $crawler->filter('[data-island=inquiry-form]')->attr('data-props'), true);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $before = $em->getRepository(Inquiry::class)->count([]);

        $post = fn (array $data) => $this->client->request('POST', $props['endpoint'], server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_SEC_FETCH_SITE' => 'same-origin',
        ], content: (string) json_encode($data + ['token' => $props['token']]));

        $post(['name' => '', 'email' => 'kaputt', 'message' => 'kurz']);
        self::assertResponseStatusCodeSame(422);
        $errors = json_decode((string) $this->client->getResponse()->getContent(), true)['errors'];
        self::assertArrayHasKey('name', $errors);
        self::assertArrayHasKey('email', $errors);
        self::assertArrayHasKey('message', $errors);

        $post(['name' => 'Bot', 'email' => 'bot@example.org', 'message' => 'Spam spam spam', 'website' => 'http://spam']);
        self::assertResponseIsSuccessful();

        $post(['name' => 'Erika Muster', 'email' => 'erika@example.org', 'message' => 'Eine Frage zu Ihrer Sammlung.']);
        self::assertResponseIsSuccessful();
        self::assertSame($before + 1, $em->getRepository(Inquiry::class)->count([]), 'Honeypot speichert nichts, echte Nachricht wird gespeichert');

        $this->client->request('POST', $props['endpoint'], server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'https://evil.example', 'HTTP_SEC_FETCH_SITE' => 'cross-site'], content: (string) json_encode(['token' => $props['token']]));
        self::assertResponseStatusCodeSame(403);
    }

    public function testSitemapsRobotsAndLlmsTxt(): void
    {
        $this->client->request('GET', '/sitemap.xml');
        self::assertStringContainsString('/sitemap-de.xml', (string) $this->client->getResponse()->getContent());
        $this->client->request('GET', '/sitemap-de.xml');
        $xml = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('hreflang="en"', $xml);
        self::assertStringContainsString('<image:loc>', $xml);
        self::assertStringContainsString('/de/objekt/', $xml);

        $this->client->request('GET', '/robots.txt');
        $robots = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Sitemap: http://localhost/sitemap.xml', $robots);
        self::assertStringContainsString('Disallow: /admin', $robots);

        $this->client->request('GET', '/llms.txt');
        self::assertStringStartsWith('# ', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('## Katalog', (string) $this->client->getResponse()->getContent());
    }

    public function testFrontendIsCachableAndSetsNoCookies(): void
    {
        $this->client->request('GET', '/de/katalog');
        $response = $this->client->getResponse();
        self::assertTrue($response->headers->hasCacheControlDirective('public'));
        self::assertSame('86400', (string) $response->headers->getCacheControlDirective('s-maxage'));
        self::assertSame([], $response->headers->getCookies(), 'Frontend setzt keine Cookies');
        $etag = (string) $response->getEtag();
        self::assertNotSame('', $etag);

        $this->client->request('GET', '/de/katalog', server: ['HTTP_IF_NONE_MATCH' => $etag]);
        self::assertResponseStatusCodeSame(304);

        // Inhaltsänderung → neue Version → neuer ETag
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $item = $this->firstPublished();
        $item->setFeatured(!$item->isFeatured());
        $em->flush();
        $this->client->request('GET', '/de/katalog', server: ['HTTP_IF_NONE_MATCH' => $etag]);
        self::assertResponseIsSuccessful();
        self::assertNotSame($etag, $this->client->getResponse()->getEtag());
    }

    public function testNoExternalResourcesAndSecurityHeaders(): void
    {
        $item = $this->firstPublished();
        foreach (['/de/', '/de/katalog', '/de/objekt/'.$item->getId().'-'.$item->getSlug('de'), '/de/kontakt'] as $url) {
            $this->client->request('GET', $url);
            $html = (string) $this->client->getResponse()->getContent();
            preg_match_all('/<(?:script|link|img|iframe)\b[^>]*\b(?:src|href)="(https?:)?\/\/(?!localhost)([^"\/]+)/i', $html, $matches);
            self::assertSame([], $matches[2], 'Keine fremden Hosts in '.$url);
            $headers = $this->client->getResponse()->headers;
            self::assertStringContainsString("frame-ancestors 'none'", (string) $headers->get('Content-Security-Policy'));
            self::assertSame('nosniff', $headers->get('X-Content-Type-Options'));
        }
    }

    private function firstPublished(): Item
    {
        $item = static::getContainer()->get(ItemRepository::class)->findOneBy(['status' => ItemStatus::Published], ['id' => 'ASC']);
        self::assertNotNull($item);

        return $item;
    }
}
