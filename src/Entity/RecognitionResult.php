<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Ergebnis eines Modells bei der letzten Erkennung eines Objekts (eine Zeile je aktivem Modell in Einstellungen → KI).
 *
 * Eines davon steht im Formular (applied): zuerst das der Hauptzeile, unter „Prüfen & freigeben“ lässt sich ein anderes
 * übernehmen. Eine neue Erkennung ersetzt alle Ergebnisse des Objekts.
 */
#[ORM\Entity]
class RecognitionResult
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'recognitions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Item $item;

    /** Reihenfolge der Zeile in den Einstellungen beim Erkennen (0 = Hauptzeile) */
    #[ORM\Column]
    private int $position;

    /** App\Ai\AiProvider (gemini, anthropic, …) */
    #[ORM\Column(length: 20)]
    private string $provider;

    /** angefragtes Modell */
    #[ORM\Column(length: 80)]
    private string $model;

    /** Modellname laut Antwort (z. B. mit Datumsstand) */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $modelUsed = null;

    /** @var array<string, mixed>|null Antwort der KI nach dem Schema aus App\Recognition\PromptBuilder */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $data = null;

    #[ORM\Column(nullable: true)]
    private ?float $confidence = null;

    /** Fehlermeldung, wenn dieses Modell nichts geliefert hat */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $error = null;

    /** Dauer der Anfrage in Millisekunden */
    #[ORM\Column]
    private int $durationMs = 0;

    #[ORM\Column(nullable: true)]
    private ?int $inputTokens = null;

    /** Ausgabe einschließlich Denk-Tokens */
    #[ORM\Column(nullable: true)]
    private ?int $outputTokens = null;

    /** Kosten in US-Dollar aus den Preisen der Zeile beim Erkennen, null ohne Preise */
    #[ORM\Column(nullable: true)]
    private ?float $cost = null;

    /** Dieses Ergebnis steht im Formular */
    #[ORM\Column(options: ['default' => false])]
    private bool $applied = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Item $item, int $position, string $provider, string $model)
    {
        $this->item = $item;
        $this->position = $position;
        $this->provider = $provider;
        $this->model = mb_substr($model, 0, 80);
        $this->createdAt = new \DateTimeImmutable();
    }

    /**
     * @param array<string, mixed> $data
     * @param array{input: int|null, output: int|null} $usage
     */
    public function succeed(array $data, string $modelUsed, array $usage, int $durationMs, ?float $cost): static
    {
        $confidence = $data['confidence'] ?? null;
        $this->data = $data;
        $this->modelUsed = mb_substr($modelUsed, 0, 80);
        $this->confidence = is_numeric($confidence) ? max(0.0, min(1.0, (float) $confidence)) : null;
        $this->inputTokens = $usage['input'];
        $this->outputTokens = $usage['output'];
        $this->durationMs = $durationMs;
        $this->cost = $cost;
        $this->error = null;

        return $this;
    }

    public function fail(string $error, int $durationMs): static
    {
        $this->error = mb_substr($error, 0, 2000);
        $this->durationMs = $durationMs;

        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getItem(): Item
    {
        return $this->item;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getModelUsed(): ?string
    {
        return $this->modelUsed;
    }

    /** @return array<string, mixed>|null */
    public function getData(): ?array
    {
        return $this->data;
    }

    public function isSuccessful(): bool
    {
        return null !== $this->data;
    }

    public function getConfidence(): ?float
    {
        return $this->confidence;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getDurationMs(): int
    {
        return $this->durationMs;
    }

    public function getInputTokens(): ?int
    {
        return $this->inputTokens;
    }

    public function getOutputTokens(): ?int
    {
        return $this->outputTokens;
    }

    public function getCost(): ?float
    {
        return $this->cost;
    }

    public function isApplied(): bool
    {
        return $this->applied;
    }

    public function setApplied(bool $applied): static
    {
        $this->applied = $applied;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
