<?php

namespace App\Ai;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Schlanker Client für die OpenAI Responses API (GPT-Modelle, „ChatGPT“).
 *
 *   POST https://api.openai.com/v1/responses
 *   Header Authorization: Bearer …, Antwort als JSON nach Schema (text.format json_schema, strict), store: false
 *
 * Bilder gehen als Data-URI in hoher Detailstufe mit (Zähnung, Druckbild, Stempel).
 * Wiederholungen bei 429/5xx übernimmt der HTTP-Client (config/packages/framework.yaml, "openai.client").
 */
final class OpenAiClient implements AiClientInterface
{
    public function __construct(
        private readonly HttpClientInterface $openaiClient,
        private readonly AiCredentials $credentials,
    ) {
    }

    public function generateJson(string $model, array $input, array $schema): array
    {
        $apiKey = $this->credentials->apiKey(AiProvider::OPENAI);
        if (null === $apiKey || '' === $apiKey) {
            throw new AiException('Kein OpenAI-API-Key hinterlegt (Backend → Einstellungen → KI).');
        }

        $content = [];
        foreach ($input as $part) {
            $content[] = 'image' === $part['type']
                ? ['type' => 'input_image', 'image_url' => sprintf('data:%s;base64,%s', $part['mime_type'], $part['data']), 'detail' => 'high']
                : ['type' => 'input_text', 'text' => $part['text']];
        }

        try {
            $response = $this->openaiClient->request('POST', 'responses', [
                'headers' => ['Authorization' => 'Bearer '.$apiKey],
                'json' => [
                    'model' => $model,
                    'input' => [['role' => 'user', 'content' => [] === $content ? [['type' => 'input_text', 'text' => 'JSON']] : $content]],
                    'text' => ['format' => [] === $schema
                        ? ['type' => 'json_object']
                        : ['type' => 'json_schema', 'name' => 'antwort', 'schema' => $schema, 'strict' => true]],
                    'store' => false,
                ],
            ]);
            $status = $response->getStatusCode();
            $body = $response->toArray(false);
        } catch (ExceptionInterface $e) {
            throw new AiException('OpenAI nicht erreichbar: '.$e->getMessage(), 0, $e);
        }

        if ($status >= 400) {
            $message = \is_array($body['error'] ?? null) ? (string) ($body['error']['message'] ?? '') : '';
            throw new AiException(sprintf('OpenAI-Fehler %d: %s', $status, '' !== $message ? $message : 'unbekannt'));
        }
        if ('incomplete' === ($body['status'] ?? null)) {
            $reason = \is_array($body['incomplete_details'] ?? null) ? (string) ($body['incomplete_details']['reason'] ?? '') : '';
            throw new AiException('content_filter' === $reason
                ? 'OpenAI hat die Antwort gefiltert.'
                : 'Die Antwort von OpenAI wurde abgeschnitten (zu lang).');
        }
        if (isset($body['status']) && 'completed' !== $body['status']) {
            throw new AiException(sprintf('OpenAI-Antwort nicht abgeschlossen (Status "%s").', (string) $body['status']));
        }

        [$json, $refusal] = self::outputText($body);
        if (null !== $refusal) {
            throw new AiException('OpenAI hat die Anfrage abgelehnt: '.$refusal);
        }
        if ('' === $json) {
            throw new AiException('Die Antwort von OpenAI enthält keinen Text.');
        }
        try {
            $data = json_decode($json, true, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new AiException('Ungültiges JSON in der Antwort: '.$e->getMessage(), 0, $e);
        }
        if (!\is_array($data)) {
            throw new AiException('Unerwartetes Antwortformat.');
        }

        // output_tokens enthält die Denk-Tokens (output_tokens_details.reasoning_tokens) bereits
        $usage = \is_array($body['usage'] ?? null) ? $body['usage'] : [];

        return [
            'data' => $data,
            'model' => \is_string($body['model'] ?? null) ? $body['model'] : $model,
            'usage' => ['input' => AiUsage::sum($usage, 'input_tokens'), 'output' => AiUsage::sum($usage, 'output_tokens')],
        ];
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
     * Text der Antwort: output[] mit type "message", darin content[] mit type "output_text" (oder "refusal").
     * Denkschritte (type "reasoning") werden übersprungen.
     *
     * @param array<mixed> $body
     *
     * @return array{string, string|null} [Text, Ablehnung]
     */
    private static function outputText(array $body): array
    {
        $text = '';
        $refusal = null;
        foreach (\is_array($body['output'] ?? null) ? $body['output'] : [] as $item) {
            if (!\is_array($item) || 'message' !== ($item['type'] ?? null)) {
                continue;
            }
            foreach (\is_array($item['content'] ?? null) ? $item['content'] : [] as $part) {
                if (!\is_array($part)) {
                    continue;
                }
                if ('output_text' === ($part['type'] ?? null) && \is_string($part['text'] ?? null)) {
                    $text .= $part['text'];
                } elseif ('refusal' === ($part['type'] ?? null)) {
                    $refusal = \is_string($part['refusal'] ?? null) ? $part['refusal'] : 'ohne Begründung';
                }
            }
        }

        return [trim($text), $refusal];
    }
}
