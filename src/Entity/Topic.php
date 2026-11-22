<?php

namespace App\Entity;

use App\Repository\TopicRepository;
use App\Translation\NameDescriptionTrait;
use App\Translation\TranslatableInterface;
use App\Translation\TranslatableTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Thema / Motiv (Tiere, Sport, Schiffe, …). Ein Objekt kann mehrere Themen haben.
 *
 * @implements TranslatableInterface<TopicTranslation>
 */
#[ORM\Entity(repositoryClass: TopicRepository::class)]
class Topic implements \Stringable, TranslatableInterface
{
    /** @use TranslatableTrait<TopicTranslation> */
    use TranslatableTrait;
    use NameDescriptionTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** @var Collection<string, TopicTranslation> */
    #[ORM\OneToMany(targetEntity: TopicTranslation::class, mappedBy: 'topic', cascade: ['persist', 'remove'], orphanRemoval: true, indexBy: 'locale')]
    #[Assert\Valid]
    private Collection $translations;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column]
    private bool $active = true;

    public function __construct(?string $name = null)
    {
        $this->translations = new ArrayCollection();
        if (null !== $name) {
            $this->setName($name);
        }
    }

    public function __toString(): string
    {
        return $this->getName();
    }

    protected function createTranslation(string $locale): TopicTranslation
    {
        return new TopicTranslation($this, $locale);
    }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        if ('' === trim($this->getName())) {
            $context->buildViolation('Bitte einen Namen eingeben.')->atPath('name')->addViolation();
        }
    }

    public function getId(): ?int
    {
        return $this->id;
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
}
