<?php

namespace App\Entity;

use App\Enum\TranslationOrigin;
use App\Translation\TranslatableInterface;
use Doctrine\ORM\Mapping as ORM;

/**
 * Gemeinsame Felder aller Übersetzungstabellen (eine Zeile je Objekt und Sprache).
 *
 * Deutsch ist die Ausgangssprache und liegt ebenfalls in diesen Tabellen.
 * "sourceHash" merkt sich, aus welchem deutschen Text übersetzt wurde: Ändert sich der
 * deutsche Text, passt der Hash nicht mehr und die Übersetzung gilt als veraltet.
 */
#[ORM\MappedSuperclass]
abstract class AbstractTranslation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 12)]
    private string $locale;

    #[ORM\Column(length: 10, enumType: TranslationOrigin::class)]
    private TranslationOrigin $origin;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $sourceHash = null;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $locale)
    {
        $this->locale = $locale;
        $this->origin = Language::SOURCE === $locale ? TranslationOrigin::Source : TranslationOrigin::Manual;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /** @return list<string> Namen der übersetzbaren Textfelder */
    abstract public static function fields(): array;

    /** @return TranslatableInterface<AbstractTranslation>|null */
    abstract public function getTranslatable(): ?TranslatableInterface;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function getOrigin(): TranslationOrigin
    {
        return $this->origin;
    }

    public function getSourceHash(): ?string
    {
        return $this->sourceHash;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function get(string $field): ?string
    {
        $this->assertField($field);

        return $this->{$field};
    }

    public function set(string $field, ?string $value): static
    {
        $this->assertField($field);
        $value = null === $value ? null : trim($value);
        $this->{$field} = '' === $value ? null : $value;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    /**
     * Nicht-leere Textwerte dieser Übersetzung.
     *
     * @return array<string, string>
     */
    public function values(): array
    {
        $values = [];
        foreach (static::fields() as $field) {
            $value = $this->get($field);
            if (null !== $value && '' !== $value) {
                $values[$field] = $value;
            }
        }

        return $values;
    }

    /**
     * Übernimmt eine Übersetzung der KI für alle Felder.
     *
     * @param array<string, string> $values
     */
    public function applyMachineTranslation(array $values, string $sourceHash): void
    {
        foreach (static::fields() as $field) {
            $this->set($field, $values[$field] ?? null);
        }
        $this->origin = TranslationOrigin::Ai;
        $this->sourceHash = $sourceHash;
    }

    /** Von Hand geändert: gilt als aktuell zum angegebenen deutschen Text. */
    public function markManual(string $sourceHash): void
    {
        if (TranslationOrigin::Source === $this->origin) {
            return;
        }
        $this->origin = TranslationOrigin::Manual;
        $this->sourceHash = $sourceHash;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function isCurrent(string $sourceHash): bool
    {
        return TranslationOrigin::Source === $this->origin || $this->sourceHash === $sourceHash;
    }

    public function isEmpty(): bool
    {
        return [] === $this->values();
    }

    private function assertField(string $field): void
    {
        if (!\in_array($field, static::fields(), true)) {
            throw new \InvalidArgumentException(sprintf('Unbekanntes Feld "%s" in %s.', $field, static::class));
        }
    }
}
