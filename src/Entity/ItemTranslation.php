<?php

namespace App\Entity;

use App\Translation\TranslatableInterface;
use Doctrine\ORM\Mapping as ORM;

/**
 * Texte eines Objekts in einer Sprache.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_item_translation', columns: ['item_id', 'locale'])]
class ItemTranslation extends AbstractTranslation
{
    #[ORM\ManyToOne(inversedBy: 'translations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Item $item;

    #[ORM\Column(length: 255, nullable: true)]
    protected ?string $title = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $description = null;

    #[ORM\Column(length: 255, nullable: true)]
    protected ?string $series = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $defects = null;

    #[ORM\Column(length: 300, nullable: true)]
    protected ?string $metaDescription = null;

    public function __construct(Item $item, string $locale)
    {
        parent::__construct($locale);
        $this->item = $item;
    }

    public static function fields(): array
    {
        return ['title', 'description', 'series', 'defects', 'metaDescription'];
    }

    /** @return TranslatableInterface<AbstractTranslation>|null */
    public function getTranslatable(): ?TranslatableInterface
    {
        return $this->item;
    }

    public function getItem(): ?Item
    {
        return $this->item;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        return $this->set('title', $title);
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        return $this->set('description', $description);
    }

    public function getSeries(): ?string
    {
        return $this->series;
    }

    public function setSeries(?string $series): static
    {
        return $this->set('series', $series);
    }

    public function getDefects(): ?string
    {
        return $this->defects;
    }

    public function setDefects(?string $defects): static
    {
        return $this->set('defects', $defects);
    }

    public function getMetaDescription(): ?string
    {
        return $this->metaDescription;
    }

    public function setMetaDescription(?string $metaDescription): static
    {
        return $this->set('metaDescription', $metaDescription);
    }
}
