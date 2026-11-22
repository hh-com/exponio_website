<?php

namespace App\Controller\Front;

use App\Repository\ItemRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Merkliste: wird nur im Browser (localStorage) gespeichert, der Server liefert die Karten.
 */
final class WatchlistController extends FrontController
{
    #[Route(['de' => '/merkliste', 'en' => '/watchlist'], name: 'watchlist', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('front/watchlist/index.html.twig');
    }

    #[Route('/api/cards', name: 'api_cards', methods: ['GET'])]
    public function cards(Request $request, ItemRepository $items): Response
    {
        $ids = \array_slice(array_filter(array_map('intval', explode(',', (string) $request->query->get('ids', '')))), 0, 100);

        return $this->render('front/partials/_cards.html.twig', ['items' => $items->findPublishedByIds($ids)]);
    }
}
