<?php

namespace App\Ai;

use App\Service\SettingsProvider;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Modelle und Prompts: Backend-Einstellung, sonst Standardwert (.env bzw. DefaultPrompts).
 *
 * Erkannt wird mit allen aktiven Zeilen „Modelle für die Erkennung“ (recognizers()). Die oberste aktive Zeile ist die
 * Hauptzeile: Ihr Anbieter (AiProvider::name()) und ihr Modell (recognitionModel()) übernehmen auch den
 * Duplikat-Vergleich, das Übersetzungsmodell gilt für ihren Anbieter.
 */
final class AiConfig
{
    public function __construct(
        private readonly SettingsProvider $settings,
        private readonly AiProvider $provider,
        #[Autowire('%env(GEMINI_MODEL)%')]
        private readonly string $geminiModel,
        #[Autowire('%env(GEMINI_TRANSLATION_MODEL)%')]
        private readonly string $geminiTranslationModel,
        #[Autowire('%env(ANTHROPIC_MODEL)%')]
        private readonly string $anthropicModel,
        #[Autowire('%env(ANTHROPIC_TRANSLATION_MODEL)%')]
        private readonly string $anthropicTranslationModel,
        #[Autowire('%env(MISTRAL_MODEL)%')]
        private readonly string $mistralModel,
        #[Autowire('%env(MISTRAL_TRANSLATION_MODEL)%')]
        private readonly string $mistralTranslationModel,
        #[Autowire('%env(OPENAI_MODEL)%')]
        private readonly string $openaiModel,
        #[Autowire('%env(OPENAI_TRANSLATION_MODEL)%')]
        private readonly string $openaiTranslationModel,
    ) {
    }

    /**
     * Aktive Zeilen in ihrer Reihenfolge, das Modell aufgelöst (leer = Standard des Anbieters).
     * Ohne Zeilen: eine Zeile mit AI_PROVIDER aus .env und dessen Standardmodell.
     *
     * @return non-empty-list<RecognizerConfig>
     */
    public function recognizers(): array
    {
        $rows = [];
        foreach ($this->settings->get()->recognizerConfigs() as $row) {
            if ($row->active) {
                $rows[] = $row->withModel($row->model ?? $this->defaultModelFor($row->provider));
            }
        }

        return [] === $rows ? [new RecognizerConfig($this->provider->name(), $this->defaultModel())] : $rows;
    }

    /** Modell der Hauptzeile (Erkennung und Duplikat-Vergleich) */
    public function recognitionModel(): string
    {
        return (string) $this->recognizers()[0]->model;
    }

    public function translationModel(): string
    {
        return $this->settings->get()->getAiTranslationModel() ?? $this->defaultTranslationModel();
    }

    public function recognitionPrompt(): string
    {
        return $this->settings->get()->getAiRecognitionPrompt() ?? DefaultPrompts::RECOGNITION;
    }

    public function translationPrompt(): string
    {
        return $this->settings->get()->getAiTranslationPrompt() ?? DefaultPrompts::TRANSLATION;
    }

    public function duplicatePrompt(): string
    {
        return $this->settings->get()->getAiDuplicatePrompt() ?? DefaultPrompts::DUPLICATE;
    }

    /** Standardmodell für die Erkennung beim Anbieter der Hauptzeile */
    public function defaultModel(): string
    {
        return $this->defaultModelFor($this->provider->name());
    }

    public function defaultModelFor(string $provider): string
    {
        return match ($provider) {
            AiProvider::ANTHROPIC => $this->anthropicModel,
            AiProvider::MISTRAL => $this->mistralModel,
            AiProvider::OPENAI => $this->openaiModel,
            default => $this->geminiModel,
        };
    }

    /** Standardmodell für Übersetzungen beim Anbieter der Hauptzeile */
    public function defaultTranslationModel(): string
    {
        return match ($this->provider->name()) {
            AiProvider::ANTHROPIC => $this->anthropicTranslationModel,
            AiProvider::MISTRAL => $this->mistralTranslationModel,
            AiProvider::OPENAI => $this->openaiTranslationModel,
            default => $this->geminiTranslationModel,
        };
    }
}
