<?php

namespace App\Recognition;

use App\Ai\AiClients;
use App\Ai\AiConfig;
use App\Ai\AiInput;

/**
 * Bilderkennung mit dem aktiven KI-Anbieter: Prompt, Auftrag und nummerierte Fotos in einer Anfrage,
 * Antwort als JSON nach dem Schema aus PromptBuilder.
 */
final class AiRecognizer implements RecognizerInterface
{
    public function __construct(
        private readonly AiClients $clients,
        private readonly AiConfig $config,
    ) {
    }

    public function recognize(RecognitionRequest $request): array
    {
        $input = [AiInput::text($request->instructions), AiInput::text($request->text)];
        foreach ($request->images as $index => $image) {
            $input[] = AiInput::text(sprintf('Bild %d:', $index + 1));
            $input[] = AiInput::image($image['data'], $image['mime']);
        }

        return $this->clients->active()->generateJson($this->config->recognitionModel(), $input, $request->schema);
    }
}
