<?php

namespace App\Duplicate;

use App\Entity\Item;

/**
 * Alles für einen Duplikat-Vergleich: das neue Objekt, die Kandidaten, Prompt, Anfrage, Fotos und Antwortschema.
 */
final readonly class ComparisonRequest
{
    /**
     * @param list<Candidate>                                        $candidates
     * @param list<array{label: string, data: string, mime: string}> $images     Fotos in der Reihenfolge der Anfrage
     * @param array<string, mixed>                                   $schema     JSON-Schema der Antwort
     */
    public function __construct(
        public Item $item,
        public array $candidates,
        public string $instructions,
        public string $text,
        public array $images,
        public array $schema,
    ) {
    }
}
