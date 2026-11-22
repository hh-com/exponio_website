<?php

namespace App\Seo;

use App\Cache\ContentCache;
use App\Catalog\CategoryTree;
use App\Entity\Item;
use App\Entity\Language;
use App\Enum\ItemStatus;
use App\Localization\Locales;
use App\Repository\CollectionAreaRepository;
use App\Repository\PageRepository;
use App\Repository\TopicRepository;
use App\Twig\AppExtension;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Asset\Packages;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Sammelt alle öffentlichen Adressen für die Sitemaps – je Seite die URLs in allen Sprachen,
 * in denen es sie gibt (Objekte nur in den Sprachen, in die sie übersetzt sind), plus Bilder.
 */
final class SitemapBuilder
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CollectionAreaRepository $areas,
        private readonly CategoryTree $tree,
        private readonly TopicRepository $topics,
        private readonly PageRepository $pages,
        private readonly AppExtension $app,
        private readonly UrlGeneratorInterface $urls,
        private readonly Packages $packages,
        private readonly Locales $locales,
        private readonly ContentCache $cache,
    ) {
    }

    /** @return list<array{urls: array<string, string>, lastmod: string|null, images: list<string>}> */
    public function entries(): array
    {
        return $this->cache->get('sitemap.entries', $this->build(...));
    }

    /** @return list<array{urls: array<string, string>, lastmod: string|null, images: list<string>}> */
    private function build(): array
    {
        $entries = [];
        $route = fn (string $name): \Closure => fn (string $l): string => $this->urls->generate($name, ['_locale' => $l], UrlGeneratorInterface::ABSOLUTE_URL);

        foreach (['home', 'catalog', 'regions', 'topics', 'languages', 'contact'] as $name) {
            $entries[] = $this->entry($route($name));
        }
        foreach ($this->areas->findActive() as $area) {
            $entries[] = $this->entry(fn (string $l): string => $this->app->entityUrl('area', $area->getId(), $area->getSlug($l), true, $l));
        }
        foreach ($this->tree->publishedCounts() as $id => $count) {
            if ($count > 0 && null !== $category = $this->tree->get($id)) {
                $entries[] = $this->entry(fn (string $l): string => $this->app->entityUrl('region', $id, $category->getSlug($l), true, $l));
            }
        }
        foreach ($this->topics->findAllOrdered(activeOnly: true) as $topic) {
            $entries[] = $this->entry(fn (string $l): string => $this->app->entityUrl('topic', $topic->getId(), $topic->getSlug($l), true, $l));
        }
        foreach ($this->pages->findActive() as $page) {
            $entries[] = $this->entry(fn (string $l): string => $this->app->entityUrl('page', $page->getId(), $page->getSlug($l), true, $l), null, $page->getUpdatedAt());
        }

        /** @var list<Item> $items */
        $items = $this->em->createQuery('SELECT i, t, img FROM App\Entity\Item i LEFT JOIN i.translations t LEFT JOIN i.images img WHERE i.status = :s ORDER BY i.id DESC')
            ->setParameter('s', ItemStatus::Published)->setMaxResults(20000)->getResult();
        foreach ($items as $item) {
            $images = array_map(fn ($image): string => $this->packages->getUrl($image->getRelativePath()), $item->getImages()->getValues());
            $entries[] = $this->entry(
                fn (string $l): string => $this->app->itemUrl($item, true, $l),
                static fn (string $l): bool => Language::SOURCE === $l || $item->isTranslatedTo($l),
                $item->getUpdatedAt(),
                $images,
            );
        }

        return $entries;
    }

    /**
     * @param callable(string): string      $urlFor
     * @param (callable(string): bool)|null $exists
     * @param list<string>                  $images
     *
     * @return array{urls: array<string, string>, lastmod: string|null, images: list<string>}
     */
    private function entry(callable $urlFor, ?callable $exists = null, ?\DateTimeInterface $lastmod = null, array $images = []): array
    {
        $urls = [];
        foreach ($this->locales->codes() as $locale) {
            if (null === $exists || $exists($locale)) {
                $urls[$locale] = $urlFor($locale);
            }
        }

        return ['urls' => $urls, 'lastmod' => $lastmod?->format('Y-m-d'), 'images' => $images];
    }
}
