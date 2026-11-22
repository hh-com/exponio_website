<?php

namespace App\Controller\Front;

use App\Catalog\SimilarItems;
use App\Entity\Language;
use App\Repository\ItemRepository;
use App\Twig\AppExtension;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class ItemController extends FrontController
{
    #[Route(['de' => '/objekt/{id}-{slug}', 'en' => '/item/{id}-{slug}'], name: 'item', requirements: ['id' => '\d+', 'slug' => '[a-z0-9-]+'], methods: ['GET'])]
    public function show(Request $request, int $id, string $slug, ItemRepository $items, SimilarItems $similar, AppExtension $app): Response
    {
        $item = $items->find($id);
        // Unveröffentlichte Objekte sieht nur ein angemeldeter Admin (Vorschau)
        $preview = null !== $item && !$item->isPublished();
        if (null === $item || ($preview && !$this->isGranted('ROLE_ADMIN'))) {
            // früher veröffentlicht, inzwischen archiviert: 410 – Suchmaschinen entfernen die Seite schneller
            throw null !== $item?->getPublishedAt() ? new GoneHttpException() : $this->createNotFoundException();
        }
        $locale = $request->getLocale();
        if ($slug !== $item->getSlug($locale)) {
            return $this->redirect($app->itemUrl($item), 301);
        }
        $items->preload([$item]);
        // hreflang nur für Sprachen, in die das Objekt übersetzt ist
        $this->setAlternates(
            $request,
            static fn (string $l): string => $app->itemUrl($item, false, $l),
            static fn (string $l): bool => Language::SOURCE === $l || $item->isTranslatedTo($l),
        );

        $response = $this->render('front/item/show.html.twig', [
            'item' => $item,
            'similar' => $preview ? [] : $similar->find($item, 8),
            'preview' => $preview,
        ]);
        if ($preview) {
            $response->setPrivate();
            $response->headers->addCacheControlDirective('no-store');
        }

        return $response;
    }
}
