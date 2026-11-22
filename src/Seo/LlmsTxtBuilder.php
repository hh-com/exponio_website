<?php

namespace App\Seo;

use App\Cache\ContentCache;
use App\Catalog\CategoryTree;
use App\Localization\Locales;
use App\Repository\CollectionAreaRepository;
use App\Repository\ItemRepository;
use App\Repository\PageRepository;
use App\Repository\TopicRepository;
use App\Service\SettingsProvider;
use App\Twig\AppExtension;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Erzeugt /llms.txt: eine kurze Markdown-Übersicht der Website für KI-Suchsysteme
 * (Generative Engine Optimization). Entsteht automatisch aus den Daten, ohne Pflegeaufwand.
 */
final class LlmsTxtBuilder
{
    private const MAX_LINKS = 40;

    public function __construct(
        private readonly SettingsProvider $settings,
        private readonly CollectionAreaRepository $areas,
        private readonly ItemRepository $items,
        private readonly CategoryTree $tree,
        private readonly TopicRepository $topics,
        private readonly PageRepository $pages,
        private readonly Locales $locales,
        private readonly AppExtension $app,
        private readonly UrlGeneratorInterface $urls,
        private readonly ContentCache $cache,
    ) {
    }

    public function build(): string
    {
        return $this->cache->get('llms.txt', $this->render(...));
    }

    private function render(): string
    {
        $settings = $this->settings->get();
        $stats = $this->items->publishedStats();
        $url = fn (string $route): string => $this->urls->generate($route, ['_locale' => 'de'], UrlGeneratorInterface::ABSOLUTE_URL);

        $lines = ['# '.$settings->getSiteName(), ''];
        if (null !== $tagline = $settings->getTagline()) {
            $lines[] = '> '.$tagline;
            $lines[] = '';
        }
        $lines[] = sprintf(
            'Private Sammlung mit %d veröffentlichten Objekten%s%s. Die Website zeigt die eigene Sammlung des Betreibers – es wird nichts verkauft.',
            $stats['count'],
            null !== $stats['minYear'] ? sprintf(' aus den Jahren %d bis %d', $stats['minYear'], $stats['maxYear']) : '',
            null !== $settings->getOwnerName() ? ' von '.$settings->getOwnerName() : '',
        );
        $lines[] = 'Jedes Objekt hat eine eigene Seite mit Fotos, Beschreibung und allen Merkmalen (auch als JSON-LD).';
        $lines[] = 'Sprachen: '.implode(', ', array_map(static fn (array $l): string => $l['nativeName'].' (/'.$l['code'].'/)', $this->locales->public()));
        $lines[] = '';

        $lines[] = '## Katalog';
        $lines[] = sprintf('- [Alle Objekte](%s): durchsuchbar und filterbar', $url('catalog'));
        $counts = $this->items->countPublishedByArea();
        foreach ($this->areas->findActive() as $area) {
            $lines[] = sprintf('- [%s](%s): %d Objekte', $area->getName(), $this->app->entityUrl('area', $area->getId(), $area->getSlug('de'), true, 'de'), $counts[$area->getId()] ?? 0);
        }
        $lines[] = '';

        $lines[] = '## Länder';
        $lines[] = sprintf('- [Alle Länder](%s)', $url('regions'));
        $regionCounts = $this->tree->publishedCounts();
        foreach (\array_slice(array_filter($this->tree->roots(), static fn ($c): bool => ($regionCounts[$c->getId()] ?? 0) > 0), 0, self::MAX_LINKS) as $category) {
            $lines[] = sprintf('- [%s](%s): %d Objekte', $category->getName(), $this->app->entityUrl('region', $category->getId(), $category->getSlug('de'), true, 'de'), $regionCounts[$category->getId()] ?? 0);
        }
        $lines[] = '';

        $lines[] = '## Themen';
        $lines[] = sprintf('- [Alle Themen](%s)', $url('topics'));
        $topicCounts = $this->items->countPublishedByTopic();
        foreach (\array_slice(array_filter($this->topics->findAllOrdered(activeOnly: true), static fn ($t): bool => ($topicCounts[$t->getId()] ?? 0) > 0), 0, self::MAX_LINKS) as $topic) {
            $lines[] = sprintf('- [%s](%s): %d Objekte', $topic->getName(), $this->app->entityUrl('topic', $topic->getId(), $topic->getSlug('de'), true, 'de'), $topicCounts[$topic->getId()] ?? 0);
        }
        $lines[] = '';

        $lines[] = '## Weitere Informationen';
        foreach ($this->pages->findActive() as $page) {
            $lines[] = sprintf('- [%s](%s)', $page->getTitle(), $this->app->entityUrl('page', $page->getId(), $page->getSlug('de'), true, 'de'));
        }
        $lines[] = sprintf('- [Sitemap](%s)', $this->urls->generate('sitemap', [], UrlGeneratorInterface::ABSOLUTE_URL));

        return implode("\n", $lines)."\n";
    }
}
