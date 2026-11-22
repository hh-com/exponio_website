<?php

namespace App\Recognition;

use App\Ai\AiConfig;
use App\Ai\GeminiClient;

/**
 * Bilderkennung mit Gemini: Prompt, Auftrag und nummerierte Fotos in einer Anfrage,
 * Antwort als JSON nach dem Schema aus PromptBuilder.
 */
final class GeminiRecognizer implements RecognizerInterface
{
    public function __construct(
        private readonly GeminiClient $client,
        private readonly AiConfig $config,
    ) {
    }

    public function recognize(RecognitionRequest $request): array
    {
        $input = [GeminiClient::text($request->instructions), GeminiClient::text($request->text)];
        foreach ($request->images as $index => $image) {
            $input[] = GeminiClient::text(sprintf('Bild %d:', $index + 1));
            $input[] = GeminiClient::image($image['data'], $image['mime']);
        }

        return $this->client->generateJson($this->config->recognitionModel(), $input, $request->schema);
    }
}
