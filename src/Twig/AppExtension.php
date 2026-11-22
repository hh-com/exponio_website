<?php

namespace App\Twig;

use App\Cache\ContentCache;
use App\Entity\Category;
use App\Entity\CollectionArea;
use App\Entity\Item;
use App\Entity\ItemAttribute;
use App\Entity\ItemImage;
use App\Entity\Language;
use App\Entity\Page;
use App\Entity\Settings;
use App\Entity\Topic;
use App\Enum\AttributeType;
use App\Localization\Locales;
use App\Localization\UiMessages;
use App\Media\BrandAssets;
use App\Repository\CollectionAreaRepository;
use App\Repository\ItemRepository;
use App\Repository\PageRepository;
use App\Service\SettingsProvider;
use App\Translation\TranslatableInterface;
use Symfony\Component\Asset\Packages;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Intl\Languages;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class AppExtension extends AbstractExtension
{
    /** @var list<CollectionArea>|null */
    private ?array $navAreas = null;

    public function __construct(
        private readonly SettingsProvider $settings,
        private readonly CollectionAreaRepository $areas,
        private readonly PageRepository $pages,
        private readonly ItemRepository $items,
        private readonly UrlGeneratorInterface $urls,
        private readonly RequestStack $requestStack,
        private readonly Packages $packages,
        private readonly TranslatorInterface $translator,
        private readonly Vite $vite,
        private readonly Locales $locales,
        private readonly UiMessages $uiMessages,
        private readonly ContentCache $cache,
        private readonly BrandAssets $brand,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('site', fn (): Settings => $this->settings->get()),
            // Logo und daraus erzeugte Dateien (logo, favicon, apple-touch-icon, icon-192, icon-512, og)
            new TwigFunction('brand', $this->brand->url(...)),
            new TwigFunction('locales', $this->locales->public(...)),
            new TwigFunction('locale_dir', fn (?string $locale = null): string => $this->locales->direction($locale ?? $this->locale())),
            new TwigFunction('og_locale', Locales::ogLocale(...)),
            new TwigFunction('nav_areas', $this->navAreas(...)),
            new TwigFunction('area_count', fn (CollectionArea $area): int => $this->areaCounts()[$area->getId()] ?? 0),
            new TwigFunction('footer_pages', fn (): array => $this->pages->findFooterPages()),
            new TwigFunction('item_url', $this->itemUrl(...)),
            new TwigFunction('area_url', fn (CollectionArea $a, bool $abs = false): string => $this->entityUrl('area', $a->getId(), $a->getSlug($this->locale()), $abs)),
            new TwigFunction('category_url', fn (Category $c, bool $abs = false): string => $this->entityUrl('region', $c->getId(), $c->getSlug($this->locale()), $abs)),
            new TwigFunction('topic_url', fn (Topic $t, bool $abs = false): string => $this->entityUrl('topic', $t->getId(), $t->getSlug($this->locale()), $abs)),
            new TwigFunction('page_url', fn (Page $p, bool $abs = false): string => $this->entityUrl('page', $p->getId(), $p->getSlug($this->locale()), $abs)),
            new TwigFunction('img', fn (?ItemImage $image, ?int $size = 800): ?string => null === $image ? null : $this->packages->getUrl($image->getRelativePath($size))),
            new TwigFunction('alternates', $this->alternates(...)),
            new TwigFunction('alt_url', $this->alternateUrl(...)),
            new TwigFunction('fallback_lang', $this->fallbackLang(...)),
            new TwigFunction('icon', Icons::svg(...), ['is_safe' => ['html']]),
            new TwigFunction('vite', $this->vite->tags(...), ['is_safe' => ['html']]),
            new TwigFunction('vue_messages', $this->vueMessages(...)),
            new TwigFunction('card_meta', $this->cardMeta(...)),
            new TwigFunction('json_ld', $this->jsonLd(...), ['is_safe' => ['html']]),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('money', $this->money(...)),
            new TwigFilter('attr_value', $this->attributeValue(...)),
            new TwigFilter('date_local', $this->dateLocal(...)),
            new TwigFilter('number_local', $this->numberLocal(...)),
            new TwigFilter('language_label', fn (string $code): string => Languages::exists($code) ? Languages::getName($code, $this->locale()) : $code),
        ];
    }

    /** @return list<CollectionArea> */
    public function navAreas(): array
    {
        return $this->navAreas ??= $this->areas->findActive();
    }

    public function itemUrl(Item $item, bool $absolute = false, ?string $locale = null): string
    {
        $locale ??= $this->locale();

        return $this->urls->generate('item', [
            '_locale' => $locale,
            'id' => $item->getId(),
            'slug' => $item->getSlug($locale),
        ], $absolute ? UrlGeneratorInterface::ABSOLUTE_URL : UrlGeneratorInterface::ABSOLUTE_PATH);
    }

    public function entityUrl(string $route, ?int $id, string $slug, bool $absolute = false, ?string $locale = null): string
    {
        return $this->urls->generate($route, ['_locale' => $locale ?? $this->locale(), 'id' => $id, 'slug' => $slug], $absolute ? UrlGeneratorInterface::ABSOLUTE_URL : UrlGeneratorInterface::ABSOLUTE_PATH);
    }

    public function money(string|float|int|null $amount, ?string $currency = null): string
    {
        if (null === $amount || '' === $amount) {
            return '';
        }
        $formatter = new \NumberFormatter($this->locale(), \NumberFormatter::CURRENCY);

        return (string) $formatter->formatCurrency((float) $amount, $currency ?? $this->settings->get()->getCurrency());
    }

    public function numberLocal(string|float|int|null $number): string
    {
        if (null === $number || '' === $number) {
            return '';
        }

        return (string) (new \NumberFormatter($this->locale(), \NumberFormatter::DECIMAL))->format((float) $number);
    }

    public function attributeValue(ItemAttribute $attribute): string
    {
        $definition = $attribute->getDefinition();
        $value = $attribute->getValue();
        $locale = $this->locale();
        $unit = $definition->getUnit() ? ' '.$definition->getUnit() : '';

        return match ($definition->getType()) {
            AttributeType::Select => $definition->getOptionLabel($value, $locale),
            AttributeType::Boolean => $this->translator->trans('1' === $value ? 'common.yes' : 'common.no', [], 'messages', $locale),
            AttributeType::Number => (is_numeric($value) ? $this->numberLocal($value) : $value).$unit,
            AttributeType::Text => $value.$unit,
        };
    }

    public function dateLocal(?\DateTimeInterface $date): string
    {
        if (null === $date) {
            return '';
        }
        $formatter = new \IntlDateFormatter($this->locale(), \IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE);

        return (string) $formatter->format($date);
    }

    /**
     * URLs der aktuellen Seite in allen öffentlichen Sprachen (für hreflang und Sprachwahl).
     * Controller können über das Request-Attribut "_alternates" (Sprache => URL) eigene URLs
     * setzen – z. B. wegen übersetzter Slugs oder weil ein Objekt nicht in allen Sprachen übersetzt ist.
     *
     * @return array<string, string>
     */
    public function alternates(): array
    {
        $request = $this->requestStack->getMainRequest();
        $alternates = $request?->attributes->get('_alternates');
        if (\is_array($alternates)) {
            return $alternates;
        }
        if (null === $request?->attributes->get('_locale')) {
            return [];
        }
        $result = [];
        foreach ($this->locales->codes() as $code) {
            $result[$code] = $this->alternateUrl($code);
        }

        return $result;
    }

    /** URL der aktuellen Seite in einer anderen Sprache */
    public function alternateUrl(string $locale): string
    {
        $request = $this->requestStack->getMainRequest();
        if (null === $request) {
            return '/'.$locale.'/';
        }
        $alternates = $request->attributes->get('_alternates', []);
        if (\is_array($alternates) && isset($alternates[$locale])) {
            return $alternates[$locale];
        }
        $route = $request->attributes->get('_canonical_route') ?? $request->attributes->get('_route');
        if (!\is_string($route) || null === $request->attributes->get('_locale')) {
            return $this->urls->generate('home', ['_locale' => $locale]);
        }
        $params = $request->attributes->get('_route_params', []);
        $params['_locale'] = $locale;

        try {
            return $this->urls->generate($route, $params + $request->query->all());
        } catch (\Throwable) {
            return $this->urls->generate('home', ['_locale' => $locale]);
        }
    }

    /**
     * Sprache eines Inhalts, der mangels Übersetzung auf Deutsch angezeigt wird (sonst null).
     * Für das lang-Attribut, damit Screenreader die richtige Aussprache wählen.
     */
    /** @param TranslatableInterface<\App\Entity\AbstractTranslation> $entity */
    public function fallbackLang(TranslatableInterface $entity): ?string
    {
        $locale = $this->locale();

        return Language::SOURCE !== $locale && !$entity->isTranslatedTo($locale) ? Language::SOURCE : null;
    }

    /** Kurzinfo für Karten aus den Merkmalen mit „In Karte anzeigen“, z. B. „Michel-Nr. 213 · postfrisch (**)“ */
    public function cardMeta(Item $item): string
    {
        $parts = [];
        foreach ($item->getCardAttributes() as $attribute) {
            $value = $this->attributeValue($attribute);
            $parts[] = AttributeType::Select === $attribute->getDefinition()->getType()
                ? $value
                : $attribute->getDefinition()->getLabel($this->locale()).' '.$value;
        }

        return implode(' · ', $parts);
    }

    /** @return array<string, string> alle Oberflächentexte unter "vue.*" (ohne Präfix) */
    public function vueMessages(): array
    {
        $messages = [];
        foreach ($this->uiMessages->all($this->locale()) as $key => $text) {
            if (str_starts_with($key, 'vue.')) {
                $messages[substr($key, 4)] = $text;
            }
        }

        return $messages;
    }

    /** @param array<string, mixed> $data strukturierte Daten (schema.org) als sicheres <script>-Element */
    public function jsonLd(array $data): string
    {
        $data = ['@context' => 'https://schema.org'] + self::withoutEmpty($data);

        return '<script type="application/ld+json">'.json_encode($data, \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE).'</script>';
    }

    /** @return array<int, int> Objektart-ID => Anzahl veröffentlichter Objekte */
    private function areaCounts(): array
    {
        return $this->cache->get('counts.areas', fn (): array => $this->items->countPublishedByArea());
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    private static function withoutEmpty(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            if (\is_array($value)) {
                $value = self::withoutEmpty($value);
            }
            if (null !== $value && '' !== $value && [] !== $value) {
                $result[$key] = $value;
            }
        }

        return array_is_list($data) ? array_values($result) : $result;
    }

    private function locale(): string
    {
        return $this->requestStack->getCurrentRequest()?->getLocale() ?? Language::SOURCE;
    }
}
