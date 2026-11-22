<?php

namespace App\Controller\Front;

use App\Catalog\CatalogQuery;
use App\Catalog\CatalogSearch;
use App\Entity\Category;
use App\Entity\Topic;
use App\Twig\AppExtension;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Asset\Packages;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Vorschläge für das Suchfeld (Objekte, Länder, Themen).
 */
final class SearchController extends FrontController
{
    #[Route('/api/suggest', name: 'api_suggest', methods: ['GET'])]
    public function suggest(Request $request, CatalogSearch $search, EntityManagerInterface $em, AppExtension $app, Packages $packages): JsonResponse
    {
        $q = trim((string) $request->query->get('q', ''));
        $locale = $request->getLocale();
        if (mb_strlen($q) < 2) {
            return $this->json(['items' => [], 'regions' => [], 'topics' => []]);
        }

        $query = new CatalogQuery();
        $query->q = mb_substr($q, 0, 100);
        $result = $search->search($query, 6, $locale, withFacets: false);
        $items = [];
        foreach ($result->items as $item) {
            $image = $item->getMainImage();
            $items[] = [
                'title' => $item->getTitle($locale),
                'meta' => implode(' · ', array_filter([$item->getCategory()?->getName($locale), $item->getYearLabel()])),
                'url' => $app->itemUrl($item),
                'thumb' => null !== $image ? $packages->getUrl($image->getRelativePath(400)) : null,
            ];
        }

        $like = '%'.str_replace(['%', '_'], '', $q).'%';
        /** @var list<Category> $regions */
        $regions = $em->createQuery('SELECT DISTINCT c FROM App\Entity\Category c JOIN c.translations t WHERE c.active = true AND t.name LIKE :q ORDER BY c.position')
            ->setParameter('q', $like)->setMaxResults(4)->getResult();
        /** @var list<Topic> $topics */
        $topics = $em->createQuery('SELECT DISTINCT t FROM App\Entity\Topic t JOIN t.translations tr WHERE t.active = true AND tr.name LIKE :q ORDER BY t.position')
            ->setParameter('q', $like)->setMaxResults(4)->getResult();

        return $this->json([
            'items' => $items,
            'total' => $result->total,
            'regions' => array_map(static fn (Category $c): array => ['label' => $c->getPathLabel($locale), 'url' => $app->entityUrl('region', $c->getId(), $c->getSlug($locale))], $regions),
            'topics' => array_map(static fn (Topic $t): array => ['label' => $t->getName($locale), 'url' => $app->entityUrl('topic', $t->getId(), $t->getSlug($locale))], $topics),
        ]);
    }
}
