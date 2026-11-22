<?php

namespace App\Tests\Functional;

use App\Ai\DefaultPrompts;
use App\Entity\ItemImage;
use App\Import\FolderImporter;
use App\Media\ImageRotator;
use App\Media\MediaStorage;
use App\Recognition\PromptBuilder;
use App\Service\SettingsProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Dienste mit echtem Container: Prompt aus den Einstellungen, Bildablage ohne Metadaten, Bilder drehen.
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

    public function testImagesAreRotatedClockwiseAndKeepTheirRotation(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $media = $container->get(MediaStorage::class);
        $rotator = $container->get(ImageRotator::class);
        $fs = new Filesystem();
        $dir = $container->get(FolderImporter::class)->getScanDir().'/rotate-test';
        $fs->mkdir($dir);
        // 400 × 300, blau mit roter Ecke oben links
        $source = imagecreatetruecolor(400, 300);
        imagefilledrectangle($source, 0, 0, 399, 299, (int) imagecolorallocate($source, 30, 60, 200));
        imagefilledrectangle($source, 0, 0, 99, 99, (int) imagecolorallocate($source, 220, 30, 30));
        imagepng($source, $dir.'/01.png');

        $plain = $media->store($dir.'/01.png', 'tests/rotate/01');
        $image = (new ItemImage())->setPath($plain['path'])->setDimensions($plain['width'], $plain['height'])->setSourceFile('rotate-test/01.png');

        // mit Original: aus dem Original neu erzeugt, Drehung gemerkt
        $rotator->rotate($image, 90);
        self::assertSame([300, 400], [$image->getWidth(), $image->getHeight()]);
        self::assertSame(90, $image->getRotation());
        self::assertNotSame($plain['path'], $image->getPath(), 'gedreht → neue Adresse (Browser-Cache)');
        self::assertFileDoesNotExist($media->absolutePath($plain['path'], 400), 'alte Dateien gelöscht');
        self::assertRedAt($media->absolutePath((string) $image->getPath()), 270, 30, 'nach Rechtsdrehung liegt die rote Ecke oben rechts');
        self::assertSame($image->getPath(), $media->store($dir.'/01.png', MediaStorage::stripContentHash((string) $image->getPath()), $image->getRotation())['path'], 'app:media:rebuild ergibt denselben Namen');

        $rotator->rotate($image, -90);
        self::assertSame(0, $image->getRotation());
        self::assertSame($plain['path'], $image->getPath(), 'zurückgedreht → wieder der ursprüngliche Name');
        self::assertSame([400, 300], [$image->getWidth(), $image->getHeight()]);

        // ohne Original (im Backend hochgeladen): die Webvariante selbst wird gedreht
        $image->setSourceFile(null);
        $rotator->rotate($image, 180);
        self::assertSame(0, $image->getRotation());
        self::assertSame([400, 300], [$image->getWidth(), $image->getHeight()]);
        self::assertRedAt($media->absolutePath((string) $image->getPath()), 370, 270, 'nach 180° liegt die rote Ecke unten rechts');

        $media->delete((string) $image->getPath());
        $fs->remove($dir);
        self::assertSame(270, MediaStorage::normalizeRotation(-90));
        self::assertSame(90, MediaStorage::normalizeRotation(450));
    }

    private static function assertRedAt(string $file, int $x, int $y, string $message): void
    {
        $image = imagecreatefromwebp($file);
        self::assertNotFalse($image, $file);
        $rgb = imagecolorat($image, $x, $y);
        self::assertIsInt($rgb);
        $red = ($rgb >> 16) & 0xFF;
        $blue = $rgb & 0xFF;
        self::assertTrue($red > 150 && $blue < 100, sprintf('%s (Rot %d, Blau %d)', $message, $red, $blue));
    }
}
