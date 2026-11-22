<?php

namespace App\Duplicate;

use App\Ai\AiConfig;
use App\Ai\AiException;
use App\Entity\Item;
use App\Entity\ItemImage;
use App\Enum\ImageSide;
use App\Enum\Specimen;
use App\Media\MediaStorage;

/**
 * Stufe 2 der Duplikat-Erkennung: baut den Vergleichsauftrag für Gemini – Prompt (aus den Einstellungen),
 * Angaben zum neuen Objekt und zu den Kandidaten, die Fotos aller Objekte und das JSON-Schema der Antwort.
 *
 * Reihenfolge für Geminis automatisches Caching: erst der feste Prompt, dann die wechselnden Teile.
 */
final class ComparisonPromptBuilder
{
    public const MAX_NEW_IMAGES = 4;
    public const MAX_CANDIDATE_IMAGES = 2;
    /** Fotos gehen in der 800-px-Variante an die KI; die echte Auflösung steht in der Anfrage. */
    private const IMAGE_SIZE = 800;

    public function __construct(
        private readonly AiConfig $config,
        private readonly MediaStorage $media,
    ) {
    }

    /** @param list<Candidate> $candidates */
    public function build(Item $item, array $candidates): ComparisonRequest
    {
        $images = [];
        foreach ($this->pick($item, self::MAX_NEW_IMAGES) as $n => $image) {
            $images[] = $this->image(sprintf('Neues Objekt – Bild %d', $n + 1), $image);
        }
        if ([] === $images) {
            throw new AiException('Das neue Objekt hat keine Bilder.');
        }
        foreach ($candidates as $candidate) {
            foreach ($this->pick($candidate->item, self::MAX_CANDIDATE_IMAGES, true) as $n => $image) {
                $images[] = $this->image(sprintf('Kandidat #%d – Bild %d', $candidate->item->getId(), $n + 1), $image);
            }
        }

        return new ComparisonRequest($item, $candidates, $this->config->duplicatePrompt(), $this->text($item, $candidates), $images, $this->schema());
    }

    /**
     * Auftrag mit den Angaben zu allen Objekten (auch für die Vorschau im Backend).
     *
     * @param list<Candidate> $candidates
     */
    public function text(Item $item, array $candidates): string
    {
        $lines = [
            sprintf('Vergleiche das neue Objekt mit %d Kandidat%s. Die Bilder folgen in dieser Reihenfolge: erst das neue Objekt, dann die Kandidaten.', \count($candidates), 1 === \count($candidates) ? '' : 'en'),
            '',
            'NEUES OBJEKT'.(null !== $item->getSourceFolder() ? ' (Upload '.$item->getSourceFolder().')' : '').':',
            $this->describe($item, self::MAX_NEW_IMAGES, false),
        ];
        foreach ($candidates as $candidate) {
            $lines[] = '';
            $lines[] = sprintf('KANDIDAT #%d (Vorauswahl: %s):', $candidate->item->getId(), [] === $candidate->reasons ? 'ähnliche Angaben' : implode(', ', $candidate->reasons));
            $lines[] = $this->describe($candidate->item, self::MAX_CANDIDATE_IMAGES, true);
        }

        return implode("\n", $lines);
    }

    /**
     * JSON-Schema der Antwort: je Kandidat ein Eintrag in "matches".
     *
     * @return array<string, mixed>
     */
    public function schema(): array
    {
        $match = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['candidate_id', 'same_object', 'confidence', 'specimen', 'condition', 'photos', 'reason'],
            'properties' => [
                'candidate_id' => ['type' => 'integer', 'description' => 'Nummer des Kandidaten (#…)'],
                'same_object' => ['type' => 'boolean'],
                'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                'specimen' => ['type' => 'string', 'enum' => array_map(static fn (Specimen $s): string => $s->value, Specimen::cases())],
                'condition' => ['type' => 'string', 'enum' => ComparisonMatch::CONDITIONS],
                'photos' => ['type' => 'string', 'enum' => ComparisonMatch::PHOTOS],
                'reason' => ['type' => 'string'],
            ],
        ];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['matches', 'notes'],
            'properties' => [
                'matches' => ['type' => 'array', 'items' => $match],
                'notes' => ['type' => 'string', 'description' => 'Hinweise für die Prüfung'],
            ],
        ];
    }

    private function describe(Item $item, int $maxImages, bool $frontAndBack): string
    {
        $lines = [
            'Titel: '.$item->getTitle(),
            'Objektart: '.($item->getArea()?->getName() ?? 'unbekannt'),
            'Land/Gebiet: '.($item->getCategory()?->getPathLabel() ?? 'unbekannt'),
            'Jahr: '.($item->getYearLabel() ?? 'unbekannt'),
        ];
        if (null !== $item->getSeries()) {
            $lines[] = 'Serie: '.$item->getSeries();
        }
        foreach ($item->getSortedAttributes() as $attribute) {
            $definition = $attribute->getDefinition();
            $lines[] = sprintf('%s: %s', $definition->getLabel(), $definition->hasOption($attribute->getValue()) ? $definition->getOptionLabel($attribute->getValue()) : $attribute->getValue());
        }
        $lines[] = 'Mängel: '.($item->getDefects() ?? 'keine angegeben');
        $photos = [];
        foreach ($this->pick($item, $maxImages, $frontAndBack) as $n => $image) {
            $photos[] = sprintf('Bild %d: %s, %d × %d px', $n + 1, $image->getSide()?->label() ?? 'Seite unbekannt', $image->getWidth(), $image->getHeight());
        }
        $lines[] = 'Fotos: '.([] === $photos ? 'keine' : implode('; ', $photos));

        return '- '.implode("\n- ", $lines);
    }

    /**
     * Fotos für den Vergleich: beim neuen Objekt alle (bis zur Höchstzahl), bei Kandidaten bevorzugt Vorder- und Rückseite.
     *
     * @return list<ItemImage>
     */
    private function pick(Item $item, int $max, bool $frontAndBack = false): array
    {
        $images = array_values(array_filter($item->getImages()->getValues(), static fn (ItemImage $i): bool => null !== $i->getPath()));
        if ($frontAndBack) {
            $rank = static fn (ItemImage $i): int => match ($i->getSide()) {
                ImageSide::Front => 0,
                ImageSide::Back => 1,
                default => 2,
            };
            usort($images, static fn (ItemImage $a, ItemImage $b): int => [$rank($a), $a->getPosition()] <=> [$rank($b), $b->getPosition()]);
        }

        return \array_slice($images, 0, $max);
    }

    /** @return array{label: string, data: string, mime: string} */
    private function image(string $label, ItemImage $image): array
    {
        return ['label' => $label, 'data' => $this->media->base64((string) $image->getPath(), self::IMAGE_SIZE), 'mime' => 'image/webp'];
    }
}
