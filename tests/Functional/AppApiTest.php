<?php

namespace App\Tests\Functional;

use App\Cache\ContentVersion;
use App\Entity\AppToken;
use App\Entity\Item;
use App\Entity\ScanJob;
use App\Enum\DuplicateStatus;
use App\Enum\ItemStatus;
use App\Enum\ScanStatus;
use App\EventSubscriber\AppApiExceptionSubscriber;
use App\Import\FolderImporter;
use App\Import\ItemRemover;
use App\Import\UploadInbox;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\HttpCache\HttpCache;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\EventListener\ErrorListener;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Upload-Schnittstelle der Exponio-App: Token, Ablage im Upload-Verzeichnis, Prüfungen, atomarer Abschluss.
 * Das Anlegen der Tokens im Backend prüft AppTokenCrudControllerTest.
 */
final class AppApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $scanDir;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->scanDir = static::getContainer()->get(FolderImporter::class)->getScanDir();
    }

    protected function tearDown(): void
    {
        // vom Sofortimport angelegte Objekte samt Webbildern wieder entfernen, damit spätere Tests sie nicht sehen
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $items = $em->getRepository(Item::class)->createQueryBuilder('i')->where('i.sourceFolder LIKE :f')->setParameter('f', '2031-%')->getQuery()->getResult();
        foreach ($items as $item) {
            static::getContainer()->get(ItemRemover::class)->remove($item);
        }
        $fs = new Filesystem();
        foreach ([...glob($this->scanDir.'/2031-*') ?: [], ...glob($this->scanDir.'/_tmp_2031-*') ?: []] as $dir) {
            $fs->remove($dir);
        }
        parent::tearDown();
    }

    public function testRequiresValidToken(): void
    {
        $this->client->request('GET', '/api/app/v1/status');
        self::assertResponseStatusCodeSame(401);
        self::assertSame('AUTH_FAILED', $this->error()['code']);
        self::assertFalse($this->client->getResponse()->headers->has('Set-Cookie'), 'keine Session');

        $this->api('GET', '/status', token: AppToken::PREFIX.'falsch');
        self::assertResponseStatusCodeSame(401);
        self::assertSame('AUTH_FAILED', $this->error()['code']);
        self::assertStringContainsString('App-Zugänge', $this->error()['message']);

        $token = $this->createToken();
        $this->api('GET', '/gibt-es-nicht', token: $token);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('NOT_FOUND', $this->error()['code'], 'JSON statt HTML-Fehlerseite');

        // Apache mit PHP-FPM/CGI: public/.htaccess reicht die Kopfzeile nur als Umgebungsvariable weiter
        $this->client->request('GET', '/api/app/v1/status', server: ['REDIRECT_HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseIsSuccessful();

        // gelöschter Zugang = Gerät gesperrt
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $entity = $em->getRepository(AppToken::class)->findOneBy(['tokenHash' => AppToken::hash($token)]);
        self::assertNotNull($entity);
        $em->remove($entity);
        $em->flush();
        $this->api('GET', '/status', token: $token);
        self::assertResponseStatusCodeSame(401);
    }

    public function testStatusChecksWritableStorageWithoutTouchingContent(): void
    {
        $token = $this->createToken();
        $version = static::getContainer()->get(ContentVersion::class)->get();

        $data = $this->api('GET', '/status?writeTest=1', token: $token);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $data['api']);
        self::assertTrue($data['writeTested']);
        self::assertIsString($data['site']);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertFalse($this->client->getResponse()->headers->has('Set-Cookie'), 'keine Session');
        self::assertSame([], glob($this->scanDir.'/.exponio_probe_*'), 'Testordner wieder entfernt');

        $entity = static::getContainer()->get(EntityManagerInterface::class)->getRepository(AppToken::class)->findOneBy(['tokenHash' => AppToken::hash($token)]);
        self::assertNotNull($entity?->getLastUsedAt(), '„Zuletzt benutzt“ wird gespeichert');
        self::assertSame($version, static::getContainer()->get(ContentVersion::class)->get(), 'App-Zugänge leeren den Seiten-Cache nicht');
    }

    public function testUploadIsVerifiedAndFinishedAtomically(): void
    {
        $token = $this->createToken();
        $id = '2031-01-02_101500';
        $webp = $this->image('webp');
        $jpeg = $this->image('jpeg');
        $note = "DDR 1953, gestempelt\nRückseite: Falz ✓\n";

        self::assertSame(['state' => 'none', 'files' => []], $this->api('GET', "/uploads/$id", token: $token));

        $saved = $this->put($token, $id, '01.webp', $webp);
        self::assertResponseIsSuccessful();
        self::assertSame(['name' => '01.webp', 'size' => \strlen($webp), 'sha256' => hash('sha256', $webp)], $saved);
        self::assertFalse($this->client->getResponse()->headers->has('Set-Cookie'), 'keine Session');

        $this->put($token, $id, '02.jpg', $jpeg, sha256: hash('sha256', 'etwas anderes'));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('CHECKSUM_MISMATCH', $this->error()['code']);
        $this->put($token, $id, '02.jpg', 'kein Bild');
        self::assertResponseStatusCodeSame(415);
        self::assertSame('INVALID_FILE', $this->error()['code']);
        $this->put($token, $id, '02.webp', $jpeg);
        self::assertResponseStatusCodeSame(415, 'Endung muss zum Inhalt passen');
        $this->put($token, $id, 'shell.php', '<?php echo 1;');
        self::assertResponseStatusCodeSame(400);
        self::assertSame('INVALID_NAME', $this->error()['code']);
        self::assertFileDoesNotExist($this->scanDir."/_tmp_$id/shell.php");

        $this->put($token, $id, '02.jpg', $jpeg);
        self::assertResponseIsSuccessful();
        $this->put($token, $id, UploadInbox::NOTE_FILE, $note);
        self::assertResponseIsSuccessful();
        $this->put($token, $id, '05.webp', $webp); // Rest eines früheren Versuchs
        self::assertResponseIsSuccessful();

        $state = $this->api('GET', "/uploads/$id", token: $token);
        self::assertSame('open', $state['state']);
        self::assertSame(['01.webp', '02.jpg', '05.webp', 'info.txt'], array_column($state['files'], 'name'));
        self::assertSame([], glob($this->scanDir."/_tmp_$id/.*.part"), 'keine Teildateien übrig');
        self::assertDirectoryDoesNotExist($this->scanDir.'/'.$id, 'vor dem Abschluss unsichtbar für den Import');
        self::assertNotContains($id, static::getContainer()->get(FolderImporter::class)->findNewFolders());

        $manifest = [$this->entry('01.webp', $webp), $this->entry('02.jpg', $jpeg), $this->entry(UploadInbox::NOTE_FILE, $note)];

        $this->complete($token, $id, [...$manifest, $this->entry('03.webp', $webp)]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('VERIFY_FAILED', $this->error()['code']);
        self::assertSame(['03.webp'], $this->error()['files'] ?? null);

        $done = $this->complete($token, $id, $manifest);
        self::assertResponseIsSuccessful();
        self::assertSame(['folder' => $id, 'count' => 2, 'bytes' => \strlen($webp) + \strlen($jpeg) + \strlen($note), 'already' => false], $done);
        self::assertDirectoryDoesNotExist($this->scanDir."/_tmp_$id");
        $files = array_values(array_diff(scandir($this->scanDir.'/'.$id) ?: [], ['.', '..']));
        self::assertSame(['01.webp', '02.jpg', 'info.txt'], $files, 'überzählige Datei entfernt');
        self::assertSame($note, file_get_contents($this->scanDir."/$id/info.txt"));

        // nach der Antwort sofort importiert (kernel.terminate), die Notiz wird zum Hinweis an die KI
        self::assertNotContains($id, static::getContainer()->get(FolderImporter::class)->findNewFolders());
        $items = static::getContainer()->get(EntityManagerInterface::class)->getRepository(Item::class)->findBy(['sourceFolder' => $id]);
        self::assertCount(1, $items);
        self::assertSame(ItemStatus::Draft, $items[0]->getStatus(), 'geprüft wird von Hand');
        self::assertSame(DuplicateStatus::Pending, $items[0]->getDuplicateStatus(), 'Erkennung und Duplikatprüfung macht app:scan');
        self::assertSame("DDR 1953, gestempelt\nRückseite: Falz ✓", $items[0]->getAiHint());
        self::assertCount(2, $items[0]->getImages(), 'info.txt ist kein Bild');

        // Antwort verloren → erneutes Abschließen findet den fertigen Ordner, statt einen zweiten anzulegen (und importiert nichts doppelt)
        self::assertSame(array_merge($done, ['already' => true]), $this->complete($token, $id, $manifest));
        self::assertSame(['state' => 'none', 'files' => []], $this->api('GET', "/uploads/$id", token: $token));
        self::assertCount(1, static::getContainer()->get(EntityManagerInterface::class)->getRepository(Item::class)->findBy(['sourceFolder' => $id]));

        $this->complete($token, $id, [$this->entry('01.webp', $jpeg)]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('UPLOAD_NOT_FOUND', $this->error()['code']);
    }

    public function testFinishedFolderNeverReusesAName(): void
    {
        $token = $this->createToken();
        $id = '2031-01-03_120000';
        (new Filesystem())->dumpFile($this->scanDir."/$id/01.webp", 'vorhanden'); // z. B. noch per SFTP hochgeladen
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $job = new ScanJob($id.'_2', ScanStatus::Imported, 1); // importiert, Objekt und Ordner später gelöscht
        $em->persist($job);
        $em->flush();

        $webp = $this->image('webp');
        $this->put($token, $id, '01.webp', $webp);
        $done = $this->complete($token, $id, [$this->entry('01.webp', $webp)]);
        self::assertResponseIsSuccessful();
        self::assertSame($id.'_3', $done['folder']);
        self::assertSame('vorhanden', file_get_contents($this->scanDir."/$id/01.webp"), 'Vorhandenes bleibt unberührt');
        $new = static::getContainer()->get(FolderImporter::class)->findNewFolders();
        self::assertNotContains($id.'_3', $new, 'sofort importiert');
        self::assertContains($id, $new, 'der Sofortimport nimmt nur den eigenen Ordner, nicht andere neue');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $job = $em->find(ScanJob::class, $job->getId());
        self::assertNotNull($job);
        $em->remove($job);
        $em->flush();
    }

    public function testRejectsInvalidRequests(): void
    {
        $token = $this->createToken();
        $id = '2031-01-04_080000';
        $webp = $this->image('webp');

        $this->api('GET', '/uploads/2031-01-04', token: $token);
        self::assertResponseStatusCodeSame(400);
        self::assertSame('INVALID_NAME', $this->error()['code']);

        $this->client->request('PUT', "/api/app/v1/uploads/$id/files/01.webp", server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token], content: $webp);
        self::assertResponseStatusCodeSame(400, 'Prüfsumme fehlt');
        self::assertSame('INVALID_REQUEST', $this->error()['code']);

        $this->put($token, $id, '01.webp', $webp, length: UploadInbox::MAX_FILE_SIZE + 1);
        self::assertResponseStatusCodeSame(413);
        self::assertSame('FILE_TOO_LARGE', $this->error()['code']);
        $this->put($token, $id, UploadInbox::NOTE_FILE, str_repeat('x', UploadInbox::MAX_NOTE_SIZE + 1));
        self::assertResponseStatusCodeSame(413);
        self::assertStringContainsString('64 KB', $this->error()['message']);
        $this->put($token, $id, UploadInbox::NOTE_FILE, "\xC3\x28");
        self::assertResponseStatusCodeSame(415, 'Notiz muss UTF-8 sein');
        $this->put($token, $id, '01.webp', $webp, length: \strlen($webp) + 10);
        self::assertResponseStatusCodeSame(400);
        self::assertSame('INCOMPLETE', $this->error()['code']);

        $this->client->request('POST', "/api/app/v1/uploads/$id/complete", server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'CONTENT_TYPE' => 'application/json'], content: '{kaputt');
        self::assertResponseStatusCodeSame(400);
        self::assertSame('INVALID_REQUEST', $this->error()['code']);
        $this->complete($token, $id, [$this->entry(UploadInbox::NOTE_FILE, 'nur Text')]);
        self::assertResponseStatusCodeSame(400, 'ohne Fotos');
        $this->complete($token, $id, [$this->entry('01.webp', $webp), $this->entry('01.webp', $webp)]);
        self::assertResponseStatusCodeSame(400, 'doppelt');
        self::assertDirectoryDoesNotExist($this->scanDir.'/'.$id);
    }

    public function testUploadPassesThroughProdHttpCache(): void
    {
        $token = $this->createToken();
        $id = '2031-01-05_090000';
        $webp = $this->image('webp');
        $kernel = $this->client->getKernel();
        // wie in prod (framework.http_cache): Symfonys Reverse-Proxy vor dem Kernel
        $cacheDir = $kernel->getCacheDir().'/http_cache_app_api';
        $cache = new HttpCache($kernel, $cacheDir);
        $auth = ['HTTP_AUTHORIZATION' => 'Bearer '.$token];
        $state = static function () use ($cache, $id, $auth): mixed {
            $response = $cache->handle(Request::create("/api/app/v1/uploads/$id", 'GET', server: $auth));
            self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

            return json_decode((string) $response->getContent(), true)['state'] ?? null;
        };

        try {
            self::assertSame('none', $state());
            $response = $cache->handle(Request::create("/api/app/v1/uploads/$id/files/01.webp", 'PUT', server: $auth + [
                'HTTP_X_CONTENT_SHA256' => hash('sha256', $webp),
                'CONTENT_TYPE' => 'application/octet-stream',
                'CONTENT_LENGTH' => (string) \strlen($webp),
            ], content: $webp));
            self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
            self::assertSame(hash('sha256', $webp), json_decode((string) $response->getContent(), true)['sha256'] ?? null, 'Body kommt unverändert durch');
            self::assertSame('open', $state(), 'Abfrage nicht aus dem Seiten-Cache beantwortet');

            self::assertSame(200, $cache->handle(Request::create('/api/app/v1/status', 'GET', server: $auth))->getStatusCode());
            $response = $cache->handle(Request::create('/api/app/v1/status', 'GET', server: ['HTTP_AUTHORIZATION' => 'Bearer '.AppToken::PREFIX.'falsch']));
            self::assertSame(401, $response->getStatusCode(), 'Statusabfrage mit anderem Token nicht aus dem Cache');
        } finally {
            (new Filesystem())->remove($cacheDir);
        }
    }

    public function testSymlinkedTmpFolderIsNeverUsed(): void
    {
        $token = $this->createToken();
        $id = '2031-01-06_070000';
        $webp = $this->image('webp');
        $fs = new Filesystem();
        $outside = \dirname($this->scanDir).'/test-outside-'.bin2hex(random_bytes(3));
        $fs->dumpFile($outside.'/fremd.txt', 'bleibt');
        symlink($outside, $this->scanDir."/_tmp_$id");

        try {
            $this->put($token, $id, '01.webp', $webp);
            self::assertResponseStatusCodeSame(500);
            self::assertSame('STORAGE_ERROR', $this->error()['code']);
            self::assertSame(['state' => 'none', 'files' => []], $this->api('GET', "/uploads/$id", token: $token));
            $this->complete($token, $id, [$this->entry('01.webp', $webp)]);
            self::assertSame('STORAGE_ERROR', $this->error()['code']);
            self::assertSame(['fremd.txt'], array_values(array_diff(scandir($outside) ?: [], ['.', '..'])), 'außerhalb von SCAN_DIR nichts geschrieben oder gelöscht');
        } finally {
            $fs->remove([$this->scanDir."/_tmp_$id", $outside]);
        }
    }

    public function testApiErrorsAreAnsweredBeforeSymfonyLogsThemAsCritical(): void
    {
        $order = array_map(
            static fn (mixed $listener): string => \is_array($listener) && \is_object($listener[0]) && \is_string($listener[1]) ? $listener[0]::class.'::'.$listener[1] : '',
            static::getContainer()->get(EventDispatcherInterface::class)->getListeners(KernelEvents::EXCEPTION),
        );
        $ours = array_search(AppApiExceptionSubscriber::class.'::onException', $order, true);
        $log = array_search(ErrorListener::class.'::logKernelException', $order, true);
        self::assertIsInt($ours);
        self::assertIsInt($log);
        self::assertLessThan($log, $ours);
    }

    private function createToken(): string
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $token = (new AppToken())->setLabel('Test');
        $em->persist($token);
        $em->flush();

        return (string) $token->takePlainToken();
    }

    /** @return array<string, mixed> */
    private function api(string $method, string $path, string $token): array
    {
        $this->client->request($method, '/api/app/v1'.$path, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        return $this->json();
    }

    /** @return array<string, mixed> */
    private function put(string $token, string $id, string $name, string $content, ?string $sha256 = null, ?int $length = null): array
    {
        $this->client->request('PUT', "/api/app/v1/uploads/$id/files/$name", server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'HTTP_X_CONTENT_SHA256' => $sha256 ?? hash('sha256', $content),
            'CONTENT_TYPE' => 'application/octet-stream',
            'CONTENT_LENGTH' => (string) ($length ?? \strlen($content)),
        ], content: $content);

        return $this->json();
    }

    /**
     * @param list<array{name: string, size: int, sha256: string}> $files
     *
     * @return array<string, mixed>
     */
    private function complete(string $token, string $id, array $files): array
    {
        $this->client->request('POST', "/api/app/v1/uploads/$id/complete", server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode(['files' => $files], \JSON_THROW_ON_ERROR));

        return $this->json();
    }

    /** @return array{name: string, size: int, sha256: string} */
    private function entry(string $name, string $content): array
    {
        return ['name' => $name, 'size' => \strlen($content), 'sha256' => hash('sha256', $content)];
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        $response = $this->client->getResponse();
        self::assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
        $data = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }

    /** @return array{code: string, message: string, files?: list<string>} */
    private function error(): array
    {
        $error = $this->json()['error'] ?? null;
        self::assertIsArray($error);
        self::assertIsString($error['code'] ?? null);
        self::assertIsString($error['message'] ?? null);

        /** @var array{code: string, message: string, files?: list<string>} $error */
        return $error;
    }

    private function image(string $format): string
    {
        $image = imagecreatetruecolor(120, 90);
        imagefilledrectangle($image, 0, 0, 120, 90, (int) imagecolorallocate($image, 160, 60, 40));
        ob_start();
        'webp' === $format ? imagewebp($image) : imagejpeg($image);

        return (string) ob_get_clean();
    }
}
