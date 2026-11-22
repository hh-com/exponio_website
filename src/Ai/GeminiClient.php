<?php

namespace App\Ai;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Schlanker Client für die Gemini Interactions API (seit Juni 2026 Googles Standardschnittstelle).
 *
 *   POST https://generativelanguage.googleapis.com/v1beta/interactions
 *   Header x-goog-api-key, Antwort als JSON nach Schema (response_format), store: false
 *
 * Wiederholungen bei 429/5xx übernimmt der HTTP-Client (config/packages/framework.yaml, "gemini.client").
 */
final class GeminiClient
{
    public function __construct(
        private readonly HttpClientInterface $geminiClient,
        private readonly GeminiCredentials $credentials,
    ) {
    }

    /** @return array{type: 'text', text: string} */
    public static function text(string $text): array
    {
        return ['type' => 'text', 'text' => $text];
    }

    /** @return array{type: 'image', data: string, mime_type: string} */
    public static function image(string $base64, string $mimeType): array
    {
        return ['type' => 'image', 'data' => $base64, 'mime_type' => $mimeType];
    }

    /**
     * Schickt Text- und Bildteile und liefert die Antwort als Array (gemäß JSON-Schema).
     *
     * @param list<array<string, string>> $input
     * @param array<string, mixed>        $schema
     *
     * @return array{data: array<string, mixed>, model: string}
     */
    public function generateJson(string $model, array $input, array $schema): array
    {
        $apiKey = $this->credentials->apiKey();
        if (null === $apiKey || '' === $apiKey) {
            throw new AiException('Kein Gemini-API-Key hinterlegt (Backend → Einstellungen → KI).');
        }

        try {
            $response = $this->geminiClient->request('POST', 'interactions', [
                'headers' => ['x-goog-api-key' => $apiKey],
                'json' => [
                    'model' => $model,
                    'input' => $input,
                    'response_format' => [
                        'type' => 'text',
                        'mime_type' => 'application/json',
                        'schema' => $schema,
                    ],
                    'store' => false,
                ],
            ]);
            $status = $response->getStatusCode();
            $body = $response->toArray(false);
        } catch (ExceptionInterface $e) {
            throw new AiException('Gemini nicht erreichbar: '.$e->getMessage(), 0, $e);
        }

        if ($status >= 400) {
            $message = \is_array($body['error'] ?? null) ? (string) ($body['error']['message'] ?? '') : '';
            throw new AiException(sprintf('Gemini-Fehler %d: %s', $status, '' !== $message ? $message : 'unbekannt'));
        }
        if (isset($body['status']) && 'completed' !== $body['status']) {
            throw new AiException(sprintf('Gemini-Antwort nicht abgeschlossen (Status "%s").', (string) $body['status']));
        }

        $json = self::outputText($body);
        if ('' === $json) {
            throw new AiException('Die Antwort von Gemini enthält keinen Text (evtl. blockiert).');
        }
        try {
            $data = json_decode($json, true, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new AiException('Ungültiges JSON in der Antwort: '.$e->getMessage(), 0, $e);
        }
        if (!\is_array($data)) {
            throw new AiException('Unerwartetes Antwortformat.');
        }

        return ['data' => $data, 'model' => \is_string($body['model'] ?? null) ? $body['model'] : $model];
    }

    /** Kleine Testanfrage: prüft Key, Modell und strukturierte Ausgabe. */
    public function ping(string $model): string
    {
        $result = $this->generateJson($model, [self::text('Antworte mit {"ok": true}.')], [
            'type' => 'object',
            'properties' => ['ok' => ['type' => 'boolean']],
            'required' => ['ok'],
        ]);

        return $result['model'];
    }

    /**
     * Text der Modellantwort: steps[] mit type "model_output", darin content[] mit type "text".
     *
     * @param array<string, mixed> $body
     */
    private static function outputText(array $body): string
    {
        $text = '';
        foreach (\is_array($body['steps'] ?? null) ? $body['steps'] : [] as $step) {
            if (!\is_array($step) || 'model_output' !== ($step['type'] ?? null)) {
                continue;
            }
            foreach (\is_array($step['content'] ?? null) ? $step['content'] : [] as $part) {
                if (\is_array($part) && 'text' === ($part['type'] ?? null) && \is_string($part['text'] ?? null)) {
                    $text .= $part['text'];
                }
            }
        }

        return trim($text);
    }
}
