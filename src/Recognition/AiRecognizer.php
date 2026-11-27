<?php

namespace App\Recognition;

use App\Ai\AiClients;
use App\Ai\AiInput;
use App\Ai\RecognizerConfig;

/**
 * Bilderkennung mit dem Anbieter einer Zeile: Prompt, Auftrag und nummerierte Fotos in einer Anfrage,
 * Antwort als JSON nach dem Schema aus PromptBuilder.
 */
final class AiRecognizer implements RecognizerInterface
{
    public function __construct(
        private readonly AiClients $clients,
    ) {
    }

    public function recognize(RecognitionRequest $request, RecognizerConfig $config): array
    {
        $input = [AiInput::text($request->instructions), AiInput::text($request->text)];
        foreach ($request->images as $index => $image) {
            $input[] = AiInput::text(sprintf('Bild %d:', $index + 1));
            $input[] = AiInput::image($image['data'], $image['mime']);
        }

        return $this->clients->for($config->provider)->generateJson((string) $config->model, $input, $request->schema);
    }
}
