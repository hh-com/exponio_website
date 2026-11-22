<?php

namespace App\Tests\Functional;

use App\Entity\Item;
use App\Entity\Language;
use App\Entity\UiTranslation;
use App\Enum\ItemStatus;
use App\Enum\TranslationOrigin;
use App\Repository\ItemRepository;
use App\Translation\TranslationRunner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Sprache freischalten → KI übersetzt (hier AI_PROVIDER=fake) → Sprache wird öffentlich.
 */
final class LanguageTest extends WebTestCase
{
    protected function tearDown(): void
    {
        // andere Tests erwarten nur Deutsch und Englisch
        $em = static::getContainer()->get(EntityManagerInterface::class);
        foreach (['fr', 'ar'] as $code) {
            $em->find(Language::class, $code)?->setActive(false)->setUiTranslatedAt(null);
        }
        $em->flush();
        parent::tearDown();
    }

    public function testActivatedLanguageIsTranslatedAndPublished(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $french = $em->find(Language::class, 'fr');
        self::assertNotNull($french);

        $client->request('GET', '/fr/catalog');
        self::assertResponseStatusCodeSame(404, 'nicht freigeschaltet');

        $french->setActive(true);
        $em->flush();
        $client->request('GET', '/fr/catalog');
        self::assertResponseStatusCodeSame(404, 'freigeschaltet, aber Oberfläche noch nicht übersetzt');

        // nach jeder Anfrage startet der Testclient den Kernel neu → Dienste und Sprache neu holen
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $french = $em->find(Language::class, 'fr');
        self::assertNotNull($french);
        $stats = $container->get(TranslationRunner::class)->run($french, 1000);
        self::assertSame(0, $stats->uiRemaining);
        self::assertSame(0, $stats->contentRemaining);
        self::assertNotNull($french->getUiTranslatedAt());
        self::assertGreaterThan(0, $em->getRepository(UiTranslation::class)->count(['locale' => 'fr']));

        $crawler = $client->request('GET', '/fr/catalog');
        self::assertResponseIsSuccessful();
        self::assertSame('fr', $crawler->filter('html')->attr('lang'));
        self::assertStringContainsString('[fr] Katalog', $crawler->filter('h1')->text());

        $item = $container->get(ItemRepository::class)->findOneBy(['status' => ItemStatus::Published], ['id' => 'ASC']);
        self::assertInstanceOf(Item::class, $item);
        $crawler = $client->request('GET', '/fr/item/'.$item->getId().'-'.$item->getSlug('fr'));
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('[fr] ', $crawler->filter('h1')->text());
        self::assertSelectorExists('link[hreflang=fr]');

        // zweiter Lauf: alles aktuell, keine KI-Anfrage
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $french = $em->find(Language::class, 'fr');
        self::assertNotNull($french);
        self::assertSame(0, $container->get(TranslationRunner::class)->run($french, 1000)->requests);

        // geänderter deutscher Text → Übersetzung veraltet → wird neu übersetzt
        $item = $em->find(Item::class, $item->getId());
        self::assertNotNull($item);
        $item->setTitle('Neuer Titel für den Test');
        $em->flush();
        $stats = $container->get(TranslationRunner::class)->run($french, 1000);
        self::assertSame(1, $stats->contentTranslated);
        self::assertSame('[fr] Neuer Titel für den Test', $item->getTitle('fr'));
        self::assertSame(TranslationOrigin::Ai, $item->translation('fr')?->getOrigin());
    }

    public function testRightToLeftLanguage(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $arabic = $em->find(Language::class, 'ar');
        self::assertNotNull($arabic);
        self::assertTrue($arabic->isRtl());
        $arabic->setActive(true);
        $em->flush();
        $container->get(TranslationRunner::class)->run($arabic, 1000);
        self::assertNotNull($arabic->getUiTranslatedAt());

        $crawler = $client->request('GET', '/ar/');
        self::assertResponseIsSuccessful();
        self::assertSame('rtl', $crawler->filter('html')->attr('dir'));
        $client->request('GET', '/de/sprachen');
        self::assertStringContainsString('العربية', (string) $client->getResponse()->getContent());
    }
}
