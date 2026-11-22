<?php

namespace App\Duplicate;

use App\Ai\AiConfig;
use App\Ai\GeminiClient;

/**
 * Duplikat-Vergleich mit Gemini: Prompt, Auftrag und beschriftete Fotos aller Objekte in EINER Anfrage,
 * Antwort als JSON nach dem Schema aus ComparisonPromptBuilder. Verwendet das Modell der Erkennung.
 */
final class GeminiComparer implements ComparerInterface
{
    public function __construct(
        private readonly GeminiClient $client,
        private readonly AiConfig $config,
    ) {
    }

    public function compare(ComparisonRequest $request): array
    {
        $input = [GeminiClient::text($request->instructions), GeminiClient::text($request->text)];
        foreach ($request->images as $image) {
            $input[] = GeminiClient::text($image['label'].':');
            $input[] = GeminiClient::image($image['data'], $image['mime']);
        }

        return $this->client->generateJson($this->config->recognitionModel(), $input, $request->schema);
    }
}
