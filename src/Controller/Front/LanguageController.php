<?php

namespace App\Controller\Front;

use App\Localization\Locales;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Übersicht aller öffentlichen Sprachen – jede verlinkt auf diese Seite in ihrer Sprache.
 */
final class LanguageController extends FrontController
{
    #[Route(['de' => '/sprachen', 'en' => '/languages'], name: 'languages', methods: ['GET'])]
    public function index(Locales $locales): Response
    {
        return $this->render('front/languages/index.html.twig', ['languages' => $locales->public()]);
    }
}
