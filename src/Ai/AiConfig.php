<?php

namespace App\Ai;

use App\Service\SettingsProvider;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Modelle und Prompts: Backend-Einstellung, sonst Standardwert (.env bzw. DefaultPrompts).
 * Die Modelle gelten für den aktiven Anbieter (App\Ai\AiProvider).
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
    ) {
    }

    public function recognitionModel(): string
    {
        return $this->settings->get()->getAiModel() ?? $this->defaultModel();
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

    public function defaultModel(): string
    {
        return AiProvider::ANTHROPIC === $this->provider->name() ? $this->anthropicModel : $this->geminiModel;
    }

    public function defaultTranslationModel(): string
    {
        return AiProvider::ANTHROPIC === $this->provider->name() ? $this->anthropicTranslationModel : $this->geminiTranslationModel;
    }
}
