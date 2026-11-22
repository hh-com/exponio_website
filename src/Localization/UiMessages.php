<?php

namespace App\Localization;

use App\Cache\ContentCache;
use App\Entity\Language;
use App\Repository\UiTranslationRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Oberflächentexte einer Sprache.
 *
 * Deutsch und Englisch stehen als Dateien im Repository (translations/messages.*.yaml),
 * alle weiteren Sprachen übersetzt die KI in die Tabelle ui_translation.
 */
final class UiMessages implements ResetInterface
{
    /** @var array<string, array<string, string>> */
    private array $loaded = [];

    public function __construct(
        private readonly UiTranslationRepository $repository,
        private readonly ContentCache $cache,
        #[Autowire(service: 'translator.default')]
        private readonly TranslatorBagInterface $fileTranslator,
    ) {
    }

    /** @return array<string, string> Übersetzungen aus der Datenbank (Schlüssel => Text) */
    public function database(string $locale): array
    {
        if (\in_array($locale, Language::FILE_LOCALES, true)) {
            return [];
        }

        return $this->loaded[$locale] ??= $this->cache->get('ui.'.$locale, fn (): array => $this->repository->messages($locale));
    }

    /** @return array<string, string> deutsche Ausgangstexte aller Schlüssel */
    public function source(): array
    {
        return $this->fileMessages(Language::SOURCE);
    }

    /** @return array<string, string> alle Texte einer Sprache, fehlende auf Deutsch */
    public function all(string $locale): array
    {
        $messages = $this->source();
        if (Language::SOURCE !== $locale) {
            $own = \in_array($locale, Language::FILE_LOCALES, true) ? $this->fileMessages($locale) : $this->database($locale);
            $messages = array_replace($messages, array_intersect_key($own, $messages));
        }

        return $messages;
    }

    /** @return array<string, string> */
    private function fileMessages(string $locale): array
    {
        $messages = [];
        foreach ($this->fileTranslator->getCatalogue($locale)->all('messages') as $key => $text) {
            $messages[(string) $key] = (string) $text;
        }

        return $messages;
    }

    public function reset(): void
    {
        $this->loaded = [];
    }
}
