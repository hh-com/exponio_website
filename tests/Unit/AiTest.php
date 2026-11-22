<?php

namespace App\Tests\Unit;

use App\Ai\AiCredentials;
use App\Ai\AiException;
use App\Ai\AiInput;
use App\Ai\AiProgress;
use App\Ai\AiProvider;
use App\Ai\AiRetryStrategy;
use App\Ai\AnthropicClient;
use App\Ai\GeminiClient;
use App\Ai\SecretBox;
use App\Entity\Settings;
use App\Service\SettingsProvider;
use App\Translation\PlaceholderGuard;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AiTest extends TestCase
{
    public function testSecretBoxRoundTripAndWrongSecret(): void
    {
        $box = new SecretBox('geheim-1');
        $encrypted = $box->encrypt('AIza-test-key');
        self::assertStringNotContainsString('AIza', $encrypted);
        self::assertSame('AIza-test-key', $box->decrypt($encrypted));
        self::assertNull((new SecretBox('geheim-2'))->decrypt($encrypted), 'anderes APP_SECRET → nicht lesbar');
        self::assertNull($box->decrypt('kaputt'));
    }

    public function testEnvKeyHasPriorityOverStoredKey(): void
    {
        $box = new SecretBox('geheim');
        $settings = $this->settings();
        $this->creds(null, null, $settings, $box)->store('backend-key-1234', AiProvider::GEMINI);
        self::assertNotNull($settings->get()->getApiKey(AiProvider::GEMINI));

        $fromBackend = $this->creds('', '', $settings, $box);
        self::assertSame('backend-key-1234', $fromBackend->apiKey(AiProvider::GEMINI));
        self::assertSame(AiCredentials::SOURCE_BACKEND, $fromBackend->source(AiProvider::GEMINI));
        self::assertSame('…1234', $fromBackend->hint(AiProvider::GEMINI));
        self::assertNull($fromBackend->apiKey(AiProvider::ANTHROPIC), 'Keys sind je Anbieter getrennt');

        $fromEnv = $this->creds('env-key-9999', null, $settings, $box);
        self::assertSame('env-key-9999', $fromEnv->apiKey(AiProvider::GEMINI));
        self::assertSame(AiCredentials::SOURCE_ENV, $fromEnv->source(AiProvider::GEMINI));
    }

    public function testActiveProviderFollowsSettings(): void
    {
        $settings = $this->settings();
        $provider = new AiProvider('gemini', $settings);
        self::assertSame(AiProvider::GEMINI, $provider->name());
        self::assertFalse($provider->isFake());
        self::assertTrue((new AiProvider('FAKE', $settings))->isFake());

        $settings->get()->setAiProvider(AiProvider::ANTHROPIC);
        self::assertSame(AiProvider::ANTHROPIC, $provider->name());
        self::assertSame(AiProvider::GEMINI, $provider->resolve(null), 'keine Auswahl = AI_PROVIDER');
        self::assertSame(AiProvider::GEMINI, $provider->resolve('unbekannt'));
        self::assertSame(AiProvider::ANTHROPIC, (new AiProvider('anthropic', $settings))->resolve(null));
    }

    public function testAnthropicRequestAndResponse(): void
    {
        $sent = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$sent): MockResponse {
            $sent = ['method' => $method, 'url' => $url, 'headers' => $options['headers'], 'body' => json_decode($options['body'], true)];

            return new MockResponse((string) json_encode([
                'model' => 'claude-test-1',
                'stop_reason' => 'tool_use',
                'content' => [
                    ['type' => 'text', 'text' => 'ignorieren'],
                    ['type' => 'tool_use', 'id' => 't1', 'name' => 'antwort', 'input' => ['title' => 'Bund 1955', 'confidence' => 0.9]],
                ],
            ]));
        }, 'https://api.anthropic.com/v1/');

        $client = new AnthropicClient($http, $this->credentials(null, 'ant-key'));
        $schema = ['type' => 'object', 'properties' => ['title' => ['type' => 'string']]];
        $result = $client->generateJson('claude-test', [AiInput::text('Prompt'), AiInput::image('QUJD', 'image/webp')], $schema);

        self::assertSame(['title' => 'Bund 1955', 'confidence' => 0.9], $result['data']);
        self::assertSame('claude-test-1', $result['model']);
        self::assertSame('POST', $sent['method']);
        self::assertSame('https://api.anthropic.com/v1/messages', $sent['url']);
        self::assertContains('x-api-key: ant-key', $sent['headers']);
        self::assertSame('claude-test', $sent['body']['model']);
        self::assertSame(['type' => 'text', 'text' => 'Prompt'], $sent['body']['messages'][0]['content'][0]);
        self::assertSame(['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/webp', 'data' => 'QUJD']], $sent['body']['messages'][0]['content'][1]);
        self::assertSame($schema, $sent['body']['tools'][0]['input_schema']);
        self::assertSame(['type' => 'tool', 'name' => 'antwort'], $sent['body']['tool_choice']);
    }

    public function testAnthropicDecodesNestedJsonStrings(): void
    {
        $http = new MockHttpClient(new MockResponse((string) json_encode([
            'content' => [['type' => 'tool_use', 'name' => 'antwort', 'input' => [
                'title' => '[kein JSON, bleibt Text]',
                'attributes' => '[{"code": "michel", "value": "123"}]',
                'images' => [['index' => 1, 'meta' => '{"a": 1}']],
            ]]],
        ])));
        $schema = ['type' => 'object', 'properties' => [
            'title' => ['type' => 'string'],
            'attributes' => ['type' => 'array', 'items' => ['type' => 'object']],
            'images' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['meta' => ['type' => ['object', 'null']]]]],
        ]];
        $data = (new AnthropicClient($http, $this->credentials(null, 'k')))->generateJson('m', [AiInput::text('x')], $schema)['data'];

        self::assertSame('[kein JSON, bleibt Text]', $data['title']);
        self::assertSame([['code' => 'michel', 'value' => '123']], $data['attributes']);
        self::assertSame(['a' => 1], $data['images'][0]['meta']);
    }

    public function testAnthropicErrors(): void
    {
        $http = new MockHttpClient(new MockResponse('{"type":"error","error":{"type":"overloaded_error","message":"Overloaded"}}', ['http_code' => 529]));
        try {
            (new AnthropicClient($http, $this->credentials(null, 'k')))->generateJson('m', [AiInput::text('x')], []);
            self::fail('Fehler erwartet');
        } catch (AiException $e) {
            self::assertStringContainsString('529', $e->getMessage());
            self::assertStringContainsString('Overloaded', $e->getMessage());
        }

        $truncated = new MockHttpClient(new MockResponse('{"stop_reason":"max_tokens","content":[]}'));
        try {
            (new AnthropicClient($truncated, $this->credentials(null, 'k')))->generateJson('m', [AiInput::text('x')], []);
            self::fail('Fehler erwartet');
        } catch (AiException $e) {
            self::assertStringContainsString('abgeschnitten', $e->getMessage());
        }

        $this->expectException(AiException::class);
        $this->expectExceptionMessage('Kein Anthropic-API-Key');
        (new AnthropicClient(new MockHttpClient(), $this->credentials(null, null)))->generateJson('m', [], []);
    }

    public function testGeminiRequestAndResponse(): void
    {
        $sent = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$sent): MockResponse {
            $sent = ['method' => $method, 'url' => $url, 'headers' => $options['headers'], 'body' => json_decode($options['body'], true)];

            return new MockResponse((string) json_encode([
                'id' => 'int_1',
                'status' => 'completed',
                'steps' => [
                    ['type' => 'thought', 'content' => [['type' => 'text', 'text' => 'ignorieren']]],
                    ['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"title": "Bund 1955",'], ['type' => 'text', 'text' => ' "confidence": 0.9}']]],
                ],
            ]));
        }, 'https://generativelanguage.googleapis.com/v1beta/');

        $client = new GeminiClient($http, $this->credentials('test-key'));
        $result = $client->generateJson('gemini-test', [AiInput::text('Prompt'), AiInput::image('QUJD', 'image/webp')], ['type' => 'object']);

        self::assertSame(['title' => 'Bund 1955', 'confidence' => 0.9], $result['data']);
        self::assertSame('POST', $sent['method']);
        self::assertSame('https://generativelanguage.googleapis.com/v1beta/interactions', $sent['url']);
        self::assertContains('x-goog-api-key: test-key', $sent['headers']);
        self::assertSame('gemini-test', $sent['body']['model']);
        self::assertFalse($sent['body']['store'], 'keine Speicherung bei Google');
        self::assertSame(['type' => 'image', 'data' => 'QUJD', 'mime_type' => 'image/webp'], $sent['body']['input'][1]);
        self::assertSame('application/json', $sent['body']['response_format']['mime_type']);
    }

    public function testGeminiErrors(): void
    {
        $http = new MockHttpClient(new MockResponse('{"error": {"code": 400, "message": "API key not valid"}}', ['http_code' => 400]));
        try {
            (new GeminiClient($http, $this->credentials('falsch')))->generateJson('m', [AiInput::text('x')], []);
            self::fail('Fehler erwartet');
        } catch (AiException $e) {
            self::assertStringContainsString('API key not valid', $e->getMessage());
        }

        $this->expectException(AiException::class);
        $this->expectExceptionMessage('Kein Gemini-API-Key');
        (new GeminiClient(new MockHttpClient(), $this->credentials(null)))->generateJson('m', [], []);
    }

    public function testProgressIsStoredPerRequestId(): void
    {
        $cache = new ArrayAdapter();
        $requests = new RequestStack();
        $settings = $this->settings();
        $settings->get()->setAiProvider(AiProvider::ANTHROPIC);
        $progress = new AiProgress($requests, $cache, new AiProvider('gemini', $settings));
        self::assertSame('Claude', $progress->aiName());

        $progress->step('ohne Anfrage (Cronjob)');
        $id = 'b3f1c0de-0000-4000-8000-000000000001';
        self::assertSame([], $progress->steps($id));

        $request = new Request();
        $request->headers->set(AiProgress::HEADER, $id);
        $requests->push($request);
        $progress->step('Schritt 1');
        $progress->step('Schritt 2');
        self::assertSame(['Schritt 1', 'Schritt 2'], array_column($progress->steps($id), 'message'));

        $requests->pop();
        $requests->push(new Request(request: [AiProgress::FIELD => 'zu-kurz']));
        $progress->step('ungültige ID wird ignoriert');
        self::assertSame([], $progress->steps('zu-kurz'));
        self::assertSame([], $progress->steps('../../etc/passwd'));
    }

    public function testRetriesAreReportedAsProgress(): void
    {
        $id = 'b3f1c0de-0000-4000-8000-000000000002';
        $requests = new RequestStack();
        $request = new Request();
        $request->headers->set(AiProgress::HEADER, $id);
        $requests->push($request);
        $settings = $this->settings();
        $progress = new AiProgress($requests, new ArrayAdapter(), new AiProvider('gemini', $settings));

        $http = new RetryableHttpClient(new MockHttpClient([
            new MockResponse('{"error":{"message":"high demand"}}', ['http_code' => 503]),
            new MockResponse('{"error":{"message":"high demand"}}', ['http_code' => 503]),
            new MockResponse('{"ok":true}'),
        ]), new AiRetryStrategyWithoutDelay($progress), 3);

        self::assertSame(200, $http->request('POST', 'https://example.test/')->getStatusCode());
        self::assertSame([
            'Gemini ist überlastet (Fehler 503) – Versuch 2 folgt gleich …',
            'Gemini ist überlastet (Fehler 503) – Versuch 3 folgt gleich …',
        ], array_column($progress->steps($id), 'message'));
    }

    public function testPlaceholderGuard(): void
    {
        self::assertTrue(PlaceholderGuard::isValid('Seite %page% von %pages%', 'Page %page% sur %pages%'));
        self::assertFalse(PlaceholderGuard::isValid('Seite %page% von %pages%', 'Page %page%'));
        self::assertTrue(PlaceholderGuard::isValid('{0} keine Objekte|{1} 1 Objekt|]1,Inf[ %count% Objekte', '{0} aucun objet|{1} 1 objet|]1,Inf[ %count% objets'));
        self::assertFalse(PlaceholderGuard::isValid('{0} keine|{1} eins|]1,Inf[ %count% viele', '%count% objets'));
        self::assertTrue(PlaceholderGuard::isValid('<p>Hallo <a href="%url%">Link</a></p>', '<p>Bonjour <a href="%url%">lien</a></p>'));
        self::assertFalse(PlaceholderGuard::isValid('<p>Hallo</p>', 'Bonjour'));
        self::assertFalse(PlaceholderGuard::isValid('Text', '   '));
    }

    private function credentials(?string $geminiKey, ?string $anthropicKey = null): AiCredentials
    {
        return $this->creds($geminiKey, $anthropicKey, $this->settings(), new SecretBox('geheim'));
    }

    private function creds(?string $geminiKey, ?string $anthropicKey, SettingsProvider $settings, SecretBox $box): AiCredentials
    {
        return new AiCredentials($geminiKey, $anthropicKey, $settings, $box, new AiProvider('gemini', $settings));
    }

    private function settings(): SettingsProvider
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $provider = new SettingsProvider($em);
        // Einstellungen ohne Datenbank: privates Feld direkt setzen
        (new \ReflectionProperty(SettingsProvider::class, 'settings'))->setValue($provider, new Settings());

        return $provider;
    }
}

/** Wie AiRetryStrategy, aber ohne Wartezeit – damit der Test nicht 6 Sekunden schläft */
final class AiRetryStrategyWithoutDelay implements \Symfony\Component\HttpClient\Retry\RetryStrategyInterface
{
    private AiRetryStrategy $inner;

    public function __construct(AiProgress $progress)
    {
        $this->inner = new AiRetryStrategy($progress);
    }

    public function shouldRetry(\Symfony\Component\HttpClient\Response\AsyncContext $context, ?string $responseContent, ?\Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface $exception): ?bool
    {
        return $this->inner->shouldRetry($context, $responseContent, $exception);
    }

    public function getDelay(\Symfony\Component\HttpClient\Response\AsyncContext $context, ?string $responseContent, ?\Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface $exception): int
    {
        return 0;
    }
}
