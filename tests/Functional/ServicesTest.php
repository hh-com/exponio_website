<?php

namespace App\Tests\Functional;

use App\Ai\DefaultPrompts;
use App\Media\MediaStorage;
use App\Recognition\PromptBuilder;
use App\Service\SettingsProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Dienste mit echtem Container: Prompt aus den Einstellungen, Bildablage ohne Metadaten.
 */
final class ServicesTest extends KernelTestCase
{
    public function testRecognitionPromptPlaceholdersAndEditablePrompt(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $prompts = $container->get(PromptBuilder::class);
        $settings = $container->get(SettingsProvider::class)->get();

        $default = $prompts->preview();
        self::assertStringNotContainsString('{{objektarten}}', $default);
        self::assertStringContainsString('michel (Michel-Nr.', $default);
        self::assertStringContainsString('Deutschland', $default);

        // eigener Prompt ohne Platzhalter: die Objektarten werden automatisch angehängt
        $settings->setRecognitionPromptText('Bestimme das Objekt.');
        self::assertStringStartsWith('Bestimme das Objekt.', $prompts->preview());
        self::assertStringContainsString('michel (Michel-Nr.', $prompts->preview());

        // unveränderter Standardtext wird nicht gespeichert
        $settings->setRecognitionPromptText(DefaultPrompts::RECOGNITION);
        self::assertNull($settings->getAiRecognitionPrompt());
        $container->get(EntityManagerInterface::class)->clear();
    }

    public function testStoredImagesHaveNoMetadata(): void
    {
        self::bootKernel();
        $media = static::getContainer()->get(MediaStorage::class);
        $source = sys_get_temp_dir().'/exif-test-'.bin2hex(random_bytes(3)).'.jpg';
        $image = imagecreatetruecolor(400, 300);
        imagefilledrectangle($image, 0, 0, 400, 300, (int) imagecolorallocate($image, 200, 80, 60));
        ob_start();
        imagejpeg($image);
        $jpeg = (string) ob_get_clean();
        // APP1-Segment mit EXIF-Kennung und GPS-Text direkt nach dem SOI-Marker einfügen
        $exif = "Exif\0\0II*\0GPSLatitude=52.52";
        file_put_contents($source, substr($jpeg, 0, 2)."\xFF\xE1".pack('n', \strlen($exif) + 2).$exif.substr($jpeg, 2));

        $saved = $media->store($source, 'tests/exif');
        foreach ([null, 800, 400] as $size) {
            $content = (string) file_get_contents($media->absolutePath($saved['path'], $size));
            self::assertStringNotContainsString('Exif', $content);
            self::assertStringNotContainsString('EXIF', $content);
            self::assertStringNotContainsString('GPS', $content);
        }
        self::assertSame($saved['path'], $media->store($source, 'tests/exif')['path'], 'gleiches Original → gleicher Dateiname');
        $media->delete($saved['path']);
        unlink($source);
    }
}
