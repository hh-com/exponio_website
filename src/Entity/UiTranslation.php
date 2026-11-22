<?php

namespace App\Entity;

use App\Enum\TranslationOrigin;
use App\Repository\UiTranslationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Oberflächentext (Menü, Buttons, Hinweise) in einer Sprache ohne eigene Übersetzungsdatei.
 * Quelle ist translations/messages.de.yaml.
 */
#[ORM\Entity(repositoryClass: UiTranslationRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_ui_translation', columns: ['locale', 'message_key'])]
class UiTranslation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 12)]
    private string $locale;

    #[ORM\Column(length: 190)]
    private string $messageKey;

    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank]
    private string $text;

    #[ORM\Column(length: 40)]
    private string $sourceHash;

    #[ORM\Column(length: 10, enumType: TranslationOrigin::class)]
    private TranslationOrigin $origin = TranslationOrigin::Ai;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $locale, string $messageKey, string $text, string $sourceHash)
    {
        $this->locale = $locale;
        $this->messageKey = $messageKey;
        $this->text = $text;
        $this->sourceHash = $sourceHash;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public static function hash(string $sourceText): string
    {
        return sha1($sourceText);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function getMessageKey(): string
    {
        return $this->messageKey;
    }

    public function getText(): string
    {
        return $this->text;
    }

    /** Von Hand geändert (Backend) */
    public function setText(?string $text): static
    {
        $this->text = (string) $text;
        $this->origin = TranslationOrigin::Manual;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    /** Von der KI übersetzt */
    public function applyMachineTranslation(string $text, string $sourceHash): void
    {
        $this->text = $text;
        $this->sourceHash = $sourceHash;
        $this->origin = TranslationOrigin::Ai;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getSourceHash(): string
    {
        return $this->sourceHash;
    }

    public function getOrigin(): TranslationOrigin
    {
        return $this->origin;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
