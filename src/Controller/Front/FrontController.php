<?php

namespace App\Controller\Front;

use App\Localization\Locales;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;

/**
 * Gemeinsame Helfer der öffentlichen Seiten.
 */
abstract class FrontController extends AbstractController
{
    public static function getSubscribedServices(): array
    {
        return parent::getSubscribedServices() + [Locales::class => Locales::class];
    }

    /**
     * Setzt die URLs dieser Seite in allen öffentlichen Sprachen (für Sprachwahl und hreflang).
     *
     * @param callable(string): string $urlForLocale
     * @param callable(string): bool   $exists       gibt es die Seite in dieser Sprache (z. B. übersetzt)?
     */
    protected function setAlternates(Request $request, callable $urlForLocale, ?callable $exists = null): void
    {
        $alternates = [];
        foreach ($this->container->get(Locales::class)->codes() as $locale) {
            if (null === $exists || $exists($locale)) {
                $alternates[$locale] = $urlForLocale($locale);
            }
        }
        $request->attributes->set('_alternates', $alternates);
    }

    protected function wantsJson(Request $request): bool
    {
        return \in_array('application/json', $request->getAcceptableContentTypes(), true);
    }
}
