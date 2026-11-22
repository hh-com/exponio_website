<?php

namespace App\Controller\Front;

use App\Catalog\CatalogQuery;
use App\Catalog\CatalogSearch;
use App\Catalog\CategoryTree;
use App\Repository\CollectionAreaRepository;
use App\Repository\ItemRepository;
use App\Repository\TopicRepository;
use App\Service\SettingsProvider;
use App\Twig\AppExtension;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Katalog-Listing mit Filtern – als Gesamtkatalog sowie für Objektart, Land und Thema.
 * Mit "Accept: application/json" liefert dieselbe URL Ergebnis-HTML und Facetten für Vue.
 */
final class CatalogController extends FrontController
{
    private const SLUG = ['id' => '\d+', 'slug' => '[a-z0-9-]+'];

    public function __construct(
        private readonly CatalogSearch $search,
        private readonly SettingsProvider $settings,
        private readonly AppExtension $app,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(['de' => '/katalog', 'en' => '/catalog'], name: 'catalog', methods: ['GET'])]
    public function catalog(Request $request): Response
    {
        $query = CatalogQuery::fromRequest($request);
        $title = null !== $query->q
            ? $this->translator->trans('catalog.search_title', ['%q%' => $query->q])
            : $this->translator->trans('catalog.title');

        return $this->renderCatalog($request, $query, [
            'title' => $title,
            'intro' => null,
            'breadcrumbs' => [['label' => $this->translator->trans('nav.catalog'), 'url' => null]],
        ]);
    }

    /** Seite einer Objektart, z. B. /de/katalog/1-briefmarken */
    #[Route(['de' => '/katalog/{id}-{slug}', 'en' => '/catalog/{id}-{slug}'], name: 'area', requirements: self::SLUG, methods: ['GET'])]
    public function area(Request $request, int $id, string $slug, CollectionAreaRepository $areas): Response
    {
        $area = $areas->find($id);
        if (null === $area || !$area->isActive()) {
            throw $this->createNotFoundException();
        }
        $locale = $request->getLocale();
        if ($slug !== $area->getSlug($locale)) {
            return $this->redirect($this->app->entityUrl('area', $id, $area->getSlug($locale)), 301);
        }
        $this->setAlternates($request, fn (string $l): string => $this->app->entityUrl('area', $id, $area->getSlug($l), false, $l));

        $query = CatalogQuery::fromRequest($request);
        $query->fixedArea = $id;

        return $this->renderCatalog($request, $query, [
            'title' => $area->getName($locale),
            'intro' => $area->getDescription($locale),
            'icon' => $area->getIcon(),
            'entity' => $area,
            'breadcrumbs' => [
                ['label' => $this->translator->trans('nav.catalog'), 'url' => $this->generateUrl('catalog')],
                ['label' => $area->getName($locale), 'url' => null],
            ],
        ]);
    }

    /** Seite eines Landes bzw. Gebiets, z. B. /de/land/12-ddr */
    #[Route(['de' => '/land/{id}-{slug}', 'en' => '/country/{id}-{slug}'], name: 'region', requirements: self::SLUG, methods: ['GET'])]
    public function region(Request $request, int $id, string $slug, CategoryTree $tree): Response
    {
        $category = $tree->get($id);
        if (null === $category) {
            throw $this->createNotFoundException();
        }
        $locale = $request->getLocale();
        if ($slug !== $category->getSlug($locale)) {
            return $this->redirect($this->app->entityUrl('region', $id, $category->getSlug($locale)), 301);
        }
        $this->setAlternates($request, fn (string $l): string => $this->app->entityUrl('region', $id, $category->getSlug($l), false, $l));

        $query = CatalogQuery::fromRequest($request);
        $query->fixedRegion = $id;

        $breadcrumbs = [['label' => $this->translator->trans('nav.regions'), 'url' => $this->generateUrl('regions')]];
        foreach ($category->getPath() as $node) {
            $breadcrumbs[] = ['label' => $node->getName($locale), 'url' => $node === $category ? null : $this->app->entityUrl('region', $node->getId(), $node->getSlug($locale))];
        }
        $counts = $tree->publishedCounts();
        $children = array_values(array_filter($tree->childrenOf($id), static fn ($c): bool => ($counts[$c->getId()] ?? 0) > 0));

        return $this->renderCatalog($request, $query, [
            'title' => $category->getName($locale),
            'intro' => $category->getDescription($locale),
            'icon' => 'globe',
            'entity' => $category,
            'breadcrumbs' => $breadcrumbs,
            'subregions' => $children,
            'regionCounts' => $counts,
        ]);
    }

    #[Route(['de' => '/thema/{id}-{slug}', 'en' => '/topic/{id}-{slug}'], name: 'topic', requirements: self::SLUG, methods: ['GET'])]
    public function topic(Request $request, int $id, string $slug, TopicRepository $topics): Response
    {
        $topic = $topics->find($id);
        if (null === $topic || !$topic->isActive()) {
            throw $this->createNotFoundException();
        }
        $locale = $request->getLocale();
        if ($slug !== $topic->getSlug($locale)) {
            return $this->redirect($this->app->entityUrl('topic', $id, $topic->getSlug($locale)), 301);
        }
        $this->setAlternates($request, fn (string $l): string => $this->app->entityUrl('topic', $id, $topic->getSlug($l), false, $l));

        $query = CatalogQuery::fromRequest($request);
        $query->fixedTopic = $id;

        return $this->renderCatalog($request, $query, [
            'title' => $topic->getName($locale),
            'intro' => $topic->getDescription($locale),
            'icon' => 'tag',
            'entity' => $topic,
            'breadcrumbs' => [
                ['label' => $this->translator->trans('nav.topics'), 'url' => $this->generateUrl('topics')],
                ['label' => $topic->getName($locale), 'url' => null],
            ],
        ]);
    }

    #[Route(['de' => '/laender', 'en' => '/countries'], name: 'regions', methods: ['GET'])]
    public function regions(CategoryTree $tree): Response
    {
        $counts = $tree->publishedCounts();

        return $this->render('front/catalog/regions.html.twig', [
            'roots' => array_values(array_filter($tree->roots(), static fn ($c): bool => ($counts[$c->getId()] ?? 0) > 0)),
            'tree' => $tree,
            'counts' => $counts,
        ]);
    }

    #[Route(['de' => '/themen', 'en' => '/topics'], name: 'topics', methods: ['GET'])]
    public function topics(TopicRepository $topics, ItemRepository $items): Response
    {
        $counts = $items->countPublishedByTopic();

        return $this->render('front/catalog/topics.html.twig', [
            'topics' => array_values(array_filter($topics->findAllOrdered(activeOnly: true), static fn ($t): bool => ($counts[$t->getId()] ?? 0) > 0)),
            'counts' => $counts,
        ]);
    }

    /** @param array<string, mixed> $context */
    private function renderCatalog(Request $request, CatalogQuery $query, array $context): Response
    {
        $locale = $request->getLocale();
        $result = $this->search->search($query, $this->settings->get()->getItemsPerPage(), $locale);
        $baseUrl = $request->getBaseUrl().$request->getPathInfo();
        $resultsHtml = $this->renderView('front/catalog/_results.html.twig', [
            'result' => $result,
            'query' => $query,
            'baseUrl' => $baseUrl,
        ]);

        if ($this->wantsJson($request)) {
            $response = $this->json([
                'html' => $resultsHtml,
                'total' => $result->total,
                'facets' => $result->facets,
                'state' => $query->toArray(),
            ]);
            $response->setVary('Accept');

            return $response;
        }

        $response = $this->render('front/catalog/index.html.twig', $context + [
            'result' => $result,
            'query' => $query,
            'resultsHtml' => $resultsHtml,
            'canonical' => $baseUrl.($result->page > 1 && !$query->hasUserFilters() ? '?page='.$result->page : ''),
            'noindex' => $query->hasUserFilters(),
            'props' => [
                'endpoint' => $baseUrl,
                'state' => $query->toArray(),
                'facets' => $result->facets,
                'total' => $result->total,
                'sorts' => array_map(fn (string $sort): array => ['value' => $sort, 'label' => $this->translator->trans('catalog.sort.'.$sort)], CatalogQuery::SORTS),
            ],
        ]);
        $response->setVary('Accept');

        return $response;
    }
}
