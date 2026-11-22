<?php

namespace App\Entity;

use App\Enum\AttributeType;
use App\Translation\TranslatableInterface;
use App\Translation\TranslatableTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Ein Merkmal einer Objektart, z. B. „Michel-Nr.“ oder „Erhaltung“ bei Briefmarken.
 * Bezeichnung und Beschriftungen der Auswahlwerte liegen je Sprache in AttributeDefinitionTranslation,
 * die technischen Werte (z. B. "mnh") hier in $options.
 *
 * @implements TranslatableInterface<AttributeDefinitionTranslation>
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_area_code', columns: ['area_id', 'code'])]
class AttributeDefinition implements \Stringable, TranslatableInterface
{
    /** @use TranslatableTrait<AttributeDefinitionTranslation> */
    use TranslatableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'attributeDefinitions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?CollectionArea $area = null;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^[a-z][a-z0-9_]{0,49}$/', message: 'Nur Kleinbuchstaben, Ziffern und _ (beginnt mit Buchstabe).')]
    private ?string $code = null;

    /** @var Collection<string, AttributeDefinitionTranslation> */
    #[ORM\OneToMany(targetEntity: AttributeDefinitionTranslation::class, mappedBy: 'definition', cascade: ['persist', 'remove'], orphanRemoval: true, indexBy: 'locale')]
    private Collection $translations;

    #[ORM\Column(length: 20, enumType: AttributeType::class)]
    private AttributeType $type = AttributeType::Text;

    /** @var list<string> technische Werte der Auswahl, z. B. ["mnh", "used"] */
    #[ORM\Column(type: 'json')]
    private array $options = [];

    #[ORM\Column]
    private bool $filterable = false;

    #[ORM\Column]
    private bool $showInCard = false;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $unit = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $aiHint = null;

    /**
     * @param array<string, string> $options Wert => deutsche Beschriftung
     */
    public function __construct(
        ?string $code = null,
        ?string $label = null,
        AttributeType $type = AttributeType::Text,
        array $options = [],
        bool $filterable = false,
        bool $showInCard = false,
        ?string $aiHint = null,
        ?string $unit = null,
    ) {
        $this->translations = new ArrayCollection();
        $this->code = $code;
        $this->type = $type;
        $this->filterable = $filterable;
        $this->showInCard = $showInCard;
        $this->aiHint = $aiHint;
        $this->unit = $unit;
        $this->setLabel($label);
        $this->setOptionLabels($options);
    }

    public function __toString(): string
    {
        return $this->getLabel();
    }

    protected function createTranslation(string $locale): AttributeDefinitionTranslation
    {
        return new AttributeDefinitionTranslation($this, $locale);
    }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        if ('' === trim($this->getLabel())) {
            $context->buildViolation('Bitte eine Bezeichnung eingeben.')->atPath('label')->addViolation();
        }
        if (AttributeType::Select === $this->type && [] === $this->options) {
            $context->buildViolation('Auswahlfelder brauchen mindestens eine Option.')
                ->atPath('optionsText')
                ->addViolation();
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getArea(): ?CollectionArea
    {
        return $this->area;
    }

    public function setArea(?CollectionArea $area): static
    {
        $this->area = $area;

        return $this;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(?string $code): static
    {
        $this->code = null === $code ? null : strtolower(trim($code));

        return $this;
    }

    public function getLabel(?string $locale = null): string
    {
        return (string) $this->translated('label', $locale);
    }

    public function setLabel(?string $label): static
    {
        $this->setSourceText('label', null === $label ? null : mb_substr($label, 0, 120));

        return $this;
    }

    public function getType(): AttributeType
    {
        return $this->type;
    }

    public function setType(?AttributeType $type): static
    {
        $this->type = $type ?? AttributeType::Text;

        return $this;
    }

    /** @return list<string> */
    public function getOptionValues(): array
    {
        return $this->options;
    }

    /** @return list<array{value: string, label: string}> Optionen mit Beschriftung (Standard: Deutsch) */
    public function getOptions(?string $locale = null): array
    {
        return array_map(fn (string $value): array => ['value' => $value, 'label' => $this->getOptionLabel($value, $locale)], $this->options);
    }

    public function hasOption(string $value): bool
    {
        return \in_array($value, $this->options, true);
    }

    public function getOptionLabel(string $value, ?string $locale = null): string
    {
        $label = null !== $locale ? $this->translation($locale)?->getOptionLabel($value) : null;
        $label ??= $this->translation(Language::SOURCE)?->getOptionLabel($value);

        return $label ?? $value;
    }

    /**
     * Setzt Werte und deutsche Beschriftungen der Auswahl.
     *
     * @param array<string, string> $options Wert => Beschriftung
     */
    public function setOptionLabels(array $options): static
    {
        $this->options = array_map('strval', array_keys($options));
        $this->translationFor(Language::SOURCE)->setOptionLabels($options);

        return $this;
    }

    /** Optionen als Text für das Backend: eine Zeile pro Option, "wert | Beschriftung". */
    public function getOptionsText(): string
    {
        return implode("\n", array_map(
            static fn (array $o): string => $o['value'].' | '.$o['label'],
            $this->getOptions(),
        ));
    }

    public function setOptionsText(?string $text): static
    {
        $options = [];
        foreach (preg_split('/\R/', (string) $text) ?: [] as $line) {
            $parts = array_map('trim', explode('|', $line));
            if ('' === $parts[0]) {
                continue;
            }
            $value = trim(strtolower((string) preg_replace('/[^a-z0-9_]+/i', '_', $parts[0])), '_');
            $options[$value] = ($parts[1] ?? '') ?: $parts[0];
        }

        return $this->setOptionLabels($options);
    }

    public function isFilterable(): bool
    {
        return $this->filterable;
    }

    public function setFilterable(?bool $filterable): static
    {
        $this->filterable = (bool) $filterable;

        return $this;
    }

    public function isShowInCard(): bool
    {
        return $this->showInCard;
    }

    public function setShowInCard(?bool $showInCard): static
    {
        $this->showInCard = (bool) $showInCard;

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

    public function getUnit(): ?string
    {
        return $this->unit;
    }

    public function setUnit(?string $unit): static
    {
        $this->unit = $unit;

        return $this;
    }

    public function getAiHint(): ?string
    {
        return $this->aiHint;
    }

    public function setAiHint(?string $aiHint): static
    {
        $this->aiHint = $aiHint;

        return $this;
    }
}
