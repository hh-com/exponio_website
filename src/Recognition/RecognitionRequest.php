<?php

namespace App\Recognition;

use App\Entity\CollectionArea;
use App\Entity\Item;

/**
 * Alles, was ein Erkenner für eine Anfrage braucht.
 */
final readonly class RecognitionRequest
{
    /**
     * @param list<array{data: string, mime: string}> $images Base64-Daten der Fotos (Reihenfolge = Bildnummer)
     * @param array<string, mixed>                    $schema JSON-Schema der Antwort
     * @param list<CollectionArea>                    $areas
     */
    public function __construct(
        public Item $item,
        public string $instructions,
        public string $text,
        public array $images,
        public array $schema,
        public array $areas,
    ) {
    }
}
