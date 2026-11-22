<?php

namespace App\Ai;

use App\Service\SettingsProvider;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Modelle und Prompts: Backend-Einstellung, sonst Standardwert (.env bzw. DefaultPrompts).
 */
final class AiConfig
{
    public function __construct(
        private readonly SettingsProvider $settings,
        #[Autowire('%env(GEMINI_MODEL)%')]
        private readonly string $defaultModel,
        #[Autowire('%env(GEMINI_TRANSLATION_MODEL)%')]
        private readonly string $defaultTranslationModel,
    ) {
    }

    public function recognitionModel(): string
    {
        return $this->settings->get()->getAiModel() ?? $this->defaultModel;
    }

    public function translationModel(): string
    {
        return $this->settings->get()->getAiTranslationModel() ?? $this->defaultTranslationModel;
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
        return $this->defaultModel;
    }

    public function defaultTranslationModel(): string
    {
        return $this->defaultTranslationModel;
    }
}
