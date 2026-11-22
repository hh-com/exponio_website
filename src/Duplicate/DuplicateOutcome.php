<?php

namespace App\Duplicate;

use App\Entity\Item;
use App\Entity\ItemUpload;

/**
 * Ergebnis einer Duplikatprüfung, für Konsole und Backend.
 */
final readonly class DuplicateOutcome
{
    public const SKIPPED = 'skipped';
    public const UNIQUE = 'unique';
    public const POSSIBLE = 'possible';
    public const MERGED = 'merged';
    public const FAILED = 'failed';

    /**
     * @param list<Candidate> $candidates
     */
    public function __construct(
        public string $result,
        public array $candidates = [],
        public ?ComparisonMatch $match = null,
        public ?Item $target = null,
        public ?ItemUpload $upload = null,
        public ?string $error = null,
    ) {
    }

    public function describe(): string
    {
        return match ($this->result) {
            self::MERGED => sprintf('Duplikat von #%d „%s“ – %s', $this->target?->getId(), $this->target?->getTitle(), self::uploadSummary($this->upload)),
            self::POSSIBLE => sprintf('mögliches Duplikat von #%d (Sicherheit %d %%) – bitte unter „Prüfen & freigeben“ entscheiden', $this->target?->getId(), (int) round(100 * (float) $this->match?->confidence)),
            self::UNIQUE => [] === $this->candidates ? 'kein Duplikat (keine Kandidaten)' : sprintf('kein Duplikat (%d Kandidat%s verglichen)', \count($this->candidates), 1 === \count($this->candidates) ? '' : 'en'),
            self::FAILED => 'Duplikatprüfung fehlgeschlagen: '.$this->error,
            default => 'nicht geprüft',
        };
    }

    public static function uploadSummary(?ItemUpload $upload): string
    {
        return match (true) {
            null === $upload => '',
            $upload->isImagesReplaced() => 'Bilder ersetzt',
            $upload->isProposal() => 'bessere Bilder vorgeschlagen',
            default => 'nur gezählt',
        };
    }
}
