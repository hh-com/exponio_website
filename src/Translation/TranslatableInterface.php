<?php

namespace App\Translation;

use App\Entity\AbstractTranslation;
use Doctrine\Common\Collections\ReadableCollection;

/**
 * Ein Objekt mit Texten in mehreren Sprachen (Ausgangssprache Deutsch).
 *
 * @template-covariant T of AbstractTranslation
 */
interface TranslatableInterface
{
    public function getId(): ?int;

    /** @return ReadableCollection<string, T> Schlüssel = Sprachcode */
    public function getTranslations(): ReadableCollection;

    /** @return T|null */
    public function translation(string $locale): ?AbstractTranslation;

    /**
     * Liefert die Übersetzung oder legt sie leer an.
     *
     * @return T
     */
    public function translationFor(string $locale): AbstractTranslation;

    /** @return array<string, string> deutsche Texte (Feld => Text) */
    public function sourceValues(): array;

    /** Hash der deutschen Texte; ändert sich, sobald ein deutscher Text geändert wird. */
    public function sourceHash(): string;

    /** Hat das Objekt in dieser Sprache einen eigenen (nicht leeren) Text? */
    public function isTranslatedTo(string $locale): bool;
}
