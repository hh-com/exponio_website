<?php

namespace App\Routing;

use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\RouteCollection;

/**
 * Macht aus den Routen der öffentlichen Website Routen für alle Sprachen der Welt.
 *
 * Die Controller beschreiben Pfade wie gewohnt mit zwei Varianten:
 *     #[Route(['de' => '/katalog', 'en' => '/catalog'], name: 'catalog')]
 *
 * Daraus entstehen
 * - "catalog.de": /de/katalog (deutsche Pfade, siehe Hauptnavigation)
 * - "catalog":    /{_locale}/catalog für alle anderen Sprachen (englische Pfade, z. B. /fr/catalog)
 *
 * Routen mit nur einem Pfad (z. B. die JSON-API) gelten unter /{_locale}/… für alle Sprachen.
 * Ob eine Sprache freigeschaltet ist, prüft App\EventSubscriber\LocaleSubscriber.
 */
#[AutoconfigureTag('routing.loader')]
final class FrontRouteLoader extends Loader
{
    public const TYPE = 'front';
    /** zweibuchstabige ISO-639-1-Codes, ohne Deutsch (das hat eigene Pfade) */
    private const OTHER_LOCALES = '(?!de)[a-z]{2}';
    private const ANY_LOCALE = '[a-z]{2}';

    public function __construct(
        #[Autowire('%kernel.project_dir%/config')]
        private readonly string $configDir,
        #[Autowire('%kernel.environment%')]
        ?string $env = null,
    ) {
        parent::__construct($env);
    }

    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        // Pfade in config/routes.yaml sind relativ zum Ordner config/
        if (\is_array($resource) && \is_string($resource['path'] ?? null) && !str_starts_with($resource['path'], '/')) {
            $resource['path'] = $this->configDir.'/'.$resource['path'];
        }
        $imported = $this->import($resource, 'attribute');
        $routes = new RouteCollection();

        foreach ($imported->all() as $name => $route) {
            $locale = $route->getDefault('_locale');
            $canonical = $route->getDefault('_canonical_route');
            $route->setDefault('_front', true);

            if (\is_string($canonical) && 'de' === $locale) {
                $route->setPath('/de'.$route->getPath());
                $routes->add($name, $route);
                continue;
            }

            $defaults = $route->getDefaults();
            unset($defaults['_locale'], $defaults['_canonical_route']);
            $route->setDefaults($defaults);
            $route->setPath('/{_locale}'.$route->getPath());
            $route->setRequirement('_locale', \is_string($canonical) ? self::OTHER_LOCALES : self::ANY_LOCALE);
            $routes->add(\is_string($canonical) ? $canonical : $name, $route);
        }

        return $routes;
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return self::TYPE === $type;
    }
}
