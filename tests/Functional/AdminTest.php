<?php

namespace App\Tests\Functional;

use App\Ai\AiProvider;
use App\Entity\Item;
use App\Entity\ItemImage;
use App\Entity\ScanJob;
use App\Entity\Settings;
use App\Enum\ImageSide;
use App\Enum\ItemStatus;
use App\Import\FolderImporter;
use App\Media\MediaStorage;
use App\Recognition\PromptBuilder;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Filesystem\Filesystem;

final class AdminTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testBackendRequiresLogin(): void
    {
        $this->client->request('GET', '/admin');
        self::assertResponseRedirects('/admin/login');
        $this->client->request('GET', '/admin/login');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/admin/api/review');
        self::assertResponseRedirects('/admin/login');
    }

    public function testBackendPagesRender(): void
    {
        $this->login();
        $item = static::getContainer()->get(EntityManagerInterface::class)->getRepository(Item::class)->findOneBy([], ['id' => 'ASC']);
        self::assertNotNull($item);
        foreach (['/admin', '/admin/review', '/admin/item', '/admin/item/new', '/admin/item/'.$item->getId().'/edit', '/admin/item/'.$item->getId(),
            '/admin/collection-area', '/admin/collection-area/1/edit', '/admin/category', '/admin/topic', '/admin/inquiry',
            '/admin/page', '/admin/page/1/edit', '/admin/settings/1/edit', '/admin/language', '/admin/language/fr/edit', '/admin/ui-translation',
            '/admin/scan-job', '/admin/user'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful($url);
            self::assertStringNotContainsString('Nicht zugreifbar', (string) $this->client->getResponse()->getContent(), $url);
        }
        $html = (string) $this->client->getResponse()->getContent();
        foreach (['Objektarten', 'Länder & Gebiete', 'Nachrichten', 'Sprachen', 'Oberflächentexte'] as $menu) {
            self::assertStringContainsString($menu, $html);
        }
    }

    public function testItemSearchCombinesWithFilters(): void
    {
        $this->login();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $item = $em->getRepository(Item::class)->findOneBy([], ['id' => 'ASC']);
        self::assertNotNull($item);
        $item->setDefects('Eckbug unten links')->setStorageLocation('Album 7');
        $em->flush();

        foreach (['Eckbug' => true, 'Album 7' => true, 'Eckbug Album' => true, 'Eckbug Gibtsnicht' => false] as $query => $found) {
            $this->client->request('GET', '/admin/item?query='.urlencode($query));
            self::assertResponseIsSuccessful();
            $html = (string) $this->client->getResponse()->getContent();
            self::assertSame($found, str_contains($html, '/admin/item/'.$item->getId().'/edit'), $query);
        }
        $this->client->request('GET', '/admin/item?query=Eckbug&filters[hasDefects]=1');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('/admin/item/'.$item->getId().'/edit', (string) $this->client->getResponse()->getContent());
    }

    public function testReviewApiSavesAndPublishes(): void
    {
        $this->login();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $item = $em->getRepository(Item::class)->findOneBy([], ['id' => 'DESC']);
        self::assertNotNull($item);
        $item->setStatus(ItemStatus::Review);
        $em->flush();

        $crawler = $this->client->request('GET', '/admin/review');
        $props = json_decode((string) $crawler->filter('#review-app')->attr('data-props'), true);

        $this->client->request('GET', $props['api']);
        $queue = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertContains($item->getId(), array_column($queue['items'], 'id'));
        self::assertNotEmpty($queue['meta']['areas']);
        self::assertNotEmpty($queue['meta']['sides']);

        $url = $props['api'].'/'.$item->getId();
        $this->client->request('POST', $url, server: ['CONTENT_TYPE' => 'application/json'], content: '{"action":"publish"}');
        self::assertResponseStatusCodeSame(403, 'ohne CSRF-Token');

        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $props['csrf']];
        $this->client->request('POST', $url, server: $headers, content: (string) json_encode(['title' => '', 'action' => 'publish']));
        self::assertResponseStatusCodeSame(422, 'Titel ist Pflicht');

        $image = $item->getImages()->first();
        self::assertNotFalse($image);
        $imageId = $image->getId();
        $this->client->request('POST', $url, server: $headers, content: (string) json_encode([
            'title' => 'Geprüfter Titel',
            'defects' => 'kurzer Zahn',
            'year' => 1955,
            'catalogValue' => '12,50',
            'showEstimate' => true,
            'attributes' => ['condition' => 'postfrisch (**)'],
            'images' => [['id' => $imageId, 'side' => 'back', 'alt' => 'Rückseite mit Falz']],
            'action' => 'publish',
        ]));
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true)['item'];
        self::assertSame('published', $data['status']);
        self::assertSame('Geprüfter Titel', $data['title']);
        self::assertSame('kurzer Zahn', $data['defects']);
        self::assertSame('12.50', $data['catalogValue']);
        self::assertTrue($data['showEstimate']);
        self::assertSame(ImageSide::Back->value, $data['images'][0]['side']);
        self::assertSame('Rückseite mit Falz', $data['images'][0]['alt']);
        if (\array_key_exists('condition', $data['attributes'])) {
            self::assertSame('mnh', $data['attributes']['condition'], 'Label wird auf den Optionswert abgebildet');
        }
    }

    public function testPossibleDuplicateIsDecidedInReviewAndShownInUploadHistory(): void
    {
        $this->login();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $file = sys_get_temp_dir().'/duplicate-admin-'.bin2hex(random_bytes(3)).'.png';
        imagepng(imagecreatetruecolor(60, 40), $file);
        $saved = static::getContainer()->get(MediaStorage::class)->store($file, 'tests/duplicate-admin');
        unlink($file);

        $target = (new Item())->setTitle('Duplikat-Ziel')->setStatus(ItemStatus::Review);
        $candidate = (new Item())->setTitle('Duplikat-Kandidat')->setStatus(ItemStatus::Review);
        $candidate->addImage((new ItemImage())->setPath($saved['path'])->setDimensions($saved['width'], $saved['height']));
        $em->persist($target);
        $em->persist($candidate);
        $em->flush();
        $targetId = (int) $target->getId();
        $candidateId = (int) $candidate->getId();
        $candidate->markPossibleDuplicate($target, 0.7, 'Gleiche Marke, andere Zähnung?', [
            'candidate_id' => $targetId, 'same_object' => true, 'confidence' => 0.7, 'specimen' => 'other',
            'condition' => 'same', 'photos' => 'better', 'reason' => 'Gleiche Marke, andere Zähnung?',
        ]);
        $em->flush();

        $crawler = $this->client->request('GET', '/admin/review');
        $props = json_decode((string) $crawler->filter('#review-app')->attr('data-props'), true);
        $this->client->request('GET', $props['api']);
        $queue = json_decode((string) $this->client->getResponse()->getContent(), true);
        $entry = array_values(array_filter($queue['items'], static fn (array $i): bool => $i['id'] === $candidateId))[0] ?? null;
        self::assertNotNull($entry);
        self::assertSame($targetId, $entry['duplicateOf']);

        $this->client->request('GET', $props['api'].'/'.$candidateId);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true)['item'];
        self::assertSame($targetId, $data['duplicate']['id']);
        self::assertSame('anderes Exemplar', $data['duplicate']['specimen']);
        self::assertTrue($data['duplicate']['better']);

        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $props['csrf']];
        $this->client->request('POST', $props['api'].'/'.$candidateId.'/duplicate', server: $headers, content: '{"action":"keep"}');
        self::assertResponseIsSuccessful();
        $merged = json_decode((string) $this->client->getResponse()->getContent(), true)['merged'];
        self::assertSame($targetId, $merged['id']);
        self::assertSame(2, $merged['uploadCount']);
        self::assertSame('nur gezählt', $merged['summary']);

        // Upload-Historie: Fotos des Duplikats zeigen und wieder zurücknehmen
        $crawler = $this->client->request('GET', '/admin/item/'.$targetId);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Upload-Historie', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('Gleiche Marke, andere Zähnung?', (string) $this->client->getResponse()->getContent());
        $this->client->submit($crawler->filter('form[action$="/use"]')->form());
        self::assertResponseRedirects('/admin/item/'.$targetId);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('werden jetzt gezeigt', (string) $this->client->getResponse()->getContent());
        $this->client->submit($crawler->filter('form[action$="/undo"]')->form());
        self::assertResponseRedirects('/admin/item/'.$targetId);
        $this->client->followRedirect();
        self::assertStringContainsString('Rückgängig gemacht', (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', '/admin/item?filters[multipleUploads]=1');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('/admin/item/'.$targetId.'/edit', (string) $this->client->getResponse()->getContent());
        $this->client->request('GET', '/admin');
        self::assertStringContainsString('Objekte mehrfach hochgeladen', (string) $this->client->getResponse()->getContent());
    }

    public function testBackgroundActionAnswersWithJson(): void
    {
        $this->login();
        $crawler = $this->client->request('GET', '/admin/settings/1/edit');
        $form = $crawler->filter('form[action*="/admin/settings/test-ai"]');
        self::assertCount(1, $form, 'Formular „KI-Verbindung testen“');

        $this->client->request('POST', (string) $form->attr('action'), [], [], ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_BACKGROUND_ACTION' => '1']);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('/admin/settings/1/edit', $data['redirect']);
        self::assertSame('warning', $data['messages'][0]['type'], 'AI_PROVIDER=fake im Test');
    }

    public function testImagesCanBeRotatedInReviewAndItemForm(): void
    {
        $this->login();
        $item = static::getContainer()->get(EntityManagerInterface::class)->getRepository(Item::class)->findOneBy([], ['id' => 'ASC']);
        self::assertNotNull($item);
        $itemId = (int) $item->getId();
        $image = $item->getImages()->first();
        self::assertNotFalse($image);
        $imageId = (int) $image->getId();
        [$path, $width, $height] = [(string) $image->getPath(), $image->getWidth(), $image->getHeight()];
        self::assertNotSame($width, $height, 'Demo-Bilder sind nicht quadratisch');

        // Prüfseite: sofort drehen
        $crawler = $this->client->request('GET', '/admin/review');
        $props = json_decode((string) $crawler->filter('#review-app')->attr('data-props'), true);
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $props['csrf']];
        $this->client->request('POST', $props['api'].'/'.$itemId.'/images', server: $headers, content: (string) json_encode(['rotate' => [$imageId => 90]]));
        self::assertResponseIsSuccessful();
        $images = json_decode((string) $this->client->getResponse()->getContent(), true)['item']['images'];
        $rotated = array_values(array_filter($images, static fn (array $i): bool => $i['id'] === $imageId))[0] ?? null;
        self::assertNotNull($rotated);
        self::assertStringNotContainsString($path, $rotated['full'], 'gedrehtes Bild hat eine neue Adresse');
        $image = $this->findImage($imageId);
        self::assertSame([$height, $width], [$image->getWidth(), $image->getHeight()]);

        // Objekt-Formular: Drehung im versteckten Feld, ausgeführt beim Speichern
        $crawler = $this->client->request('GET', '/admin/item/'.$itemId.'/edit');
        self::assertResponseIsSuccessful();
        $preview = (string) $image->getPath().'-800.webp';
        $entry = $crawler->filter('[data-image-entry]')->reduce(static fn (Crawler $e): bool => str_contains($e->html(), $preview));
        self::assertCount(1, $entry, 'Bild mit Vorschau und Dreh-Knöpfen im Formular');
        $field = (string) $entry->filter('input[data-image-rotate]')->attr('name');
        self::assertCount(3, $entry->filter('button[data-rotate-step]'));
        [$path, $width, $height] = [(string) $image->getPath(), $image->getWidth(), $image->getHeight()];

        $form = $crawler->filter('form[name="Item"]')->form();
        $form[$field] = '-90';
        $this->client->submit($form);
        self::assertResponseRedirects();
        $image = $this->findImage($imageId);
        self::assertNotSame($path, $image->getPath());
        self::assertSame([$height, $width], [$image->getWidth(), $image->getHeight()]);
        $mediaDir = static::getContainer()->getParameter('app.media_dir');
        self::assertIsString($mediaDir);
        self::assertFileDoesNotExist($mediaDir.'/'.$path.'-400.webp', 'alte Dateien gelöscht');
        self::assertFileExists($mediaDir.'/'.$image->getPath().'-400.webp');
    }

    public function testReviewDeletesItemWithUploadFolder(): void
    {
        $this->login();
        $importer = static::getContainer()->get(FolderImporter::class);
        $folder = '2030-03-04_120000';
        $dir = $importer->getScanDir().'/'.$folder;
        $fs = new Filesystem();
        $fs->remove($dir);
        $fs->mkdir($dir);
        $gd = imagecreatetruecolor(300, 360);
        imagefilledrectangle($gd, 0, 0, 300, 360, (int) imagecolorallocate($gd, 160, 40, 40));
        imagewebp($gd, $dir.'/01.webp');
        $item = $importer->importFolder($folder);
        $id = (int) $item->getId();
        $image = $item->getImages()->first();
        self::assertNotFalse($image);
        $path = (string) $image->getPath();
        $mediaDir = static::getContainer()->getParameter('app.media_dir');
        self::assertIsString($mediaDir);
        self::assertFileExists($mediaDir.'/'.$path.'-400.webp');

        $crawler = $this->client->request('GET', '/admin/review');
        $props = json_decode((string) $crawler->filter('#review-app')->attr('data-props'), true);
        $this->client->request('GET', $props['api'].'/'.$id);
        self::assertSame([$folder], json_decode((string) $this->client->getResponse()->getContent(), true)['item']['folders'], 'Prüfseite nennt den Ordner in der Rückfrage');

        $url = $props['api'].'/'.$id.'/delete';
        $this->client->request('POST', $url, server: ['CONTENT_TYPE' => 'application/json'], content: '{}');
        self::assertResponseStatusCodeSame(403, 'ohne CSRF-Token');
        self::assertDirectoryExists($dir);

        $this->client->request('POST', $url, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $props['csrf']], content: '{}');
        self::assertResponseIsSuccessful();
        $result = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(['deleted' => $id, 'folders' => [$folder], 'failed' => []], $result);
        self::assertDirectoryDoesNotExist($dir, 'Upload-Ordner gelöscht');
        self::assertFileDoesNotExist($mediaDir.'/'.$path.'-400.webp', 'Webbilder gelöscht');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertNull($em->find(Item::class, $id));
        $job = $em->getRepository(ScanJob::class)->findOneBy(['folder' => $folder]);
        self::assertNotNull($job, 'Scan-Protokoll behält den Ordner');
        self::assertNull($job->getItem());
        self::assertSame(sprintf('Objekt #%d gelöscht', $id), $job->getMessage());
    }

    public function testImagesCanBeCroppedInReview(): void
    {
        $this->login();
        // Upload mit Original: 400 × 300, oben links ein rotes Feld (die Marke), der Rest weiß (Albumtext)
        $importer = static::getContainer()->get(FolderImporter::class);
        $folder = '2030-03-05_120000';
        $dir = $importer->getScanDir().'/'.$folder;
        $fs = new Filesystem();
        $fs->remove($dir);
        $fs->mkdir($dir);
        $gd = imagecreatetruecolor(400, 300);
        imagefill($gd, 0, 0, (int) imagecolorallocate($gd, 255, 255, 255));
        imagefilledrectangle($gd, 0, 0, 199, 149, (int) imagecolorallocate($gd, 200, 30, 30));
        imagepng($gd, $dir.'/01.png');
        $item = $importer->importFolder($folder);
        $itemId = (int) $item->getId();
        $image = $item->getImages()->first();
        self::assertNotFalse($image);
        $imageId = (int) $image->getId();
        $mediaDir = static::getContainer()->getParameter('app.media_dir');
        self::assertIsString($mediaDir);

        $crawler = $this->client->request('GET', '/admin/review');
        $props = json_decode((string) $crawler->filter('#review-app')->attr('data-props'), true);
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $props['csrf']];
        $url = $props['api'].'/'.$itemId.'/images';
        $send = function (array $payload) use ($url, $headers): array {
            $this->client->request('POST', $url, server: $headers, content: (string) json_encode($payload));
            $images = json_decode((string) $this->client->getResponse()->getContent(), true)['item']['images'] ?? [];

            return $images[0] ?? [];
        };

        // linke obere Hälfte: nur noch die Marke
        $data = $send(['crop' => [$imageId => ['x' => 0, 'y' => 0, 'w' => 0.5, 'h' => 0.5]]]);
        self::assertResponseIsSuccessful();
        self::assertTrue($data['cropped']);
        self::assertSame([200, 150], [$data['width'], $data['height']]);
        $image = $this->findImage($imageId);
        self::assertEqualsWithDelta(['x' => 0, 'y' => 0, 'w' => 0.5, 'h' => 0.5], $image->getCrop()?->toArray(), 1e-9);
        $cropped = (string) $image->getPath();
        self::assertFileExists($mediaDir.'/'.$cropped.'-400.webp');

        // Drehen behält den Zuschnitt (neu aus dem Original erzeugt)
        $data = $send(['rotate' => [$imageId => 90]]);
        self::assertSame([150, 200], [$data['width'], $data['height']]);
        self::assertTrue($data['cropped']);
        self::assertFileDoesNotExist($mediaDir.'/'.$cropped.'-400.webp', 'alte Dateien gelöscht');

        // Ausschnitt des gedrehten Bildes: obere Hälfte = im Original die linke Hälfte der Marke
        $data = $send(['crop' => [$imageId => ['x' => 0, 'y' => 0, 'w' => 1, 'h' => 0.5]]]);
        self::assertSame([150, 100], [$data['width'], $data['height']]);
        self::assertEqualsWithDelta(['x' => 0, 'y' => 0, 'w' => 0.25, 'h' => 0.5], $this->findImage($imageId)->getCrop()?->toArray(), 1e-9);

        $send(['crop' => [$imageId => ['x' => 0.5, 'y' => 0.5, 'w' => 0.001, 'h' => 0.5]]]);
        self::assertResponseStatusCodeSame(422, 'Ausschnitt zu klein');

        // aufheben: wieder das ganze Foto, gedreht
        $data = $send(['crop' => [$imageId => null]]);
        self::assertResponseIsSuccessful();
        self::assertFalse($data['cropped']);
        self::assertSame([300, 400], [$data['width'], $data['height']]);
        self::assertNull($this->findImage($imageId)->getCrop());

        // ohne Original (Demo-Bild): die Webbilder selbst werden zugeschnitten, endgültig
        $demo = static::getContainer()->get(EntityManagerInterface::class)->getRepository(Item::class)->findOneBy([], ['id' => 'ASC']);
        self::assertNotNull($demo);
        $demoImage = $demo->getImages()->first();
        self::assertNotFalse($demoImage);
        self::assertNull($demoImage->getSourceFile());
        [$demoId, $width, $height] = [(int) $demoImage->getId(), $demoImage->getWidth(), $demoImage->getHeight()];
        $this->client->request('POST', $props['api'].'/'.$demo->getId().'/images', server: $headers, content: (string) json_encode(['crop' => [$demoId => ['x' => 0.25, 'y' => 0, 'w' => 0.5, 'h' => 1]]]));
        self::assertResponseIsSuccessful();
        $demoImage = $this->findImage($demoId);
        self::assertSame([(int) round($width / 2), $height], [$demoImage->getWidth(), $demoImage->getHeight()]);
        self::assertNull($demoImage->getCrop(), 'nichts aufzuheben');
    }

    public function testItemFormCropsBeforeRotatingOnSave(): void
    {
        $this->login();
        $importer = static::getContainer()->get(FolderImporter::class);
        $folder = '2030-03-06_120000';
        $dir = $importer->getScanDir().'/'.$folder;
        $fs = new Filesystem();
        $fs->remove($dir);
        $fs->mkdir($dir);
        $gd = imagecreatetruecolor(400, 300);
        imagefill($gd, 0, 0, (int) imagecolorallocate($gd, 255, 255, 255));
        imagepng($gd, $dir.'/01.png');
        imagepng($gd, $dir.'/02.png');
        $item = $importer->importFolder($folder);
        $itemId = (int) $item->getId();
        $image = $item->getImages()->first();
        self::assertNotFalse($image);
        $imageId = (int) $image->getId();

        // Akkordeon: erstes Bild offen, das zweite zu; im Kopf Nummer und Größe
        $crawler = $this->client->request('GET', '/admin/item/'.$itemId.'/edit');
        self::assertResponseIsSuccessful();
        $entries = $crawler->filter('.item-image-item');
        self::assertCount(2, $entries);
        self::assertCount(1, $entries->eq(0)->filter('.accordion-collapse.show'), 'erstes Bild aufgeklappt');
        self::assertCount(0, $entries->eq(1)->filter('.accordion-collapse.show'));
        self::assertStringContainsString('Bild 1', $entries->eq(0)->filter('.item-image-head__title')->text());
        self::assertStringContainsString('400 × 300 px', $entries->eq(0)->filter('.item-image-head__meta')->text());
        $entry = $entries->eq(0)->filter('[data-image-entry]');
        self::assertSame('0', $entry->attr('data-cropped'));
        self::assertCount(1, $entry->filter('button[data-crop-start]'));
        $rotate = (string) $entry->filter('input[data-image-rotate]')->attr('name');
        $crop = (string) $entry->filter('input[data-image-crop]')->attr('name');

        // Zuschnitt bezieht sich auf das Bild vor der Drehung: 200 × 150, dann gedreht 150 × 200
        $form = $crawler->filter('form[name="Item"]')->form();
        $form[$rotate] = '90';
        $form[$crop] = (string) json_encode(['x' => 0, 'y' => 0, 'w' => 0.5, 'h' => 0.5]);
        $this->client->submit($form);
        self::assertResponseRedirects();
        $image = $this->findImage($imageId);
        self::assertSame([150, 200], [$image->getWidth(), $image->getHeight()]);
        self::assertSame(90, $image->getRotation());
        self::assertEqualsWithDelta(['x' => 0, 'y' => 0, 'w' => 0.5, 'h' => 0.5], $image->getCrop()?->toArray(), 1e-9);

        // aufheben: wieder das ganze Foto, gedreht; ein ungültiger Ausschnitt ändert nichts
        $crawler = $this->client->request('GET', '/admin/item/'.$itemId.'/edit');
        self::assertSame('1', $crawler->filter('[data-image-entry]')->eq(0)->attr('data-cropped'));
        self::assertStringContainsString('zugeschnitten', $crawler->filter('.item-image-head__meta')->eq(0)->text());
        $form = $crawler->filter('form[name="Item"]')->form();
        $form[$crop] = 'reset';
        $this->client->submit($form);
        $image = $this->findImage($imageId);
        self::assertSame([300, 400], [$image->getWidth(), $image->getHeight()]);
        self::assertNull($image->getCrop());

        $crawler = $this->client->request('GET', '/admin/item/'.$itemId.'/edit');
        $form = $crawler->filter('form[name="Item"]')->form();
        $form[$crop] = '{"x":0.5}';
        $this->client->submit($form);
        self::assertResponseRedirects();
        self::assertSame([300, 400], [$this->findImage($imageId)->getWidth(), $this->findImage($imageId)->getHeight()]);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Ein Bild wurde nicht zugeschnitten', $crawler->filter('#flash-toasts')->text());
    }

    public function testAiHintIsSavedAndSentWithRecognition(): void
    {
        $this->login();
        $item = static::getContainer()->get(EntityManagerInterface::class)->getRepository(Item::class)->findOneBy([], ['id' => 'ASC']);
        self::assertNotNull($item);
        $itemId = (int) $item->getId();

        $crawler = $this->client->request('GET', '/admin/review');
        $props = json_decode((string) $crawler->filter('#review-app')->attr('data-props'), true);
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $props['csrf']];

        // mit der Erkennung geschickt: vorher gespeichert, gilt also schon für diese Erkennung
        $this->client->request('POST', $props['api'].'/'.$itemId.'/recognize', server: $headers, content: (string) json_encode(['aiHint' => "  DDR 1953, 24 Pf Karl Marx\n"]));
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('DDR 1953, 24 Pf Karl Marx', $data['item']['aiHint'] ?? null);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $item = $em->find(Item::class, $itemId);
        self::assertNotNull($item);
        $request = static::getContainer()->get(PromptBuilder::class)->build($item);
        self::assertStringContainsString('Hinweis des Sammlers zu diesem Objekt.', $request->text);
        self::assertStringContainsString('DDR 1953, 24 Pf Karl Marx', $request->text);
        self::assertStringNotContainsString('DDR 1953', $request->instructions, 'der feste Prompt bleibt gleich (Caching)');

        // ohne aiHint gilt der gespeicherte Hinweis; leer gespeichert ist er weg
        $this->client->request('POST', $props['api'].'/'.$itemId.'/recognize', server: $headers, content: '{}');
        self::assertSame('DDR 1953, 24 Pf Karl Marx', json_decode((string) $this->client->getResponse()->getContent(), true)['item']['aiHint']);
        $this->client->request('POST', $props['api'].'/'.$itemId, server: $headers, content: (string) json_encode(['aiHint' => '  ', 'action' => 'save']));
        self::assertResponseIsSuccessful();
        self::assertNull(json_decode((string) $this->client->getResponse()->getContent(), true)['item']['aiHint']);
        $em->clear();
        $item = $em->find(Item::class, $itemId);
        self::assertNotNull($item);
        self::assertStringNotContainsString('Hinweis des Sammlers', static::getContainer()->get(PromptBuilder::class)->build($item)->text);
    }

    public function testSeveralModelsAreComparedAndOneCanBeApplied(): void
    {
        $this->login();
        $this->updateSettings(static fn (Settings $s) => $s->setAiRecognizers([
            ['provider' => AiProvider::GEMINI, 'active' => true],
            ['provider' => AiProvider::OPENAI, 'model' => 'gpt-test', 'active' => false],
            ['provider' => AiProvider::ANTHROPIC, 'model' => 'claude-test', 'active' => true, 'priceInput' => 2, 'priceOutput' => 10],
        ]));

        try {
            // dasselbe Objekt wie testAiHintIsSavedAndSentWithRecognition(), damit kein weiteres die Website verlässt
            $item = static::getContainer()->get(EntityManagerInterface::class)->getRepository(Item::class)->findOneBy([], ['id' => 'ASC']);
            self::assertNotNull($item);
            $itemId = (int) $item->getId();
            $crawler = $this->client->request('GET', '/admin/review');
            $props = json_decode((string) $crawler->filter('#review-app')->attr('data-props'), true);
            $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $props['csrf']];

            $this->client->request('POST', $props['api'].'/'.$itemId.'/recognize', server: $headers, content: '{}');
            self::assertResponseIsSuccessful();
            $data = json_decode((string) $this->client->getResponse()->getContent(), true);
            self::assertTrue($data['ok']);
            $results = $data['item']['recognitions']['results'];
            self::assertSame([AiProvider::GEMINI, AiProvider::ANTHROPIC], array_column($results, 'provider'), 'nur aktive Zeilen, in ihrer Reihenfolge');
            self::assertSame([true, false], array_column($results, 'primary'));
            self::assertSame([true, false], array_column($results, 'applied'), 'die Hauptzeile steht im Formular');
            self::assertSame('claude-test', $results[1]['model']);
            self::assertSame(0.42, $data['item']['ai']['confidence']);
            self::assertSame(0.59, $results[1]['confidence']);
            self::assertNotSame($results[0]['values']['year'], $results[1]['values']['year'], 'die Fake-Ergebnisse unterscheiden sich');
            self::assertContains('title', array_column($data['item']['recognitions']['rows'], 'key'));
            // Kosten aus den Preisen der Zeile; der Fake meldet 1200 + 258 je Foto als Eingabe und 450 als Ausgabe (zweite Zeile)
            $input = 1200 + 258 * min(4, \count($data['item']['images']));
            self::assertSame([$input, 450], [$results[1]['inputTokens'], $results[1]['outputTokens']]);
            self::assertEqualsWithDelta(($input * 2 + 450 * 10) / 1_000_000, $results[1]['cost'], 1e-9);
            self::assertNull($results[0]['cost'], 'ohne Preise keine Kosten');

            // den Vorschlag des zweiten Modells übernehmen
            $this->client->request('POST', $props['api'].'/'.$itemId.'/recognition/'.$results[1]['id'], server: $headers, content: '{}');
            self::assertResponseIsSuccessful();
            $applied = json_decode((string) $this->client->getResponse()->getContent(), true)['item'];
            self::assertSame([false, true], array_column($applied['recognitions']['results'], 'applied'));
            self::assertSame(0.59, $applied['ai']['confidence']);
            self::assertSame($results[1]['values']['year'], (string) $applied['year']);
            self::assertSame($results[1]['values']['title'], $applied['title']);
            self::assertSame('review', $applied['status'], 'Übernehmen veröffentlicht nie automatisch');

            $this->client->request('POST', $props['api'].'/'.$itemId.'/recognition/999999', server: $headers, content: '{}');
            self::assertResponseStatusCodeSame(404);
        } finally {
            $this->updateSettings(static fn (Settings $s) => $s->setAiRecognizers(null));
        }
    }

    public function testSettingsStoreModelRowsAndKeysPerProvider(): void
    {
        $this->login();
        $crawler = $this->client->request('GET', '/admin/settings/1/edit');
        self::assertResponseIsSuccessful();
        foreach (AiProvider::CHOICES as $provider) {
            self::assertCount(1, $crawler->filter(sprintf('input[name="Settings[newApiKeys][%s][key]"]', $provider)), 'ein Key-Feld je Anbieter');
        }
        $form = $crawler->filter('form[name="Settings"]')->form();
        $submit = function (array $rows, array $keys = []) use ($form): Crawler {
            $values = $form->getPhpValues();
            $values['Settings']['aiRecognizers'] = $rows;
            $values['Settings']['newApiKeys'] = $keys;

            return $this->client->request('POST', $form->getUri(), $values);
        };

        try {
            $submit([
                ['active' => '1', 'provider' => AiProvider::ANTHROPIC, 'model' => ' claude-test ', 'priceInput' => '2', 'priceOutput' => '10,5'],
                ['provider' => AiProvider::MISTRAL, 'model' => ''],
            ], [AiProvider::ANTHROPIC => ['key' => 'ant-geheim-1234']]);
            self::assertResponseRedirects();

            $settings = $this->freshSettings();
            $rows = $settings->recognizerConfigs();
            self::assertCount(2, $rows);
            self::assertSame([AiProvider::ANTHROPIC, 'claude-test', true, 2.0, 10.5], [$rows[0]->provider, $rows[0]->model, $rows[0]->active, $rows[0]->priceInput, $rows[0]->priceOutput]);
            self::assertSame([AiProvider::MISTRAL, null, false], [$rows[1]->provider, $rows[1]->model, $rows[1]->active]);
            self::assertNotNull($settings->getApiKey(AiProvider::ANTHROPIC));
            self::assertStringNotContainsString('ant-geheim', (string) $settings->getApiKey(AiProvider::ANTHROPIC), 'verschlüsselt gespeichert');

            $crawler = $this->client->request('GET', '/admin/settings/1/edit');
            self::assertStringContainsString('Hinterlegt, endet auf …1234', $crawler->filter('form[name="Settings"]')->text());
            self::assertStringContainsString('Hauptzeile', $crawler->filter('.ai-recognizer-item')->first()->text());
            self::assertCount(1, $crawler->filter('input[name="Settings[newApiKeys][anthropic][remove]"]'));

            // ohne aktive Zeile gäbe es keine Hauptzeile
            $crawler = $submit([['provider' => AiProvider::GEMINI]]);
            self::assertFalse($this->client->getResponse()->isRedirect());
            self::assertStringContainsString('Mindestens ein Modell muss aktiv sein', $crawler->text());

            // Key entfernen
            $submit([['active' => '1', 'provider' => AiProvider::ANTHROPIC]], [AiProvider::ANTHROPIC => ['key' => '', 'remove' => '1']]);
            self::assertResponseRedirects();
            self::assertNull($this->freshSettings()->getApiKey(AiProvider::ANTHROPIC));
        } finally {
            $this->updateSettings(static fn (Settings $s) => $s->setAiRecognizers(null)->setApiKey(AiProvider::ANTHROPIC, null));
        }
    }

    /** @param callable(Settings): mixed $change */
    private function updateSettings(callable $change): void
    {
        $change($this->freshSettings());
        static::getContainer()->get(EntityManagerInterface::class)->flush();
    }

    private function freshSettings(): Settings
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $settings = $em->getRepository(Settings::class)->findOneBy([]);
        self::assertNotNull($settings);

        return $settings;
    }

    private function findImage(int $id): ItemImage
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $image = $em->find(ItemImage::class, $id);
        self::assertNotNull($image);

        return $image;
    }

    private function login(): void
    {
        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'admin@example.com']);
        self::assertNotNull($user);
        $this->client->loginUser($user);
    }
}
