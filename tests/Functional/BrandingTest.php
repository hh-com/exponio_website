<?php

namespace App\Tests\Functional;

use App\Entity\Settings;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\FileFormField;

/**
 * Name und Logo der Website aus dem Backend: Upload, erzeugte Dateien, Verwendung auf Website,
 * im Backend und im Web-App-Manifest, Zurücksetzen auf das Standardlogo.
 */
final class BrandingTest extends WebTestCase
{
    private KernelBrowser $client;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function testStandardLogoAndManifest(): void
    {
        $this->client->request('GET', '/site.webmanifest');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/manifest+json');
        $manifest = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($manifest);
        self::assertSame($this->settings()->getSiteName(), $manifest['name']);
        self::assertSame('/brand/icon-192.png', $manifest['icons'][0]['src']);
        self::assertSame('/brand/icon-512.png', $manifest['icons'][1]['src']);

        $this->client->request('GET', '/de/');
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('src="/brand/logo.webp"', $html);
        self::assertStringContainsString('href="/brand/favicon-32.png"', $html);
        self::assertStringContainsString('href="/site.webmanifest"', $html);
        self::assertStringContainsString('/brand/og-default.jpg', $html);
    }

    public function testUploadedLogoReplacesAllBrandFilesAndCanBeReset(): void
    {
        $this->login();
        $settings = $this->settings();
        $originalName = $settings->getSiteName();
        self::assertNull($settings->getLogoPath());

        try {
            // nicht quadratisch: wird mittig quadratisch zugeschnitten
            $this->saveSettings($this->png(400, 300), 'Exponio Testname');
            self::assertResponseRedirects();
            $this->client->followRedirect();
            self::assertSelectorTextContains('.alert-success', 'Das neue Logo ist online');

            $base = $this->settings()->getLogoPath();
            self::assertNotNull($base);
            self::assertMatchesRegularExpression('#^brand/logo-[0-9a-f]{8}$#', $base);
            foreach (['.webp' => [256, 256], '-32.png' => [32, 32], '-180.png' => [180, 180], '-192.png' => [192, 192], '-512.png' => [512, 512], '-og.jpg' => [1200, 630]] as $suffix => [$width, $height]) {
                $file = $this->mediaDir().'/'.$base.$suffix;
                self::assertFileExists($file);
                $size = getimagesize($file);
                self::assertIsArray($size, $suffix);
                self::assertSame([$width, $height], [$size[0], $size[1]], $suffix);
            }

            // Website, Backend und Manifest verwenden Logo und Namen aus den Einstellungen
            $this->client->request('GET', '/de/');
            $html = (string) $this->client->getResponse()->getContent();
            self::assertStringContainsString('src="/media/'.$base.'.webp"', $html);
            self::assertStringContainsString('href="/media/'.$base.'-32.png"', $html);
            self::assertStringContainsString('href="/media/'.$base.'-180.png"', $html);
            self::assertStringContainsString('/media/'.$base.'-og.jpg', $html);
            self::assertStringContainsString('Exponio Testname', $html);
            self::assertStringNotContainsString('/brand/logo.webp', $html);

            $this->client->request('GET', '/admin');
            self::assertStringContainsString('/media/'.$base.'.webp', (string) $this->client->getResponse()->getContent());

            $this->client->request('GET', '/site.webmanifest');
            $manifest = json_decode((string) $this->client->getResponse()->getContent(), true);
            self::assertIsArray($manifest);
            self::assertSame('Exponio Testname', $manifest['name']);
            self::assertSame('/media/'.$base.'-192.png', $manifest['icons'][0]['src']);

            // ein anderes Logo ersetzt das erste und räumt dessen Dateien weg
            $this->saveSettings($this->png(300, 300, [0x41, 0x8C, 0xC2]));
            self::assertResponseRedirects();
            $second = $this->settings()->getLogoPath();
            self::assertNotNull($second);
            self::assertNotSame($base, $second);
            self::assertFileDoesNotExist($this->mediaDir().'/'.$base.'.webp');
            self::assertFileExists($this->mediaDir().'/'.$second.'.webp');

            // „Standardlogo verwenden“
            $crawler = $this->client->request('GET', $this->editUrl());
            $this->client->submit($crawler->filter('form[action*="reset-logo"]')->form());
            self::assertResponseRedirects();
            self::assertNull($this->settings()->getLogoPath());
            self::assertFileDoesNotExist($this->mediaDir().'/'.$second.'.webp');
            $this->client->request('GET', '/de/');
            self::assertStringContainsString('src="/brand/logo.webp"', (string) $this->client->getResponse()->getContent());
        } finally {
            $this->restore($originalName);
        }
    }

    public function testTooSmallLogoIsRejected(): void
    {
        $this->login();
        $this->saveSettings($this->png(Settings::LOGO_MIN_EDGE - 56, Settings::LOGO_MIN_EDGE - 56));

        self::assertResponseStatusCodeSame(422);
        // Meldung des Validators nennt die Mindestbreite
        self::assertStringContainsString(Settings::LOGO_MIN_EDGE.'px', (string) $this->client->getResponse()->getContent());
        self::assertNull($this->settings()->getLogoPath());
    }

    /** Schickt das Einstellungsformular ab wie „Speichern und weiter bearbeiten“ im Backend */
    private function saveSettings(string $logoFile, ?string $siteName = null): void
    {
        $crawler = $this->client->request('GET', $this->editUrl());
        $form = $crawler->filter('form[name="Settings"]')->form();
        $field = $form['Settings[newLogo]'];
        self::assertInstanceOf(FileFormField::class, $field);
        $field->upload($logoFile);
        if (null !== $siteName) {
            $form['Settings[siteName]'] = $siteName;
        }
        $this->client->request(
            'POST',
            $form->getUri(),
            $form->getPhpValues() + ['ea' => ['newForm' => ['btn' => Action::SAVE_AND_CONTINUE]]],
            $form->getPhpFiles(),
        );
    }

    /** @param array{int<0, 255>, int<0, 255>, int<0, 255>} $color */
    private function png(int $width, int $height, array $color = [0xE2, 0x47, 0x6F]): string
    {
        $image = imagecreatetruecolor(max(1, $width), max(1, $height));
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledellipse($image, intdiv($width, 2), intdiv($height, 2), intdiv($height, 2), intdiv($height, 2), (int) imagecolorallocate($image, ...$color));
        $file = sys_get_temp_dir().'/logo-'.bin2hex(random_bytes(4)).'.png';
        imagepng($image, $file);
        $this->tempFiles[] = $file;

        return $file;
    }

    private function restore(string $siteName): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $settings = $this->settings();
        $settings->setSiteName($siteName)->setLogoPath(null);
        $em->flush();
    }

    private function settings(): Settings
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $settings = $em->getRepository(Settings::class)->findOneBy([], ['id' => 'ASC']);
        self::assertNotNull($settings);

        return $settings;
    }

    private function editUrl(): string
    {
        return '/admin/settings/'.$this->settings()->getId().'/edit';
    }

    private function mediaDir(): string
    {
        $dir = static::getContainer()->getParameter('app.media_dir');
        self::assertIsString($dir);

        return $dir;
    }

    private function login(): void
    {
        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'admin@example.com']);
        self::assertNotNull($user);
        $this->client->loginUser($user);
    }
}
