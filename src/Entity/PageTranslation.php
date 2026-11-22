<?php

namespace App\Entity;

use App\Translation\TranslatableInterface;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_page_translation', columns: ['page_id', 'locale'])]
class PageTranslation extends AbstractTranslation
{
    #[ORM\ManyToOne(inversedBy: 'translations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Page $page;

    #[ORM\Column(length: 150, nullable: true)]
    protected ?string $title = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $content = null;

    public function __construct(Page $page, string $locale)
    {
        parent::__construct($locale);
        $this->page = $page;
    }

    public static function fields(): array
    {
        return ['title', 'content'];
    }

    /** @return TranslatableInterface<AbstractTranslation>|null */
    public function getTranslatable(): ?TranslatableInterface
    {
        return $this->page;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        return $this->set('title', $title);
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function setContent(?string $content): static
    {
        return $this->set('content', $content);
    }
}
