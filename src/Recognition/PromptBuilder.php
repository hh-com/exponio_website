<?php

namespace App\Recognition;

use App\Ai\AiConfig;
use App\Ai\DefaultPrompts;
use App\Entity\AttributeDefinition;
use App\Entity\CollectionArea;
use App\Entity\Item;
use App\Enum\AttributeType;
use App\Enum\ImageSide;
use App\Media\MediaStorage;
use App\Repository\CategoryRepository;
use App\Repository\CollectionAreaRepository;
use App\Repository\TopicRepository;

/**
 * Baut den Prompt (aus den Einstellungen, Platzhalter gefüllt) und das JSON-Schema der Antwort.
 *
 * Reihenfolge für Geminis automatisches Caching: erst der feste Prompt mit den Listen,
 * danach die wechselnden Teile (Auftrag samt Hinweis an die KI, dann die Bilder).
 */
final class PromptBuilder
{
    public const MAX_IMAGES = 4;
    private const MAX_LIST = 400;
    /** Vor dem Hinweis an die KI (Item::$aiHint). Er steht im wechselnden Teil der Anfrage, der feste Prompt bleibt gleich. */
    private const HINT_INTRO = 'Hinweis des Sammlers zu diesem Objekt. Er kennt das Stück: Seine Angaben haben Vorrang vor deiner eigenen Einschätzung und gelten als gesichert. Übernimm sie und ergänze den Rest anhand der Fotos. Widerspricht der Hinweis deutlich dem, was auf den Fotos zu sehen ist, erkläre das in "notes".';

    public function __construct(
        private readonly CollectionAreaRepository $areas,
        private readonly CategoryRepository $categories,
        private readonly TopicRepository $topics,
        private readonly AiConfig $config,
        private readonly MediaStorage $media,
    ) {
    }

    public function build(Item $item): RecognitionRequest
    {
        $areas = $this->areas->findActive();
        if ([] === $areas) {
            throw new RecognitionException('Es ist keine aktive Objektart angelegt.');
        }
        $images = [];
        foreach ($item->getImages()->slice(0, self::MAX_IMAGES) as $image) {
            $images[] = ['data' => $this->media->base64((string) $image->getPath()), 'mime' => 'image/webp'];
        }
        if ([] === $images) {
            throw new RecognitionException('Das Objekt hat keine Bilder.');
        }

        $text = sprintf(
            'Bestimme dieses Sammlungsobjekt (%d Foto%s). Vermutete Objektart laut Import: %s.',
            \count($images),
            1 === \count($images) ? '' : 's',
            $item->getArea()?->getName() ?? 'unbekannt',
        );
        if (null !== $hint = $item->getAiHint()) {
            $text .= "\n\n".self::HINT_INTRO."\n\"\"\"\n".$hint."\n\"\"\"";
        }

        return new RecognitionRequest($item, $this->instructions($areas), $text, $images, $this->schema($areas), $areas);
    }

    /** Fertiger Prompt mit den aktuellen Daten (auch für die Vorschau im Backend). */
    public function preview(): string
    {
        return $this->instructions($this->areas->findActive());
    }

    /** @param list<CollectionArea> $areas */
    public function instructions(array $areas): string
    {
        $prompt = $this->config->recognitionPrompt();
        $areaList = $this->describeAreas($areas);
        if (!str_contains($prompt, DefaultPrompts::PLACEHOLDER_AREAS)) {
            $prompt .= "\n\nObjektarten (Wert für \"area\") und ihre Merkmale:\n".DefaultPrompts::PLACEHOLDER_AREAS;
        }

        $regions = array_map(static fn ($c): string => $c->getPathLabel(), \array_slice($this->categories->findAllOrdered(), 0, self::MAX_LIST));
        sort($regions, \SORT_NATURAL | \SORT_FLAG_CASE);
        $topics = array_map(static fn ($t): string => $t->getName(), \array_slice($this->topics->findAllOrdered(), 0, self::MAX_LIST));

        return strtr($prompt, [
            DefaultPrompts::PLACEHOLDER_AREAS => $areaList,
            DefaultPrompts::PLACEHOLDER_REGIONS => [] === $regions ? '(noch keine)' : implode('; ', $regions),
            DefaultPrompts::PLACEHOLDER_TOPICS => [] === $topics ? '(noch keine)' : implode('; ', $topics),
        ]);
    }

    /**
     * JSON-Schema der Antwort (alle Felder Pflicht, unbekannte Werte als null).
     *
     * @param list<CollectionArea> $areas
     *
     * @return array<string, mixed>
     */
    public function schema(array $areas): array
    {
        $codes = [];
        foreach ($areas as $area) {
            foreach ($area->getAttributeDefinitions() as $definition) {
                $codes[(string) $definition->getCode()] = true;
            }
        }
        $codeSchema = ['type' => 'string'];
        if ([] !== $codes) {
            $codeSchema['enum'] = array_keys($codes);
        }

        $nullableString = static fn (string $description): array => ['type' => ['string', 'null'], 'description' => $description];
        $stringList = ['type' => 'array', 'items' => ['type' => 'string']];

        $properties = [
            'is_collectible' => ['type' => 'boolean'],
            'area' => ['type' => 'string', 'enum' => array_map(static fn (CollectionArea $a): string => $a->getAiKey(), $areas)],
            'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
            'title' => ['type' => 'string'],
            'description' => ['type' => 'string'],
            'meta_description' => ['type' => 'string', 'description' => 'höchstens 155 Zeichen'],
            'defects' => $nullableString('sichtbare Mängel oder null'),
            'region_path' => $stringList + ['description' => 'Land/Gebiet, allgemein → speziell'],
            'year' => ['type' => ['integer', 'null']],
            'year_to' => ['type' => ['integer', 'null'], 'description' => 'nur bei Zeiträumen/Serien über mehrere Jahre'],
            'issue_date' => $nullableString('Ausgabetag als YYYY-MM-DD'),
            'series' => $nullableString('Serie/Ausgabe, z. B. "Bedeutende Deutsche"'),
            'topics' => $stringList,
            'attributes' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['code', 'value'],
                    'properties' => ['code' => $codeSchema, 'value' => ['type' => 'string']],
                ],
            ],
            'images' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['index', 'side', 'alt'],
                    'properties' => [
                        'index' => ['type' => 'integer', 'description' => 'Bildnummer, beginnt bei 1'],
                        'side' => ['type' => 'string', 'enum' => array_map(static fn (ImageSide $s): string => $s->value, ImageSide::cases())],
                        'alt' => ['type' => 'string'],
                    ],
                ],
            ],
            'catalog_value_eur' => ['type' => ['number', 'null']],
            'notes' => ['type' => 'string', 'description' => 'Unsicherheiten und Hinweise für die Prüfung'],
            'alternatives' => $stringList + ['description' => 'andere mögliche Bestimmungen'],
        ];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => array_keys($properties),
            'properties' => $properties,
        ];
    }

    /** @param list<CollectionArea> $areas */
    private function describeAreas(array $areas): string
    {
        $lines = [];
        foreach ($areas as $area) {
            $description = $area->getDescription();
            $lines[] = sprintf('* %s = %s%s', $area->getAiKey(), $area->getName(), $description ? ' – '.$this->oneLine($description) : '');
            foreach ($area->getAttributeDefinitions() as $definition) {
                $lines[] = '  - '.$this->describeDefinition($definition);
            }
        }

        return implode("\n", $lines);
    }

    private function describeDefinition(AttributeDefinition $definition): string
    {
        $type = match ($definition->getType()) {
            AttributeType::Text => 'Text',
            AttributeType::Number => 'Zahl'.($definition->getUnit() ? ' in '.$definition->getUnit() : ''),
            AttributeType::Boolean => 'Ja/Nein: "1" oder "0"',
            AttributeType::Select => 'Auswahl: '.implode(', ', array_map(
                static fn (array $o): string => $o['value'].'='.$o['label'],
                $definition->getOptions(),
            )),
        };
        $hint = $definition->getAiHint() ? ' – '.$this->oneLine($definition->getAiHint()) : '';

        return sprintf('%s (%s; %s)%s', $definition->getCode(), $definition->getLabel(), $type, $hint);
    }

    private function oneLine(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }
}
