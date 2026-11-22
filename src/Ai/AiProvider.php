<?php

namespace App\Ai;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * AI_PROVIDER=gemini (Standard) oder fake (Entwicklung und Tests ohne API-Key, ohne Kosten).
 */
final class AiProvider
{
    public function __construct(
        #[Autowire('%env(AI_PROVIDER)%')]
        private readonly string $provider,
    ) {
    }

    public function isFake(): bool
    {
        return 'fake' === strtolower(trim($this->provider));
    }
}
