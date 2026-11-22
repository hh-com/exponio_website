<?php

namespace App\Localization;

use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\HttpKernel\CacheWarmer\WarmableInterface;
use Symfony\Component\Translation\Formatter\MessageFormatter;
use Symfony\Component\Translation\MessageCatalogueInterface;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Ergänzt den Symfony-Übersetzer um die KI-übersetzten Oberflächentexte aus der Datenbank.
 * Für Deutsch und Englisch ändert sich nichts; fehlt ein Text, gilt der deutsche.
 */
#[AsDecorator('translator')]
final class DatabaseTranslator implements TranslatorInterface, TranslatorBagInterface, LocaleAwareInterface, WarmableInterface
{
    private readonly MessageFormatter $formatter;

    public function __construct(
        #[AutowireDecorated]
        private readonly TranslatorInterface&TranslatorBagInterface&LocaleAwareInterface $inner,
        private readonly UiMessages $messages,
    ) {
        $this->formatter = new MessageFormatter();
    }

    /** @param array<string, mixed> $parameters */
    public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        $locale ??= $this->getLocale();
        if (null === $domain || 'messages' === $domain) {
            $message = $this->messages->database($locale)[$id] ?? null;
            if (null !== $message) {
                return $this->formatter->format($message, $locale, $parameters);
            }
        }

        return $this->inner->trans($id, $parameters, $domain, $locale);
    }

    public function getCatalogue(?string $locale = null): MessageCatalogueInterface
    {
        return $this->inner->getCatalogue($locale);
    }

    public function getCatalogues(): array
    {
        return $this->inner->getCatalogues();
    }

    public function setLocale(string $locale): void
    {
        $this->inner->setLocale($locale);
    }

    public function getLocale(): string
    {
        return $this->inner->getLocale();
    }

    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        return $this->inner instanceof WarmableInterface ? $this->inner->warmUp($cacheDir, $buildDir) : [];
    }
}
