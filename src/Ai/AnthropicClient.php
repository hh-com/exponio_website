<?php

namespace App\Ai;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Schlanker Client für die Claude Messages API.
 *
 *   POST https://api.anthropic.com/v1/messages
 *   Header x-api-key und anthropic-version
 *
 * Die strukturierte Antwort kommt über ein erzwungenes Werkzeug ("tool_choice"): Claude muss es mit Eingaben
 * nach dem JSON-Schema aufrufen; diese Eingaben sind das Ergebnis. Wiederholungen bei 429/5xx/529 übernimmt
 * der HTTP-Client (config/packages/framework.yaml, "anthropic.client").
 */
final class AnthropicClient implements AiClientInterface
{
    private const TOOL = 'antwort';
    private const MAX_TOKENS = 16000;

    public function __construct(
        private readonly HttpClientInterface $anthropicClient,
        private readonly AiCredentials $credentials,
    ) {
    }

    public function generateJson(string $model, array $input, array $schema): array
    {
        $apiKey = $this->credentials->apiKey(AiProvider::ANTHROPIC);
        if (null === $apiKey || '' === $apiKey) {
            throw new AiException('Kein Anthropic-API-Key hinterlegt (Backend → Einstellungen → KI).');
        }

        $content = [];
        foreach ($input as $part) {
            $content[] = 'image' === $part['type']
                ? ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $part['mime_type'], 'data' => $part['data']]]
                : ['type' => 'text', 'text' => $part['text']];
        }
        if ([] === $content) {
            $content[] = ['type' => 'text', 'text' => '.'];
        }

        try {
            $response = $this->anthropicClient->request('POST', 'messages', [
                'headers' => ['x-api-key' => $apiKey],
                'json' => [
                    'model' => $model,
                    'max_tokens' => self::MAX_TOKENS,
                    'messages' => [['role' => 'user', 'content' => $content]],
                    'tools' => [[
                        'name' => self::TOOL,
                        'description' => 'Liefert die Antwort als strukturierte Daten.',
                        'input_schema' => [] === $schema ? ['type' => 'object'] : $schema,
                    ]],
                    'tool_choice' => ['type' => 'tool', 'name' => self::TOOL],
                ],
            ]);
            $status = $response->getStatusCode();
            $body = $response->toArray(false);
        } catch (ExceptionInterface $e) {
            throw new AiException('Claude nicht erreichbar: '.$e->getMessage(), 0, $e);
        }

        if ($status >= 400) {
            $message = \is_array($body['error'] ?? null) ? (string) ($body['error']['message'] ?? '') : '';
            throw new AiException(sprintf('Claude-Fehler %d: %s', $status, '' !== $message ? $message : 'unbekannt'));
        }
        if ('max_tokens' === ($body['stop_reason'] ?? null)) {
            throw new AiException('Die Antwort von Claude wurde abgeschnitten (zu lang).');
        }
        if ('refusal' === ($body['stop_reason'] ?? null)) {
            throw new AiException('Claude hat die Anfrage abgelehnt.');
        }

        foreach (\is_array($body['content'] ?? null) ? $body['content'] : [] as $block) {
            if (\is_array($block) && 'tool_use' === ($block['type'] ?? null) && self::TOOL === ($block['name'] ?? null) && \is_array($block['input'] ?? null)) {
                return ['data' => self::decodeNested($block['input'], $schema), 'model' => \is_string($body['model'] ?? null) ? $body['model'] : $model];
            }
        }

        throw new AiException('Die Antwort von Claude enthält keine strukturierten Daten.');
    }

    /**
     * Claude liefert verschachtelte Listen/Objekte in Werkzeug-Eingaben gelegentlich als JSON-Text
     * ("[{\"code\": …}]"). Solche Werte werden zurückverwandelt, wenn das Schema dort Liste/Objekt erwartet.
     *
     * @param array<mixed>         $data
     * @param array<string, mixed> $schema
     *
     * @return array<mixed>
     */
    private static function decodeNested(array $data, array $schema): array
    {
        $properties = \is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        foreach ($data as $key => $value) {
            $sub = \is_array($properties[$key] ?? null) ? $properties[$key] : [];
            $types = (array) ($sub['type'] ?? []);
            if (\is_string($value) && (\in_array('array', $types, true) || \in_array('object', $types, true))) {
                $decoded = json_decode($value, true);
                if (\is_array($decoded)) {
                    $value = $decoded;
                }
            }
            if (\is_array($value) && \in_array('object', $types, true)) {
                $value = self::decodeNested($value, $sub);
            } elseif (\is_array($value) && \in_array('array', $types, true) && \is_array($sub['items'] ?? null)) {
                $value = self::decodeList($value, $sub['items']);
            }
            $data[$key] = $value;
        }

        return $data;
    }

    /**
     * @param array<mixed>         $list
     * @param array<string, mixed> $itemSchema
     *
     * @return array<mixed>
     */
    private static function decodeList(array $list, array $itemSchema): array
    {
        foreach ($list as $i => $item) {
            if (\is_array($item)) {
                $list[$i] = self::decodeNested($item, $itemSchema);
            }
        }

        return $list;
    }

    public function ping(string $model): string
    {
        $result = $this->generateJson($model, [AiInput::text('Antworte mit {"ok": true}.')], [
            'type' => 'object',
            'properties' => ['ok' => ['type' => 'boolean']],
            'required' => ['ok'],
        ]);

        return $result['model'];
    }
}
