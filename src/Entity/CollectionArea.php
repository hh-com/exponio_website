<?php

namespace App\Entity;

use App\Repository\CollectionAreaRepository;
use App\Translation\NameDescriptionTrait;
use App\Translation\TranslatableInterface;
use App\Translation\TranslatableTrait;
use App\Util\Slug;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Objektart (Briefmarken, Belege & Ganzsachen, Münzen, …) mit eigenen Merkmalen.
 * Der Klassenname stammt aus der ersten Version („Sammelgebiet“) und bleibt aus Kompatibilität.
 *
 * @implements TranslatableInterface<CollectionAreaTranslation>
 */
#[ORM\Entity(repositoryClass: CollectionAreaRepository::class)]
class CollectionArea implements \Stringable, TranslatableInterface
{
    /** @use TranslatableTrait<CollectionAreaTranslation> */
    use TranslatableTrait;
    use NameDescriptionTrait;

    public const ICONS = ['stamp', 'envelope', 'coin', 'banknote', 'postcard', 'box'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** @var Collection<string, CollectionAreaTranslation> */
    #[ORM\OneToMany(targetEntity: CollectionAreaTranslation::class, mappedBy: 'area', cascade: ['persist', 'remove'], orphanRemoval: true, indexBy: 'locale')]
    #[Assert\Valid]
    private Collection $translations;

    #[ORM\Column(length: 30)]
    #[Assert\Choice(choices: self::ICONS)]
    private string $icon = 'stamp';

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column]
    private bool $active = true;

    /** @var Collection<int, AttributeDefinition> */
    #[ORM\OneToMany(targetEntity: AttributeDefinition::class, mappedBy: 'area', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    #[Assert\Valid]
    private Collection $attributeDefinitions;

    public function __construct(?string $name = null, string $icon = 'stamp')
    {
        $this->translations = new ArrayCollection();
        $this->attributeDefinitions = new ArrayCollection();
        $this->icon = $icon;
        if (null !== $name) {
            $this->setName($name);
        }
    }

    public function __toString(): string
    {
        return $this->getName();
    }

    protected function createTranslation(string $locale): CollectionAreaTranslation
    {
        return new CollectionAreaTranslation($this, $locale);
    }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        if ('' === trim($this->getName())) {
            $context->buildViolation('Bitte einen Namen eingeben.')->atPath('name')->addViolation();
        }
        $seen = [];
        foreach ($this->attributeDefinitions as $definition) {
            $code = (string) $definition->getCode();
            if (isset($seen[$code])) {
                $context->buildViolation(sprintf('Der Merkmal-Code "%s" ist doppelt vergeben.', $code))
                    ->atPath('attributeDefinitions')
                    ->addViolation();
            }
            $seen[$code] = true;
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    /** Stabiler Schlüssel für die KI-Antwort (enum im JSON-Schema). */
    public function getAiKey(): string
    {
        return Slug::make($this->getName()).'-'.$this->id;
    }

    public function getIcon(): string
    {
        return $this->icon;
    }

    public function setIcon(?string $icon): static
    {
        $this->icon = $icon ?? 'stamp';

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

    /** @return Collection<int, AttributeDefinition> */
    public function getAttributeDefinitions(): Collection
    {
        return $this->attributeDefinitions;
    }

    public function addAttributeDefinition(AttributeDefinition $definition): static
    {
        if (!$this->attributeDefinitions->contains($definition)) {
            $this->attributeDefinitions->add($definition);
            $definition->setArea($this);
        }

        return $this;
    }

    public function removeAttributeDefinition(AttributeDefinition $definition): static
    {
        $this->attributeDefinitions->removeElement($definition);

        return $this;
    }

    public function getDefinition(string $code): ?AttributeDefinition
    {
        foreach ($this->attributeDefinitions as $definition) {
            if ($definition->getCode() === $code) {
                return $definition;
            }
        }

        return null;
    }

    /** @return list<AttributeDefinition> */
    public function getFilterableDefinitions(): array
    {
        return array_values(array_filter(
            $this->attributeDefinitions->toArray(),
            static fn (AttributeDefinition $d): bool => $d->isFilterable() && $d->getType()->isFilterable(),
        ));
    }
}
