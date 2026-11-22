<?php

namespace App\Entity;

use App\Translation\TranslatableInterface;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_collection_area_translation', columns: ['area_id', 'locale'])]
class CollectionAreaTranslation extends NameDescriptionTranslation
{
    #[ORM\ManyToOne(inversedBy: 'translations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?CollectionArea $area;

    public function __construct(CollectionArea $area, string $locale)
    {
        parent::__construct($locale);
        $this->area = $area;
    }

    /** @return TranslatableInterface<AbstractTranslation>|null */
    public function getTranslatable(): ?TranslatableInterface
    {
        return $this->area;
    }
}
