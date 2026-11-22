<?php

namespace App\Controller\Front;

use App\Catalog\CategoryTree;
use App\Repository\ItemRepository;
use App\Repository\TopicRepository;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends FrontController
{
    #[Route('/', name: 'home', methods: ['GET'])]
    public function index(ItemRepository $items, CategoryTree $tree, TopicRepository $topics): Response
    {
        $regionCounts = $tree->publishedCounts();
        $regions = array_values(array_filter($tree->roots(), static fn ($c): bool => ($regionCounts[$c->getId()] ?? 0) > 0));
        usort($regions, static fn ($a, $b): int => ($regionCounts[$b->getId()] ?? 0) <=> ($regionCounts[$a->getId()] ?? 0));

        $topicCounts = $items->countPublishedByTopic();
        $topicList = array_values(array_filter($topics->findAllOrdered(activeOnly: true), static fn ($t): bool => ($topicCounts[$t->getId()] ?? 0) > 0));

        $featured = $items->findLatest(8, featuredOnly: true);
        $latest = $items->findLatest(8);

        return $this->render('front/home/index.html.twig', [
            'featured' => $featured,
            'latest' => $latest,
            'regions' => \array_slice($regions, 0, 12),
            'regionCounts' => $regionCounts,
            'topics' => \array_slice($topicList, 0, 24),
            'topicCounts' => $topicCounts,
            'stats' => $items->publishedStats(),
        ]);
    }
}
