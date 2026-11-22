<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Gemeinsame Felder für Übersetzungen mit Name und Beschreibung (Objektart, Land, Thema).
 */
#[ORM\MappedSuperclass]
abstract class NameDescriptionTranslation extends AbstractTranslation
{
    #[ORM\Column(length: 150, nullable: true)]
    protected ?string $name = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $description = null;

    public static function fields(): array
    {
        return ['name', 'description'];
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        return $this->set('name', null === $name ? null : mb_substr($name, 0, 150));
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        return $this->set('description', $description);
    }
}
