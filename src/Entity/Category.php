<?php

namespace App\Entity;

use App\Repository\CategoryRepository;
use App\Translation\NameDescriptionTrait;
use App\Translation\TranslatableInterface;
use App\Translation\TranslatableTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Land, Region oder Epoche als Baum, z. B. Deutschland › DDR.
 * Auf der Website heißt der Bereich „Länder“, im Backend „Länder & Gebiete“.
 *
 * @implements TranslatableInterface<CategoryTranslation>
 */
#[ORM\Entity(repositoryClass: CategoryRepository::class)]
class Category implements \Stringable, TranslatableInterface
{
    /** @use TranslatableTrait<CategoryTranslation> */
    use TranslatableTrait;
    use NameDescriptionTrait;

    private const MAX_DEPTH = 10;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'children')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Category $parent = null;

    /** @var Collection<int, Category> */
    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'parent')]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $children;

    /** @var Collection<string, CategoryTranslation> */
    #[ORM\OneToMany(targetEntity: CategoryTranslation::class, mappedBy: 'category', cascade: ['persist', 'remove'], orphanRemoval: true, indexBy: 'locale')]
    #[Assert\Valid]
    private Collection $translations;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(?string $name = null, ?Category $parent = null)
    {
        $this->parent = $parent;
        $this->children = new ArrayCollection();
        $this->translations = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        if (null !== $name) {
            $this->setName($name);
        }
    }

    public function __toString(): string
    {
        return $this->getPathLabel();
    }

    protected function createTranslation(string $locale): CategoryTranslation
    {
        return new CategoryTranslation($this, $locale);
    }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        if ('' === trim($this->getName())) {
            $context->buildViolation('Bitte einen Namen eingeben.')->atPath('name')->addViolation();
        }
        $node = $this->parent;
        for ($i = 0; null !== $node && $i < self::MAX_DEPTH; ++$i) {
            if ($node === $this) {
                $context->buildViolation('Ein Land kann nicht unter sich selbst oder einem Untergebiet liegen.')
                    ->atPath('parent')
                    ->addViolation();

                return;
            }
            $node = $node->getParent();
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    /** @return list<Category> vom obersten Gebiet bis zu diesem (inklusive) */
    public function getPath(): array
    {
        $path = [];
        $node = $this;
        for ($i = 0; null !== $node && $i < self::MAX_DEPTH; ++$i) {
            array_unshift($path, $node);
            $node = $node->getParent();
        }

        return $path;
    }

    public function getPathLabel(?string $locale = null): string
    {
        return implode(' › ', array_map(static fn (Category $c): string => $c->getName($locale), $this->getPath()));
    }

    public function getDepth(): int
    {
        return \count($this->getPath()) - 1;
    }

    public function getParent(): ?Category
    {
        return $this->parent;
    }

    public function setParent(?Category $parent): static
    {
        $this->parent = $parent;

        return $this;
    }

    /** @return Collection<int, Category> */
    public function getChildren(): Collection
    {
        return $this->children;
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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
