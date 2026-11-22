<?php

namespace App\Tests\Unit;

use App\Media\CropBox;
use App\Media\MediaStorage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Zuschneiden ohne Datenbank: Umrechnung des angezeigten (gedrehten) Ausschnitts auf das Original,
 * und MediaStorage schneidet vor dem Drehen zu.
 */
final class CropBoxTest extends TestCase
{
    /** @return iterable<string, array{int}> */
    public static function rotations(): iterable
    {
        foreach ([0, 90, 180, 270] as $degrees) {
            yield $degrees.'°' => [$degrees];
        }
    }

    /** Was im gedrehten Bild im Ausschnitt liegt, liegt im Original in unrotate() – geprüft mit der Drehung von GD */
    #[DataProvider('rotations')]
    public function testUnrotateMatchesTheRotatedImage(int $degrees): void
    {
        $shown = CropBox::fromArray(['x' => 0.1, 'y' => 0.2, 'w' => 0.3, 'h' => 0.5]);
        $inOriginal = $shown->unrotate($degrees);

        $original = self::canvas(400, 200);
        self::paint($original, $inOriginal, 255, 0, 0);
        $rotated = imagerotate($original, -$degrees, 0);
        self::assertInstanceOf(\GdImage::class, $rotated);
        [$w, $h] = [imagesx($rotated), imagesy($rotated)];

        self::assertSame([255, 0, 0], self::rgb($rotated, (int) (($shown->x + $shown->w / 2) * $w), (int) (($shown->y + $shown->h / 2) * $h)), 'Mitte des Ausschnitts');
        self::assertSame([255, 0, 0], self::rgb($rotated, (int) (($shown->x + 0.02) * $w), (int) (($shown->y + 0.02) * $h)), 'Ecke oben links');
        self::assertSame([255, 0, 0], self::rgb($rotated, (int) (($shown->x + $shown->w - 0.02) * $w), (int) (($shown->y + $shown->h - 0.02) * $h)), 'Ecke unten rechts');
        self::assertSame([255, 255, 255], self::rgb($rotated, (int) (($shown->x + $shown->w + 0.05) * $w), (int) (($shown->y + $shown->h / 2) * $h)), 'rechts daneben');
    }

    public function testBoxesAreComposedClampedAndChecked(): void
    {
        $outer = CropBox::fromArray(['x' => 0.2, 'y' => 0.1, 'w' => 0.5, 'h' => 0.8]);
        $inner = CropBox::fromArray(['x' => 0.5, 'y' => 0.25, 'w' => 0.5, 'h' => 0.5]);
        self::assertEqualsWithDelta(['x' => 0.45, 'y' => 0.3, 'w' => 0.25, 'h' => 0.4], $outer->compose($inner)->toArray(), 1e-9);

        self::assertSame(['x' => 0.5, 'y' => 0.0, 'w' => 0.5, 'h' => 1.0], CropBox::fromArray(['x' => 0.5, 'y' => -0.2, 'w' => 0.9, 'h' => 2])->toArray(), 'auf das Bild begrenzt');
        self::assertTrue(CropBox::fromArray(['x' => 0, 'y' => 0, 'w' => 1, 'h' => 1])->isFull());
        self::assertSame(['x' => 10, 'y' => 20, 'width' => 50, 'height' => 40], CropBox::fromArray(['x' => 0.1, 'y' => 0.2, 'w' => 0.5, 'h' => 0.4])->pixels(100, 100));

        $this->expectException(\InvalidArgumentException::class);
        CropBox::fromArray(['x' => 0.5, 'y' => 0.5, 'w' => 0.01, 'h' => 0.5]);
    }

    public function testStorageCropsBeforeRotating(): void
    {
        $dir = sys_get_temp_dir().'/exponio-crop-'.bin2hex(random_bytes(4));
        $fs = new Filesystem();
        try {
            $fs->mkdir($dir);
            // 400 × 200, rechts unten ein rotes Feld von 100 × 60 Pixeln
            $original = self::canvas(400, 200);
            $crop = CropBox::fromArray(['x' => 0.7, 'y' => 0.6, 'w' => 0.25, 'h' => 0.3]);
            self::paint($original, $crop, 255, 0, 0);
            imagepng($original, $dir.'/original.png');
            $media = new MediaStorage($dir.'/media');

            $plain = $media->store($dir.'/original.png', 'items/x/01');
            $cropped = $media->store($dir.'/original.png', 'items/x/01', 90, $crop);
            self::assertSame([400, 200], [$plain['width'], $plain['height']]);
            self::assertSame([60, 100], [$cropped['width'], $cropped['height']], 'erst zugeschnitten, dann gedreht');
            self::assertNotSame($plain['path'], $cropped['path'], 'eigene Adresse');
            self::assertSame($cropped['path'], $media->store($dir.'/original.png', 'items/x/01', 90, $crop)['path'], 'gleiche Angaben, gleicher Name (app:media:rebuild)');

            $large = imagecreatefromwebp($media->absolutePath($cropped['path']));
            self::assertInstanceOf(\GdImage::class, $large);
            [$r, $g, $b] = self::rgb($large, 30, 50);
            self::assertGreaterThan(200, $r);
            self::assertLessThan(60, $g + $b, 'nur das rote Feld ist übrig');
        } finally {
            $fs->remove($dir);
        }
    }

    /**
     * @param positive-int $width
     * @param positive-int $height
     */
    private static function canvas(int $width, int $height): \GdImage
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 255, 255, 255));

        return $image;
    }

    /**
     * @param int<0, 255> $r
     * @param int<0, 255> $g
     * @param int<0, 255> $b
     */
    private static function paint(\GdImage $image, CropBox $box, int $r, int $g, int $b): void
    {
        $px = $box->pixels(imagesx($image), imagesy($image));
        imagefilledrectangle($image, $px['x'], $px['y'], $px['x'] + $px['width'] - 1, $px['y'] + $px['height'] - 1, (int) imagecolorallocate($image, $r, $g, $b));
    }

    /** @return array{int, int, int} */
    private static function rgb(\GdImage $image, int $x, int $y): array
    {
        $c = (int) imagecolorat($image, $x, $y);

        return [($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF];
    }
}
