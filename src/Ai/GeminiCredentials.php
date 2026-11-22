<?php

namespace App\Ai;

use App\Service\SettingsProvider;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Woher kommt der Gemini-API-Key?
 *
 * 1. GEMINI_API_KEY in .env.local (hat Vorrang)
 * 2. sonst der im Backend eingegebene, verschlüsselt gespeicherte Key
 */
final class GeminiCredentials
{
    public const SOURCE_ENV = 'env';
    public const SOURCE_BACKEND = 'backend';

    public function __construct(
        #[Autowire('%env(default::GEMINI_API_KEY)%')]
        private readonly ?string $envKey,
        private readonly SettingsProvider $settings,
        private readonly SecretBox $box,
    ) {
    }

    public function apiKey(): ?string
    {
        $env = trim((string) $this->envKey);
        if ('' !== $env) {
            return $env;
        }
        $stored = $this->settings->get()->getGeminiApiKey();

        return null === $stored ? null : $this->box->decrypt($stored);
    }

    /** @return self::SOURCE_*|null */
    public function source(): ?string
    {
        if ('' !== trim((string) $this->envKey)) {
            return self::SOURCE_ENV;
        }

        return null !== $this->apiKey() ? self::SOURCE_BACKEND : null;
    }

    /** Ein Key ist gespeichert, lässt sich aber nicht entschlüsseln (APP_SECRET geändert). */
    public function isUnreadable(): bool
    {
        $stored = $this->settings->get()->getGeminiApiKey();

        return null !== $stored && null === $this->box->decrypt($stored);
    }

    /** z. B. „…a1b2“ – zeigt, welcher Key hinterlegt ist, ohne ihn zu verraten */
    public function hint(): ?string
    {
        $key = $this->apiKey();

        return null === $key ? null : '…'.substr($key, -4);
    }

    /** Speichert einen neuen Key verschlüsselt in den Einstellungen (null = entfernen). */
    public function store(?string $plain): void
    {
        $plain = null === $plain ? '' : trim($plain);
        $this->settings->get()->setGeminiApiKey('' === $plain ? null : $this->box->encrypt($plain));
    }
}
