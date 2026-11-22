<?php

namespace App\Translation;

use App\Util\Slug;

/**
 * Name, Beschreibung und Slug für Objektart, Land und Thema (ohne Sprache = Deutsch).
 * Setzt TranslatableTrait voraus.
 */
trait NameDescriptionTrait
{
    public function getName(?string $locale = null): string
    {
        return (string) $this->translated('name', $locale);
    }

    public function setName(?string $name): static
    {
        $this->setSourceText('name', null === $name ? null : mb_substr($name, 0, 150));

        return $this;
    }

    public function getDescription(?string $locale = null): ?string
    {
        return $this->translated('description', $locale);
    }

    public function setDescription(?string $description): static
    {
        $this->setSourceText('description', $description);

        return $this;
    }

    public function getSlug(?string $locale = null): string
    {
        return Slug::make($this->getName($locale));
    }
}
