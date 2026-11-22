<?php

namespace App\Tests\Unit;

use App\Duplicate\CandidateFinder;
use App\Duplicate\ComparisonMatch;
use App\Enum\Specimen;
use App\Media\ImageHasher;
use PHPUnit\Framework\TestCase;

/**
 * Duplikat-Erkennung ohne Datenbank: Wahrnehmungs-Hash, Vergleich von Katalognummern und Nennwerten,
 * Regel „Erhaltung zuerst, dann Fotoqualität“.
 */
final class DuplicateRulesTest extends TestCase
{
    public function testSimilarPhotosHaveASmallHashDistance(): void
    {
        $hasher = new ImageHasher();
        $photo = self::bands([10, 200, 30, 220, 50, 240, 70, 250, 90]);
        $hash = $hasher->hash($photo);
        self::assertNotNull($hash);
        self::assertTrue(ImageHasher::isValid($hash));
        self::assertSame('5555555555555555', $hash, 'jedes Feld heller als sein rechter Nachbar = Bit gesetzt');

        $smaller = imagescale($photo, 225, 160);
        self::assertInstanceOf(\GdImage::class, $smaller);
        self::assertLessThanOrEqual(CandidateFinder::HASH_STRONG, ImageHasher::distance($hash, (string) $hasher->hash($smaller)), 'Größe ändert den Hash kaum');

        $other = self::bands([200, 10, 220, 30, 240, 50, 250, 70, 255]);
        self::assertSame(64, ImageHasher::distance($hash, (string) $hasher->hash($other)));
        self::assertSame(64, ImageHasher::distance($hash, 'kein-hash'));
    }

    public function testFlatImageHasNoHash(): void
    {
        $flat = imagecreatetruecolor(300, 200);
        imagefilledrectangle($flat, 0, 0, 299, 199, (int) imagecolorallocate($flat, 40, 90, 160));

        self::assertNull((new ImageHasher())->hash($flat));
    }

    public function testRotatedPhotoIsRecognized(): void
    {
        $hasher = new ImageHasher();
        $photo = self::bands([20, 40, 60, 80, 100, 120, 140, 160, 180]);
        $hash = (string) $hasher->hash($photo);
        self::assertSame('0000000000000000', $hash);

        $upsideDown = imagerotate($photo, 180, 0);
        self::assertInstanceOf(\GdImage::class, $upsideDown);
        self::assertSame(64, ImageHasher::distance($hash, (string) $hasher->hash($upsideDown)), 'auf dem Kopf: alle Bits umgekehrt');
        $distances = array_map(static fn (string $rotated): int => ImageHasher::distance($hash, $rotated), $hasher->rotatedHashes($upsideDown));
        self::assertContains(0, $distances, 'eine der Drehungen passt genau');
    }

    public function testCatalogNumbersAndFaceValuesAreNormalized(): void
    {
        self::assertSame(['213a'], CandidateFinder::normalizeCatalog('Mi.-Nr. 213 A'));
        self::assertSame(['45'], CandidateFinder::normalizeCatalog('KM# 45'));
        self::assertSame(['snus123', 'ytfr45'], CandidateFinder::normalizeCatalog('Sn:US 123; Yt:FR 45'));
        self::assertSame(['10'], CandidateFinder::normalizeValue('10 Pf'));
        self::assertSame(['10'], CandidateFinder::normalizeValue('10 Pfennig'));
        self::assertSame(['1/2'], CandidateFinder::normalizeValue('1/2 Groschen'));
        self::assertSame(['2.5'], CandidateFinder::normalizeValue('2,50 DM'));
    }

    public function testConditionComesBeforePhotoQuality(): void
    {
        $match = static fn (string $condition, string $photos, Specimen $specimen = Specimen::Same): ComparisonMatch => new ComparisonMatch(1, true, 0.9, $specimen, $condition, $photos, '');

        self::assertTrue($match('better', 'worse')->isBetter(), 'bessere Erhaltung gewinnt auch mit schlechteren Fotos');
        self::assertFalse($match('worse', 'better')->isBetter(), 'schlechtere Erhaltung verliert auch mit schöneren Fotos');
        self::assertTrue($match('same', 'better')->isBetter(), 'gleiche Erhaltung: die Fotos entscheiden');
        self::assertTrue($match('unknown', 'better')->isBetter());
        self::assertFalse($match('same', 'same')->isBetter());
        self::assertFalse($match('same', 'better')->conditionChanged(), 'nur neu fotografiert: Mängel bleiben');
        self::assertTrue($match('better', 'same')->conditionChanged());
        self::assertTrue($match('same', 'better', Specimen::Other)->conditionChanged(), 'anderes Exemplar: Mängel wechseln mit den Fotos');

        $parsed = ComparisonMatch::fromArray(['candidate_id' => '7', 'same_object' => true, 'confidence' => 1.4, 'specimen' => 'other', 'condition' => 'prima', 'photos' => 'better', 'reason' => ' schärfer ']);
        self::assertNotNull($parsed);
        self::assertSame(7, $parsed->candidateId);
        self::assertSame(1.0, $parsed->confidence, 'Sicherheit wird auf 0–1 begrenzt');
        self::assertSame(Specimen::Other, $parsed->specimen);
        self::assertSame('unknown', $parsed->condition, 'unbekannte Werte werden abgefangen');
        self::assertSame('schärfer', $parsed->reason);
        self::assertSame($parsed->toArray(), ComparisonMatch::fromArray($parsed->toArray())?->toArray());
        self::assertNull(ComparisonMatch::fromArray(['same_object' => true]));
    }

    /** @param list<int> $values Helligkeit der neun senkrechten Streifen, links nach rechts */
    private static function bands(array $values): \GdImage
    {
        $image = imagecreatetruecolor(450, 320);
        foreach ($values as $i => $value) {
            imagefilledrectangle($image, $i * 50, 0, $i * 50 + 49, 319, (int) imagecolorallocate($image, $value, $value, $value));
        }

        return $image;
    }
}
