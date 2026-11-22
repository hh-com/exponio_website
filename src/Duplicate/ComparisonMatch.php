<?php

namespace App\Duplicate;

use App\Enum\Specimen;

/**
 * Antwort der KI zu einem Kandidaten: dasselbe Objekt? wie sicher? dasselbe Stück? Erhaltung und Fotos besser?
 *
 * „Besser“ misst sich zuerst an der Erhaltung, dann an der Fotoqualität: Ein schlechter erhaltenes Exemplar
 * ersetzt nie ein besser erhaltenes, auch nicht mit schöneren Fotos.
 */
final readonly class ComparisonMatch
{
    public const CONDITIONS = ['better', 'same', 'worse', 'unknown'];
    public const PHOTOS = ['better', 'same', 'worse'];

    public function __construct(
        public int $candidateId,
        public bool $sameObject,
        public float $confidence,
        public Specimen $specimen,
        public string $condition,
        public string $photos,
        public string $reason,
    ) {
    }

    /** @param array<mixed> $data Eintrag aus "matches" der KI-Antwort (oder aus toArray()) */
    public static function fromArray(array $data): ?self
    {
        if (!is_numeric($data['candidate_id'] ?? null)) {
            return null;
        }
        $condition = $data['condition'] ?? null;
        $photos = $data['photos'] ?? null;
        $specimen = $data['specimen'] ?? null;
        $reason = $data['reason'] ?? null;

        return new self(
            (int) $data['candidate_id'],
            true === ($data['same_object'] ?? false),
            is_numeric($data['confidence'] ?? null) ? max(0.0, min(1.0, (float) $data['confidence'])) : 0.0,
            Specimen::tryFrom(\is_string($specimen) ? $specimen : '') ?? Specimen::Unknown,
            \in_array($condition, self::CONDITIONS, true) ? $condition : 'unknown',
            \in_array($photos, self::PHOTOS, true) ? $photos : 'same',
            \is_string($reason) ? trim(mb_substr($reason, 0, 2000)) : '',
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'candidate_id' => $this->candidateId,
            'same_object' => $this->sameObject,
            'confidence' => $this->confidence,
            'specimen' => $this->specimen->value,
            'condition' => $this->condition,
            'photos' => $this->photos,
            'reason' => $this->reason,
        ];
    }

    /** Ist der neue Upload besser? Erhaltung zuerst, bei gleicher (oder unklarer) Erhaltung entscheiden die Fotos. */
    public function isBetter(): bool
    {
        return 'better' === $this->condition || ('worse' !== $this->condition && 'better' === $this->photos);
    }

    /**
     * Zeigen die neuen Fotos ein Exemplar in anderer Erhaltung? Dann wechseln mit den Fotos auch Mängel
     * und Exemplar-Merkmale (Erhaltung, Gummierung, Stempel …).
     */
    public function conditionChanged(): bool
    {
        return \in_array($this->condition, ['better', 'worse'], true) || Specimen::Other === $this->specimen;
    }

    public function conditionLabel(): string
    {
        return match ($this->condition) {
            'better' => 'Erhaltung besser',
            'worse' => 'Erhaltung schlechter',
            'same' => 'Erhaltung gleich',
            default => 'Erhaltung unklar',
        };
    }

    public function photosLabel(): string
    {
        return match ($this->photos) {
            'better' => 'Fotos besser',
            'worse' => 'Fotos schlechter',
            default => 'Fotos gleichwertig',
        };
    }
}
