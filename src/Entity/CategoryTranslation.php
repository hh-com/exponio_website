<?php

namespace App\Entity;

use App\Translation\TranslatableInterface;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_category_translation', columns: ['category_id', 'locale'])]
#[ORM\Index(name: 'idx_category_translation_name', columns: ['name'])]
class CategoryTranslation extends NameDescriptionTranslation
{
    #[ORM\ManyToOne(inversedBy: 'translations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Category $category;

    public function __construct(Category $category, string $locale)
    {
        parent::__construct($locale);
        $this->category = $category;
    }

    /** @return TranslatableInterface<AbstractTranslation>|null */
    public function getTranslatable(): ?TranslatableInterface
    {
        return $this->category;
    }
}
