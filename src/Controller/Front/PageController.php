<?php

namespace App\Controller\Front;

use App\Repository\PageRepository;
use App\Twig\AppExtension;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PageController extends FrontController
{
    #[Route(['de' => '/seite/{id}-{slug}', 'en' => '/page/{id}-{slug}'], name: 'page', requirements: ['id' => '\d+', 'slug' => '[a-z0-9-]+'], methods: ['GET'])]
    public function show(Request $request, int $id, string $slug, PageRepository $pages, AppExtension $app): Response
    {
        $page = $pages->find($id);
        if (null === $page || !$page->isActive()) {
            throw $this->createNotFoundException();
        }
        if ($slug !== $page->getSlug($request->getLocale())) {
            return $this->redirect($app->entityUrl('page', $id, $page->getSlug($request->getLocale())), 301);
        }
        $this->setAlternates($request, static fn (string $l): string => $app->entityUrl('page', $id, $page->getSlug($l), false, $l));

        return $this->render('front/page/show.html.twig', ['page' => $page]);
    }
}
