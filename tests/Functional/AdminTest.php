<?php

namespace App\Tests\Functional;

use App\Entity\Item;
use App\Entity\ItemImage;
use App\Enum\ImageSide;
use App\Enum\ItemStatus;
use App\Media\MediaStorage;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

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

    private function login(): void
    {
        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'admin@example.com']);
        self::assertNotNull($user);
        $this->client->loginUser($user);
    }
}
