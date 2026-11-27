<?php

namespace App\Ai;

/**
 * Eine Zeile „Modelle für die Erkennung“ (Einstellungen → KI): Anbieter, Modell, aktiv und optional die Preise.
 *
 * Jede aktive Zeile bestimmt jedes Objekt; die oberste aktive ist die Hauptzeile (siehe AiConfig::recognizers()).
 * Gespeichert wird die Liste als JSON in Settings::$aiRecognizers (toArray()/fromArray()).
 */
final readonly class RecognizerConfig
{
    public function __construct(
        public string $provider,
        /** null = Standardmodell des Anbieters aus .env */
        public ?string $model = null,
        public bool $active = true,
        /** US-Dollar je Million Eingabe-Tokens, null = unbekannt */
        public ?float $priceInput = null,
        /** US-Dollar je Million Ausgabe-Tokens (einschließlich Denk-Tokens), null = unbekannt */
        public ?float $priceOutput = null,
    ) {
    }

    /** @param array<mixed> $row */
    public static function fromArray(array $row): ?self
    {
        $provider = \is_string($row['provider'] ?? null) ? strtolower(trim($row['provider'])) : '';
        if (!\in_array($provider, AiProvider::CHOICES, true)) {
            return null;
        }
        $model = \is_string($row['model'] ?? null) ? trim($row['model']) : '';

        return new self(
            $provider,
            '' === $model ? null : mb_substr($model, 0, 80),
            (bool) ($row['active'] ?? false),
            self::price($row['priceInput'] ?? null),
            self::price($row['priceOutput'] ?? null),
        );
    }

    /** @return array{provider: string, model: string|null, active: bool, priceInput: float|null, priceOutput: float|null} */
    public function toArray(): array
    {
        return ['provider' => $this->provider, 'model' => $this->model, 'active' => $this->active, 'priceInput' => $this->priceInput, 'priceOutput' => $this->priceOutput];
    }

    public function withModel(string $model): self
    {
        return new self($this->provider, $model, $this->active, $this->priceInput, $this->priceOutput);
    }

    /** Kosten einer Anfrage in US-Dollar, null ohne Preise oder Token-Zahlen */
    public function cost(?int $inputTokens, ?int $outputTokens): ?float
    {
        if (null === $this->priceInput || null === $this->priceOutput || null === $inputTokens || null === $outputTokens) {
            return null;
        }

        return ($inputTokens * $this->priceInput + $outputTokens * $this->priceOutput) / 1_000_000;
    }

    private static function price(mixed $value): ?float
    {
        if (\is_string($value)) {
            $value = str_replace(',', '.', trim($value));
        }

        return is_numeric($value) && $value >= 0 ? (float) $value : null;
    }
}
