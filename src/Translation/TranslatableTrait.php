<?php

namespace App\Translation;

use App\Entity\AbstractTranslation;
use App\Entity\Language;
use Doctrine\Common\Collections\Collection;

/**
 * Standard-Umsetzung von TranslatableInterface. Die Klasse braucht eine Eigenschaft
 * "$translations" (OneToMany, indexBy: 'locale') und implementiert createTranslation().
 *
 * @template T of AbstractTranslation
 */
trait TranslatableTrait
{
    /** @return T */
    abstract protected function createTranslation(string $locale): AbstractTranslation;

    /** @return Collection<string, T> */
    public function getTranslations(): Collection
    {
        return $this->translations;
    }

    /** @return T|null */
    public function translation(string $locale): ?AbstractTranslation
    {
        $translation = $this->translations->get($locale);
        if (null !== $translation) {
            return $translation;
        }
        // neu angelegte Einträge haben vor dem Speichern evtl. noch keinen Sprachschlüssel
        foreach ($this->translations as $candidate) {
            if ($candidate->getLocale() === $locale) {
                return $candidate;
            }
        }

        return null;
    }

    /** @return T */
    public function translationFor(string $locale): AbstractTranslation
    {
        $translation = $this->translation($locale);
        if (null === $translation) {
            $translation = $this->createTranslation($locale);
            $this->translations->set($locale, $translation);
        }

        return $translation;
    }

    public function sourceValues(): array
    {
        return $this->translation(Language::SOURCE)?->values() ?? [];
    }

    public function sourceHash(): string
    {
        return sha1((string) json_encode($this->sourceValues(), \JSON_UNESCAPED_UNICODE));
    }

    public function isTranslatedTo(string $locale): bool
    {
        return !($this->translation($locale)?->isEmpty() ?? true);
    }

    /**
     * Für das Backend: alle Übersetzungen außer Deutsch (Deutsch wird in den Hauptfeldern bearbeitet).
     *
     * @return list<T>
     */
    public function getOtherTranslations(): array
    {
        $others = array_values(array_filter($this->translations->toArray(), static fn (AbstractTranslation $t): bool => Language::SOURCE !== $t->getLocale()));
        usort($others, static fn (AbstractTranslation $a, AbstractTranslation $b): int => strcmp($a->getLocale(), $b->getLocale()));

        return $others;
    }

    /**
     * Im Backend entfernte Übersetzungen löschen (sie werden beim nächsten Lauf neu übersetzt).
     *
     * @param iterable<T> $translations
     */
    public function setOtherTranslations(iterable $translations): void
    {
        $keep = [];
        foreach ($translations as $translation) {
            $keep[] = $translation;
        }
        foreach ($this->translations->toArray() as $key => $translation) {
            if (Language::SOURCE !== $translation->getLocale() && !\in_array($translation, $keep, true)) {
                $this->translations->remove($key);
            }
        }
    }

    /** Text eines Feldes in der gewünschten Sprache, sonst der deutsche Text. */
    protected function translated(string $field, ?string $locale): ?string
    {
        $value = null !== $locale ? $this->translation($locale)?->get($field) : null;

        return $value ?? $this->translation(Language::SOURCE)?->get($field);
    }

    /** Setzt den deutschen Ausgangstext eines Feldes. */
    protected function setSourceText(string $field, ?string $value): void
    {
        $this->translationFor(Language::SOURCE)->set($field, $value);
    }
}
