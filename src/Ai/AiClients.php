<?php

namespace App\Ai;

/**
 * Liefert den Client der Hauptzeile (App\Ai\AiProvider) oder eines bestimmten Anbieters.
 */
final class AiClients
{
    public function __construct(
        private readonly AiProvider $provider,
        private readonly GeminiClient $gemini,
        private readonly AnthropicClient $anthropic,
        private readonly MistralClient $mistral,
        private readonly OpenAiClient $openai,
    ) {
    }

    /** Client der Hauptzeile */
    public function active(): AiClientInterface
    {
        return $this->for($this->provider->name());
    }

    public function for(string $provider): AiClientInterface
    {
        return match ($provider) {
            AiProvider::ANTHROPIC => $this->anthropic,
            AiProvider::MISTRAL => $this->mistral,
            AiProvider::OPENAI => $this->openai,
            default => $this->gemini,
        };
    }
}
