<?php

namespace App\Tests\Unit;

use App\Ai\AiException;
use App\Ai\GeminiClient;
use App\Ai\GeminiCredentials;
use App\Ai\SecretBox;
use App\Entity\Settings;
use App\Service\SettingsProvider;
use App\Translation\PlaceholderGuard;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
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
        (new GeminiCredentials(null, $settings, $box))->store('backend-key-1234');
        self::assertNotNull($settings->get()->getGeminiApiKey());

        $fromBackend = new GeminiCredentials('', $settings, $box);
        self::assertSame('backend-key-1234', $fromBackend->apiKey());
        self::assertSame(GeminiCredentials::SOURCE_BACKEND, $fromBackend->source());
        self::assertSame('…1234', $fromBackend->hint());

        $fromEnv = new GeminiCredentials('env-key-9999', $settings, $box);
        self::assertSame('env-key-9999', $fromEnv->apiKey());
        self::assertSame(GeminiCredentials::SOURCE_ENV, $fromEnv->source());
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
        $result = $client->generateJson('gemini-test', [GeminiClient::text('Prompt'), GeminiClient::image('QUJD', 'image/webp')], ['type' => 'object']);

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
            (new GeminiClient($http, $this->credentials('falsch')))->generateJson('m', [GeminiClient::text('x')], []);
            self::fail('Fehler erwartet');
        } catch (AiException $e) {
            self::assertStringContainsString('API key not valid', $e->getMessage());
        }

        $this->expectException(AiException::class);
        $this->expectExceptionMessage('Kein Gemini-API-Key');
        (new GeminiClient(new MockHttpClient(), $this->credentials(null)))->generateJson('m', [], []);
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

    private function credentials(?string $envKey): GeminiCredentials
    {
        return new GeminiCredentials($envKey, $this->settings(), new SecretBox('geheim'));
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
