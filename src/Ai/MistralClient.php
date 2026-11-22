<?php

namespace App\Ai;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Schlanker Client für die Mistral Chat Completions API.
 *
 *   POST https://api.mistral.ai/v1/chat/completions
 *   Header Authorization: Bearer …, Antwort als JSON nach Schema (response_format json_schema, strict)
 *
 * Bilder gehen als Data-URI ("image_url": "data:image/webp;base64,…"), höchstens 8 pro Anfrage (laut Doku).
 * Wiederholungen bei 429/5xx übernimmt der HTTP-Client (config/packages/framework.yaml, "mistral.client").
 */
final class MistralClient implements AiClientInterface
{
    public const MAX_IMAGES = 8;

    public function __construct(
        private readonly HttpClientInterface $mistralClient,
        private readonly AiCredentials $credentials,
    ) {
    }

    public function generateJson(string $model, array $input, array $schema): array
    {
        $apiKey = $this->credentials->apiKey(AiProvider::MISTRAL);
        if (null === $apiKey || '' === $apiKey) {
            throw new AiException('Kein Mistral-API-Key hinterlegt (Backend → Einstellungen → KI).');
        }

        $content = [];
        $images = 0;
        foreach ($input as $part) {
            if ('image' === $part['type']) {
                ++$images;
                $content[] = ['type' => 'image_url', 'image_url' => sprintf('data:%s;base64,%s', $part['mime_type'], $part['data'])];
            } else {
                $content[] = ['type' => 'text', 'text' => $part['text']];
            }
        }
        if ($images > self::MAX_IMAGES) {
            throw new AiException(sprintf('Mistral verarbeitet höchstens %d Bilder pro Anfrage (hier: %d).', self::MAX_IMAGES, $images));
        }

        try {
            $response = $this->mistralClient->request('POST', 'chat/completions', [
                'headers' => ['Authorization' => 'Bearer '.$apiKey],
                'json' => [
                    'model' => $model,
                    'messages' => [['role' => 'user', 'content' => [] === $content ? '.' : $content]],
                    'response_format' => [] === $schema
                        ? ['type' => 'json_object']
                        : ['type' => 'json_schema', 'json_schema' => ['name' => 'antwort', 'schema' => $schema, 'strict' => true]],
                ],
            ]);
            $status = $response->getStatusCode();
            $body = $response->toArray(false);
        } catch (ExceptionInterface $e) {
            throw new AiException('Mistral nicht erreichbar: '.$e->getMessage(), 0, $e);
        }

        if (403 === $status && ('tier_not_allowed' === ($body['type'] ?? null) || str_contains(self::errorMessage($body), 'subscription tier'))) {
            throw new AiException(sprintf('Das Modell %s ist in deinem Mistral-Tarif nicht enthalten (der kostenlose Tarif „Experiment“ sperrt u. a. die großen Modelle). '
                .'In der Mistral-Konsole auf „Scale“ umstellen oder unter Einstellungen → KI ein anderes Modell eintragen.', $model));
        }
        if ($status >= 400) {
            throw new AiException(sprintf('Mistral-Fehler %d: %s', $status, self::errorMessage($body)));
        }

        $choice = \is_array($body['choices'][0] ?? null) ? $body['choices'][0] : [];
        $finish = $choice['finish_reason'] ?? null;
        if ('length' === $finish || 'model_length' === $finish) {
            throw new AiException('Die Antwort von Mistral wurde abgeschnitten (zu lang).');
        }
        $json = self::text($choice['message']['content'] ?? null);
        if ('' === $json) {
            throw new AiException('Die Antwort von Mistral enthält keinen Text.');
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

    public function ping(string $model): string
    {
        $result = $this->generateJson($model, [AiInput::text('Antworte mit {"ok": true}.')], [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => ['ok' => ['type' => 'boolean']],
            'required' => ['ok'],
        ]);

        return $result['model'];
    }

    /**
     * Inhalt der Antwort: meist ein String, bei Modellen mit Denkschritten eine Liste von Teilen –
     * dann zählen nur die Textteile.
     */
    private static function text(mixed $content): string
    {
        if (\is_string($content)) {
            return trim($content);
        }
        $text = '';
        foreach (\is_array($content) ? $content : [] as $part) {
            if (\is_array($part) && 'text' === ($part['type'] ?? null) && \is_string($part['text'] ?? null)) {
                $text .= $part['text'];
            }
        }

        return trim($text);
    }

    /**
     * Fehlermeldung aus den verschiedenen Formaten der API ("message", "detail", "error.message").
     *
     * @param array<mixed> $body
     */
    private static function errorMessage(array $body): string
    {
        $message = $body['message'] ?? $body['detail'] ?? (\is_array($body['error'] ?? null) ? ($body['error']['message'] ?? null) : null);
        if (\is_array($message)) {
            $message = json_encode($message, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        }

        return \is_string($message) && '' !== $message ? $message : 'unbekannt';
    }
}
