<?php

namespace App\Recognition;

use App\Ai\RecognizerConfig;
use App\Enum\AttributeType;

/**
 * Liefert plausible Testdaten ohne API-Aufruf (AI_PROVIDER=fake), für Entwicklung und Tests.
 *
 * $variant (Stelle der Zeile, 0 = Hauptzeile) ändert Jahr, Merkmale und Sicherheit, damit sich die Ergebnisse
 * mehrerer Zeilen unter „Prüfen & freigeben“ unterscheiden.
 */
final class FakeRecognizer implements RecognizerInterface
{
    public function recognize(RecognitionRequest $request, RecognizerConfig $config, int $variant = 0): array
    {
        $area = $request->item->getArea() ?? $request->areas[0];
        $seed = crc32((string) $request->item->getSourceFolder().$request->item->getId()) + $variant * 7919;
        $year = 1950 + $seed % 60;

        $attributes = [];
        foreach ($area->getAttributeDefinitions() as $definition) {
            $options = $definition->getOptionValues();
            $value = match ($definition->getType()) {
                AttributeType::Select => [] === $options ? null : $options[$seed % \count($options)],
                AttributeType::Boolean => (string) ($seed % 2),
                AttributeType::Number => (string) (1 + $seed % 40),
                AttributeType::Text => 'michel' === $definition->getCode() ? (string) (100 + $seed % 900) : null,
            };
            if (null !== $value) {
                $attributes[] = ['code' => (string) $definition->getCode(), 'value' => $value];
            }
        }

        $images = [];
        foreach ($request->images as $index => $image) {
            $images[] = [
                'index' => $index + 1,
                'side' => 0 === $index ? 'front' : 'back',
                'alt' => 0 === $index ? 'Vorderseite der Testmarke' : 'Rückseite mit Gummierung',
            ];
        }

        return [
            'model' => 'fake',
            'usage' => ['input' => 1200 + 258 * \count($request->images), 'output' => 400 + 50 * $variant],
            'data' => [
                'is_collectible' => true,
                'area' => $area->getAiKey(),
                'confidence' => round(min(0.95, 0.42 + 0.17 * $variant), 2),
                'title' => sprintf('Testobjekt %d, Sondermarke', $year),
                'description' => 'Automatisch erzeugte Testbestimmung (AI_PROVIDER=fake). Bitte prüfen und korrigieren.',
                'meta_description' => sprintf('Testobjekt aus dem Jahr %d – automatisch erzeugte Bestimmung.', $year),
                'defects' => 0 === $seed % 2 ? 'kurzer Zahn oben rechts' : null,
                'region_path' => ['Deutschland', 'Bundesrepublik Deutschland'],
                'year' => $year,
                'year_to' => null,
                'issue_date' => sprintf('%d-%02d-%02d', $year, 1 + $seed % 12, 1 + $seed % 28),
                'series' => 'Testserie',
                'topics' => ['Persönlichkeiten'],
                'attributes' => $attributes,
                'images' => $images,
                'catalog_value_eur' => round(($seed % 500) / 100, 2),
                'notes' => 'Fake-Erkennung – keine echte Bestimmung.',
                'alternatives' => [],
            ],
        ];
    }
}
