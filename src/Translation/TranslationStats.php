<?php

namespace App\Translation;

/**
 * Ergebnis eines Übersetzungslaufs für eine Sprache.
 */
final class TranslationStats
{
    public int $requests = 0;
    public int $uiTranslated = 0;
    public int $uiRemaining = 0;
    public int $contentTranslated = 0;
    public int $contentRemaining = 0;

    public function isComplete(): bool
    {
        return 0 === $this->uiRemaining && 0 === $this->contentRemaining;
    }

    public function summary(): string
    {
        return sprintf(
            '%d Anfragen · Oberfläche: %d übersetzt, %d offen · Inhalte: %d übersetzt, %d offen',
            $this->requests,
            $this->uiTranslated,
            $this->uiRemaining,
            $this->contentTranslated,
            $this->contentRemaining,
        );
    }
}
