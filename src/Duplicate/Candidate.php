<?php

namespace App\Duplicate;

use App\Entity\Item;

/**
 * Ein vorhandenes Objekt, das nach der Vorauswahl (CandidateFinder) dasselbe sein könnte wie ein neuer Upload.
 */
final readonly class Candidate
{
    /**
     * @param list<string> $reasons Gründe der Vorauswahl, z. B. „gleiche Katalognummer“
     */
    public function __construct(
        public Item $item,
        public int $score,
        public array $reasons,
        public ?int $hashDistance = null,
        public bool $catalogMatch = false,
    ) {
    }

    /** Nahezu dasselbe Foto (kleiner Hamming-Abstand der Wahrnehmungs-Hashes) */
    public function hasSamePhoto(): bool
    {
        return null !== $this->hashDistance && $this->hashDistance <= CandidateFinder::HASH_STRONG;
    }
}
