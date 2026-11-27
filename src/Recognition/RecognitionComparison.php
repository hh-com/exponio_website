<?php

namespace App\Recognition;

use App\Ai\AiProvider;
use App\Entity\AttributeDefinition;
use App\Entity\CollectionArea;
use App\Entity\Item;
use App\Entity\RecognitionResult;
use App\Enum\AttributeType;
use App\Repository\CollectionAreaRepository;
use App\Service\SettingsProvider;

/**
 * Bereitet die Ergebnisse der Modelle eines Objekts für die Vergleichstabelle unter „Prüfen & freigeben“ auf:
 * Zeilen = Felder (Merkmale aus der Objektart, die das jeweilige Modell gewählt hat), Spalten = Modelle.
 * Werte sind lesbarer Text, so wie sie nach dem Übernehmen im Formular stünden.
 */
final class RecognitionComparison
{
    /** feste Zeilen vor den Merkmalen */
    private const HEAD = [
        'area' => 'Objektart',
        'region' => 'Land / Gebiet',
        'year' => 'Jahr',
        'issue_date' => 'Ausgabetag',
        'series' => 'Serie / Ausgabe',
        'title' => 'Titel',
    ];

    /** feste Zeilen nach den Merkmalen; long = lange Texte, erst nach „Texte anzeigen“ */
    private const TAIL = [
        'topics' => ['Themen', false],
        'defects' => ['Mängel', false],
        'catalog_value' => ['Schätzwert', false],
        'description' => ['Beschreibung', true],
        'notes' => ['Notizen der KI', true],
        'alternatives' => ['Alternativen', true],
    ];

    /** @var array<string, CollectionArea>|null nach KI-Schlüssel */
    private ?array $areas = null;

    public function __construct(
        private readonly CollectionAreaRepository $areaRepository,
        private readonly RecognitionApplier $applier,
        private readonly SettingsProvider $settings,
    ) {
    }

    /**
     * @return array{rows: list<array{key: string, label: string, long: bool}>, results: list<array<string, mixed>>}
     */
    public function build(Item $item): array
    {
        $results = $item->getRecognitions()->getValues();
        $attributeRows = [];
        $values = [];
        foreach ($results as $result) {
            $values[] = $this->values($item, $result, $attributeRows);
        }

        $rows = [];
        foreach (self::HEAD as $key => $label) {
            $rows[] = ['key' => $key, 'label' => $label, 'long' => false];
        }
        foreach ($attributeRows as $key => $label) {
            $rows[] = ['key' => $key, 'label' => $label, 'long' => false];
        }
        foreach (self::TAIL as $key => [$label, $long]) {
            $rows[] = ['key' => $key, 'label' => $label, 'long' => $long];
        }

        return [
            'rows' => $rows,
            'results' => array_map(fn (RecognitionResult $result, array $v): array => [
                'id' => $result->getId(),
                'provider' => $result->getProvider(),
                'label' => AiProvider::shortLabel($result->getProvider()),
                'model' => $result->getModel(),
                'modelUsed' => $result->getModelUsed(),
                'primary' => 0 === $result->getPosition(),
                'applied' => $result->isApplied(),
                'error' => $result->getError(),
                'confidence' => $result->getConfidence(),
                'isCollectible' => \is_bool($result->getData()['is_collectible'] ?? null) ? $result->getData()['is_collectible'] : null,
                'durationMs' => $result->getDurationMs(),
                'inputTokens' => $result->getInputTokens(),
                'outputTokens' => $result->getOutputTokens(),
                'cost' => $result->getCost(),
                'values' => (object) $v,
            ], $results, $values),
        ];
    }

    /**
     * @param array<string, string> $attributeRows Merkmal-Zeilen aller Ergebnisse (Schlüssel => Bezeichnung), wird ergänzt
     *
     * @return array<string, string|null>
     */
    private function values(Item $item, RecognitionResult $result, array &$attributeRows): array
    {
        $data = $result->getData();
        if (null === $data) {
            return [];
        }
        $area = $this->area($data['area'] ?? null) ?? $item->getArea();
        $year = self::int($data['year'] ?? null);
        $yearTo = self::int($data['year_to'] ?? null);

        $values = [
            'area' => $area?->getName(),
            'region' => self::join($data['region_path'] ?? null, ' › '),
            'year' => null === $year ? null : (null !== $yearTo && $yearTo > $year ? $year.'–'.$yearTo : (string) $year),
            'issue_date' => self::date($data['issue_date'] ?? null),
            'series' => self::text($data['series'] ?? null),
            'title' => self::text($data['title'] ?? null),
        ];

        foreach (\is_array($data['attributes'] ?? null) ? $data['attributes'] : [] as $attribute) {
            $definition = \is_array($attribute) ? $area?->getDefinition((string) ($attribute['code'] ?? '')) : null;
            if (null === $definition) {
                continue;
            }
            $value = $this->applier->normalizeValue($definition, (string) ($attribute['value'] ?? ''));
            if (null === $value) {
                continue;
            }
            $key = 'attr:'.$definition->getCode();
            $attributeRows[$key] ??= $definition->getLabel();
            $values[$key] = self::attributeText($definition, $value);
        }

        $value = $data['catalog_value_eur'] ?? null;
        $values += [
            'topics' => self::join($data['topics'] ?? null, ', '),
            'defects' => self::text($data['defects'] ?? null),
            'catalog_value' => is_numeric($value) && $value >= 0 ? number_format((float) $value, 2, ',', '.').' '.$this->settings->get()->getCurrency() : null,
            'description' => self::text($data['description'] ?? null),
            'notes' => self::text($data['notes'] ?? null),
            'alternatives' => self::join($data['alternatives'] ?? null, "\n"),
        ];

        return $values;
    }

    private function area(mixed $key): ?CollectionArea
    {
        if (null === $this->areas) {
            $this->areas = [];
            foreach ($this->areaRepository->findAllWithDefinitions() as $area) {
                $this->areas[$area->getAiKey()] = $area;
            }
        }

        return \is_string($key) ? ($this->areas[$key] ?? null) : null;
    }

    private static function attributeText(AttributeDefinition $definition, string $value): string
    {
        return match ($definition->getType()) {
            AttributeType::Select => $definition->getOptionLabel($value),
            AttributeType::Boolean => '1' === $value ? 'ja' : 'nein',
            AttributeType::Number => str_replace('.', ',', $value).(null !== $definition->getUnit() ? ' '.$definition->getUnit() : ''),
            AttributeType::Text => $value,
        };
    }

    private static function text(mixed $value): ?string
    {
        $value = \is_string($value) ? trim($value) : '';

        return '' === $value ? null : $value;
    }

    private static function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private static function date(mixed $value): ?string
    {
        $date = \is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;

        return false === $date ? null : $date->format('d.m.Y');
    }

    private static function join(mixed $list, string $separator): ?string
    {
        $parts = array_values(array_filter(array_map(static fn (mixed $v): string => \is_string($v) ? trim($v) : '', \is_array($list) ? $list : []), static fn (string $v): bool => '' !== $v));

        return [] === $parts ? null : implode($separator, $parts);
    }
}
