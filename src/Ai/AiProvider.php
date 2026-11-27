<?php

namespace App\Ai;

use App\Service\SettingsProvider;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Welcher KI-Anbieter ist aktiv?
 *
 * AI_PROVIDER=fake (Entwicklung und Tests ohne API-Key, ohne Kosten) hat immer Vorrang.
 * Sonst gilt der Anbieter der Hauptzeile im Backend (Einstellungen → KI → Modelle für die Erkennung, oberste aktive
 * Zeile), ohne Zeilen der Wert aus AI_PROVIDER. Er übernimmt Duplikat-Vergleich und Übersetzung; erkannt wird mit
 * allen aktiven Zeilen (AiConfig::recognizers()).
 */
final class AiProvider
{
    public const GEMINI = 'gemini';
    public const ANTHROPIC = 'anthropic';
    public const MISTRAL = 'mistral';
    public const OPENAI = 'openai';
    public const FAKE = 'fake';

    /** Auswahl im Backend: Bezeichnung => Anbieter */
    public const CHOICES = [
        'Google Gemini' => self::GEMINI,
        'Anthropic Claude' => self::ANTHROPIC,
        'Mistral AI' => self::MISTRAL,
        'OpenAI (ChatGPT)' => self::OPENAI,
    ];

    /** Höchstzahl Bilder pro Anfrage, falls der Anbieter eine hat (Mistral: laut Doku 8) */
    private const MAX_IMAGES = [
        self::MISTRAL => 8,
    ];

    public function __construct(
        #[Autowire('%env(AI_PROVIDER)%')]
        private readonly string $envProvider,
        private readonly SettingsProvider $settings,
    ) {
    }

    public function isFake(): bool
    {
        return self::FAKE === $this->normalize($this->envProvider);
    }

    /** Anbieter der Hauptzeile: gemini | anthropic | mistral | openai (bei fake: der Anbieter, der ohne Testmodus gelten würde) */
    public function name(): string
    {
        return $this->resolve($this->settings->get()->primaryRecognizer()?->provider);
    }

    /** Wirksamer Anbieter für eine Auswahl im Backend (null = AI_PROVIDER aus .env) */
    public function resolve(?string $chosen): string
    {
        if (null !== $chosen && \in_array($chosen, self::CHOICES, true)) {
            return $chosen;
        }
        $env = $this->normalize($this->envProvider);

        return \in_array($env, self::CHOICES, true) ? $env : self::GEMINI;
    }

    public static function label(string $provider): string
    {
        return (string) (array_search($provider, self::CHOICES, true) ?: $provider);
    }

    /** Kurzname für Statusmeldungen, z. B. „Claude“ */
    public static function shortLabel(string $provider): string
    {
        return match ($provider) {
            self::ANTHROPIC => 'Claude',
            self::GEMINI => 'Gemini',
            self::MISTRAL => 'Mistral',
            self::OPENAI => 'OpenAI',
            default => $provider,
        };
    }

    /** Höchstzahl Bilder pro Anfrage beim Anbieter (null = Hauptzeile; Ergebnis null = keine feste Grenze) */
    public function maxImages(?string $provider = null): ?int
    {
        return $this->isFake() ? null : (self::MAX_IMAGES[$provider ?? $this->name()] ?? null);
    }

    private function normalize(string $value): string
    {
        return strtolower(trim($value));
    }
}
