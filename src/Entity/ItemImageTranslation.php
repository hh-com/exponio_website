<?php

namespace App\Entity;

use App\Translation\TranslatableInterface;
use Doctrine\ORM\Mapping as ORM;

/**
 * Alt-Text eines Fotos in einer Sprache.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_item_image_translation', columns: ['image_id', 'locale'])]
class ItemImageTranslation extends AbstractTranslation
{
    #[ORM\ManyToOne(inversedBy: 'translations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ItemImage $image;

    #[ORM\Column(length: 255, nullable: true)]
    protected ?string $alt = null;

    public function __construct(ItemImage $image, string $locale)
    {
        parent::__construct($locale);
        $this->image = $image;
    }

    public static function fields(): array
    {
        return ['alt'];
    }

    /** @return TranslatableInterface<AbstractTranslation>|null */
    public function getTranslatable(): ?TranslatableInterface
    {
        return $this->image;
    }

    public function getAlt(): ?string
    {
        return $this->alt;
    }

    public function setAlt(?string $alt): static
    {
        return $this->set('alt', $alt);
    }
}
