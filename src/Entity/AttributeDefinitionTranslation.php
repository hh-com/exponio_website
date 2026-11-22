<?php

namespace App\Entity;

use App\Translation\TranslatableInterface;
use Doctrine\ORM\Mapping as ORM;

/**
 * Bezeichnung eines Merkmals und Beschriftungen seiner Auswahlwerte in einer Sprache.
 * Für die KI-Übersetzung werden die Beschriftungen als "option:<wert>" geführt.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_attribute_definition_translation', columns: ['definition_id', 'locale'])]
class AttributeDefinitionTranslation extends AbstractTranslation
{
    private const OPTION_PREFIX = 'option:';

    #[ORM\ManyToOne(inversedBy: 'translations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?AttributeDefinition $definition;

    #[ORM\Column(length: 120, nullable: true)]
    protected ?string $label = null;

    /** @var array<string, string> Wert => Beschriftung */
    #[ORM\Column(type: 'json')]
    private array $optionLabels = [];

    public function __construct(AttributeDefinition $definition, string $locale)
    {
        parent::__construct($locale);
        $this->definition = $definition;
    }

    public static function fields(): array
    {
        return ['label'];
    }

    /** @return TranslatableInterface<AbstractTranslation>|null */
    public function getTranslatable(): ?TranslatableInterface
    {
        return $this->definition;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): static
    {
        return $this->set('label', $label);
    }

    public function getOptionLabel(string $value): ?string
    {
        $label = $this->optionLabels[$value] ?? null;

        return null === $label || '' === $label ? null : $label;
    }

    /** @return array<string, string> */
    public function getOptionLabels(): array
    {
        return $this->optionLabels;
    }

    /** @param array<string, string> $labels Wert => Beschriftung */
    public function setOptionLabels(array $labels): static
    {
        $this->optionLabels = array_filter(array_map(static fn ($l): string => trim((string) $l), $labels), static fn (string $l): bool => '' !== $l);

        return $this;
    }

    /** Für das Backend: eine Zeile pro Option, "wert | Beschriftung". */
    public function getOptionLabelsText(): string
    {
        $lines = [];
        foreach ($this->optionLabels as $value => $label) {
            $lines[] = $value.' | '.$label;
        }

        return implode("\n", $lines);
    }

    public function setOptionLabelsText(?string $text): static
    {
        $labels = [];
        foreach (preg_split('/\R/', (string) $text) ?: [] as $line) {
            $parts = array_map('trim', explode('|', $line, 2));
            if ('' !== $parts[0] && '' !== ($parts[1] ?? '')) {
                $labels[$parts[0]] = $parts[1];
            }
        }

        return $this->setOptionLabels($labels);
    }

    public function values(): array
    {
        $values = parent::values();
        foreach ($this->optionLabels as $value => $label) {
            $values[self::OPTION_PREFIX.$value] = $label;
        }

        return $values;
    }

    public function applyMachineTranslation(array $values, string $sourceHash): void
    {
        $options = [];
        foreach ($values as $key => $text) {
            if (str_starts_with($key, self::OPTION_PREFIX)) {
                $options[substr($key, \strlen(self::OPTION_PREFIX))] = $text;
            }
        }
        $this->setOptionLabels($options);
        parent::applyMachineTranslation($values, $sourceHash);
    }
}
