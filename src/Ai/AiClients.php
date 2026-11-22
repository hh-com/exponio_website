<?php

namespace App\Ai;

/**
 * Liefert den Client des aktiven Anbieters (App\Ai\AiProvider).
 */
final class AiClients
{
    public function __construct(
        private readonly AiProvider $provider,
        private readonly GeminiClient $gemini,
        private readonly AnthropicClient $anthropic,
    ) {
    }

    public function active(): AiClientInterface
    {
        return match ($this->provider->name()) {
            AiProvider::ANTHROPIC => $this->anthropic,
            default => $this->gemini,
        };
    }
}
