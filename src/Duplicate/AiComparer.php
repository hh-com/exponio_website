<?php

namespace App\Duplicate;

use App\Ai\AiClients;
use App\Ai\AiConfig;
use App\Ai\AiInput;

/**
 * Duplikat-Vergleich mit dem aktiven KI-Anbieter: Prompt, Auftrag und beschriftete Fotos aller Objekte in EINER Anfrage,
 * Antwort als JSON nach dem Schema aus ComparisonPromptBuilder. Verwendet das Modell der Erkennung.
 */
final class AiComparer implements ComparerInterface
{
    public function __construct(
        private readonly AiClients $clients,
        private readonly AiConfig $config,
    ) {
    }

    public function compare(ComparisonRequest $request): array
    {
        $input = [AiInput::text($request->instructions), AiInput::text($request->text)];
        foreach ($request->images as $image) {
            $input[] = AiInput::text($image['label'].':');
            $input[] = AiInput::image($image['data'], $image['mime']);
        }

        return $this->clients->active()->generateJson($this->config->recognitionModel(), $input, $request->schema);
    }
}
