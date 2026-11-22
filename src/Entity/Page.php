<?php

namespace App\Entity;

use App\Repository\PageRepository;
use App\Translation\TranslatableInterface;
use App\Translation\TranslatableTrait;
use App\Util\Slug;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Inhaltsseite (Impressum, Datenschutz, Über die Sammlung, Barrierefreiheit, …).
 *
 * @implements TranslatableInterface<PageTranslation>
 */
#[ORM\Entity(repositoryClass: PageRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Page implements \Stringable, TranslatableInterface
{
    /** @use TranslatableTrait<PageTranslation> */
    use TranslatableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** @var Collection<string, PageTranslation> */
    #[ORM\OneToMany(targetEntity: PageTranslation::class, mappedBy: 'page', cascade: ['persist', 'remove'], orphanRemoval: true, indexBy: 'locale')]
    #[Assert\Valid]
    private Collection $translations;

    #[ORM\Column]
    private bool $showInFooter = true;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(?string $title = null, ?string $content = null)
    {
        $this->translations = new ArrayCollection();
        $this->updatedAt = new \DateTimeImmutable();
        if (null !== $title) {
            $this->setTitle($title);
        }
        $this->setContent($content);
    }

    public function __toString(): string
    {
        return $this->getTitle();
    }

    protected function createTranslation(string $locale): PageTranslation
    {
        return new PageTranslation($this, $locale);
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        if ('' === trim($this->getTitle())) {
            $context->buildViolation('Bitte einen Titel eingeben.')->atPath('title')->addViolation();
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(?string $locale = null): string
    {
        return (string) $this->translated('title', $locale);
    }

    public function setTitle(?string $title): static
    {
        $this->setSourceText('title', null === $title ? null : mb_substr($title, 0, 150));

        return $this;
    }

    /** HTML-Inhalt (wird bei der Ausgabe bereinigt) */
    public function getContent(?string $locale = null): ?string
    {
        return $this->translated('content', $locale);
    }

    public function setContent(?string $content): static
    {
        $this->setSourceText('content', $content);

        return $this;
    }

    public function getSlug(?string $locale = null): string
    {
        return Slug::make($this->getTitle($locale), 'seite');
    }

    public function isShowInFooter(): bool
    {
        return $this->showInFooter;
    }

    public function setShowInFooter(?bool $showInFooter): static
    {
        $this->showInFooter = (bool) $showInFooter;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(?int $position): static
    {
        $this->position = $position ?? 0;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(?bool $active): static
    {
        $this->active = (bool) $active;

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
