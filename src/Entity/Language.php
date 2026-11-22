<?php

namespace App\Entity;

use App\Repository\LanguageRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Eine Sprache der Welt (ISO 639-1). Die Tabelle enthält alle Sprachen; im Backend
 * werden die gewünschten freigeschaltet. Öffentlich sichtbar ist eine Sprache erst,
 * wenn ihre Oberflächentexte vollständig übersetzt sind.
 */
#[ORM\Entity(repositoryClass: LanguageRepository::class)]
#[ORM\Index(name: 'idx_language_active', columns: ['active', 'position'])]
class Language implements \Stringable
{
    /** Ausgangssprache aller Inhalte */
    public const SOURCE = 'de';

    /** Sprachen, deren Oberflächentexte als Datei im Repository liegen (translations/messages.*.yaml) */
    public const FILE_LOCALES = ['de', 'en'];

    /** Sprachen mit Schrift von rechts nach links */
    public const RTL = ['ar', 'dv', 'fa', 'he', 'ps', 'sd', 'ug', 'ur', 'yi'];

    #[ORM\Id]
    #[ORM\Column(length: 12)]
    private string $code;

    /** Name in der Sprache selbst, z. B. „Français“ */
    #[ORM\Column(length: 80)]
    private string $nativeName;

    /** Name auf Deutsch, z. B. „Französisch“ */
    #[ORM\Column(length: 80)]
    private string $nameDe;

    #[ORM\Column]
    private bool $rtl;

    #[ORM\Column]
    private bool $active = false;

    #[ORM\Column]
    private int $position = 100;

    /** Zeitpunkt, an dem alle Oberflächentexte übersetzt waren (null = noch unvollständig) */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $uiTranslatedAt = null;

    public function __construct(string $code, string $nativeName, string $nameDe)
    {
        $this->code = $code;
        $this->nativeName = $nativeName;
        $this->nameDe = $nameDe;
        $this->rtl = \in_array($code, self::RTL, true);
    }

    public function __toString(): string
    {
        return $this->nameDe.' ('.$this->code.')';
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getNativeName(): string
    {
        return $this->nativeName;
    }

    public function getNameDe(): string
    {
        return $this->nameDe;
    }

    public function isRtl(): bool
    {
        return $this->rtl;
    }

    public function getDirection(): string
    {
        return $this->rtl ? 'rtl' : 'ltr';
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(?bool $active): static
    {
        $this->active = self::SOURCE === $this->code || (bool) $active;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(?int $position): static
    {
        $this->position = $position ?? 100;

        return $this;
    }

    public function getUiTranslatedAt(): ?\DateTimeImmutable
    {
        return $this->uiTranslatedAt;
    }

    public function setUiTranslatedAt(?\DateTimeImmutable $uiTranslatedAt): static
    {
        $this->uiTranslatedAt = $uiTranslatedAt;

        return $this;
    }

    public function hasUiFiles(): bool
    {
        return \in_array($this->code, self::FILE_LOCALES, true);
    }

    public function isSource(): bool
    {
        return self::SOURCE === $this->code;
    }

    /** Aktiv und Oberfläche vollständig übersetzt */
    public function isPublic(): bool
    {
        return $this->active && ($this->hasUiFiles() || null !== $this->uiTranslatedAt);
    }
}
