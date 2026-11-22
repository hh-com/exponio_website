<?php

namespace App\Ai;

use App\Service\SettingsProvider;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Woher kommt der API-Key des Anbieters?
 *
 * 1. GEMINI_API_KEY bzw. ANTHROPIC_API_KEY in .env.local (hat Vorrang)
 * 2. sonst der im Backend eingegebene, verschlüsselt gespeicherte Key
 *
 * Ohne Anbieter-Angabe gilt der aktive Anbieter (App\Ai\AiProvider).
 */
final class AiCredentials
{
    public const SOURCE_ENV = 'env';
    public const SOURCE_BACKEND = 'backend';

    public function __construct(
        #[Autowire('%env(default::GEMINI_API_KEY)%')]
        private readonly ?string $geminiEnvKey,
        #[Autowire('%env(default::ANTHROPIC_API_KEY)%')]
        private readonly ?string $anthropicEnvKey,
        private readonly SettingsProvider $settings,
        private readonly SecretBox $box,
        private readonly AiProvider $provider,
    ) {
    }

    public function apiKey(?string $provider = null): ?string
    {
        $provider ??= $this->provider->name();
        $env = $this->envKey($provider);
        if ('' !== $env) {
            return $env;
        }
        $stored = $this->settings->get()->getApiKey($provider);

        return null === $stored ? null : $this->box->decrypt($stored);
    }

    /** @return self::SOURCE_*|null */
    public function source(?string $provider = null): ?string
    {
        $provider ??= $this->provider->name();
        if ('' !== $this->envKey($provider)) {
            return self::SOURCE_ENV;
        }

        return null !== $this->apiKey($provider) ? self::SOURCE_BACKEND : null;
    }

    /** Name der Umgebungsvariable, z. B. GEMINI_API_KEY */
    public static function envName(string $provider): string
    {
        return strtoupper($provider).'_API_KEY';
    }

    /** Ein Key ist gespeichert, lässt sich aber nicht entschlüsseln (APP_SECRET geändert). */
    public function isUnreadable(?string $provider = null): bool
    {
        $stored = $this->settings->get()->getApiKey($provider ?? $this->provider->name());

        return null !== $stored && null === $this->box->decrypt($stored);
    }

    /** Ein Key liegt im Backend gespeichert (unabhängig von .env). */
    public function hasStored(?string $provider = null): bool
    {
        return null !== $this->settings->get()->getApiKey($provider ?? $this->provider->name());
    }

    /** z. B. „…a1b2“ – zeigt, welcher Key hinterlegt ist, ohne ihn zu verraten */
    public function hint(?string $provider = null): ?string
    {
        $key = $this->apiKey($provider);

        return null === $key ? null : '…'.substr($key, -4);
    }

    /** Speichert einen neuen Key verschlüsselt in den Einstellungen (null = entfernen). */
    public function store(?string $plain, ?string $provider = null): void
    {
        $plain = null === $plain ? '' : trim($plain);
        $this->settings->get()->setApiKey($provider ?? $this->provider->name(), '' === $plain ? null : $this->box->encrypt($plain));
    }

    private function envKey(string $provider): string
    {
        return trim((string) match ($provider) {
            AiProvider::ANTHROPIC => $this->anthropicEnvKey,
            default => $this->geminiEnvKey,
        });
    }
}
