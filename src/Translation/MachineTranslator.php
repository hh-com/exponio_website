<?php

namespace App\Translation;

use App\Ai\AiClients;
use App\Ai\AiConfig;
use App\Ai\AiInput;
use App\Ai\AiProvider;
use App\Ai\DefaultPrompts;
use App\Entity\Language;

/**
 * Übersetzt mehrere deutsche Texte in einer KI-Anfrage (Schlüssel => Text).
 * Ungültige Übersetzungen (z. B. mit verlorenem Platzhalter) fehlen im Ergebnis.
 */
final class MachineTranslator
{
    public function __construct(
        private readonly AiClients $clients,
        private readonly AiConfig $config,
        private readonly AiProvider $provider,
    ) {
    }

    /**
     * @param array<string, string> $texts
     *
     * @return array<string, string>
     */
    public function translate(array $texts, Language $language): array
    {
        if ([] === $texts) {
            return [];
        }
        $translated = $this->provider->isFake() ? $this->fake($texts, $language) : $this->ask($texts, $language);

        $valid = [];
        foreach ($translated as $key => $text) {
            if (isset($texts[$key]) && PlaceholderGuard::isValid($texts[$key], $text)) {
                $valid[$key] = $text;
            }
        }

        return $valid;
    }

    /**
     * @param array<string, string> $texts
     *
     * @return array<string, string>
     */
    private function ask(array $texts, Language $language): array
    {
        $prompt = strtr($this->config->translationPrompt(), [
            DefaultPrompts::PLACEHOLDER_LANGUAGE => sprintf('%s (Sprachcode %s, Eigenname „%s“)', $language->getNameDe(), $language->getCode(), $language->getNativeName()),
        ]);
        $entries = [];
        foreach ($texts as $key => $text) {
            $entries[] = ['key' => (string) $key, 'text' => $text];
        }
        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['translations'],
            'properties' => [
                'translations' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['key', 'text'],
                        'properties' => ['key' => ['type' => 'string'], 'text' => ['type' => 'string']],
                    ],
                ],
            ],
        ];

        $result = $this->clients->active()->generateJson($this->config->translationModel(), [
            AiInput::text($prompt),
            AiInput::text("Zu übersetzende Einträge (JSON):\n".json_encode($entries, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)),
        ], $schema);

        $translated = [];
        foreach (\is_array($result['data']['translations'] ?? null) ? $result['data']['translations'] : [] as $row) {
            if (\is_array($row) && \is_string($row['key'] ?? null) && \is_string($row['text'] ?? null)) {
                $translated[$row['key']] = trim($row['text']);
            }
        }

        return $translated;
    }

    /**
     * Testmodus: markiert den Text nur mit dem Sprachcode.
     *
     * @param array<string, string> $texts
     *
     * @return array<string, string>
     */
    private function fake(array $texts, Language $language): array
    {
        return array_map(static fn (string $text): string => '['.$language->getCode().'] '.$text, $texts);
    }
}
