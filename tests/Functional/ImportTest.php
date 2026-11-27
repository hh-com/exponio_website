<?php

namespace App\Tests\Functional;

use App\Entity\Item;
use App\Entity\ItemImage;
use App\Entity\ScanJob;
use App\Enum\ImageSide;
use App\Enum\ItemStatus;
use App\Enum\ScanStatus;
use App\Import\FolderImporter;
use App\Recognition\RecognitionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Upload-Ordner der App → Import → (Fake-)Erkennung → Prüf-Warteschlange.
 */
final class ImportTest extends KernelTestCase
{
    public function testFolderIsImportedOnceAndRecognized(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $importer = $container->get(FolderImporter::class);
        $fs = new Filesystem();
        $dir = $importer->getScanDir();
        $fs->remove($dir);
        $fs->mkdir([$dir.'/2030-01-02_101500', $dir.'/_tmp_2030-01-02_101600', $dir.'/2030-01-02_101700']);
        $this->writeImage($dir.'/2030-01-02_101500/01.webp');
        $this->writeImage($dir.'/2030-01-02_101500/02.webp');
        // Notiz, wie sie auch per SFTP kommen könnte: BOM, Windows-Zeilenenden, ein ungültiges Byte
        $fs->dumpFile($dir.'/2030-01-02_101500/info.txt', "\xEF\xBB\xBF DDR 1953\r\nFalz \xC3(\r\n");
        $this->writeImage($dir.'/_tmp_2030-01-02_101600/01.webp'); // unfertiger Upload – ignorieren
        // 2030-01-02_101700 bleibt leer → Fehler im Protokoll

        $result = $importer->importNew();
        self::assertCount(1, $result['imported']);
        self::assertArrayHasKey('2030-01-02_101700', $result['failed']);
        $item = $result['imported'][0];
        self::assertSame('2030-01-02_101500', $item->getSourceFolder());
        self::assertCount(2, $item->getImages());
        self::assertSame(ItemStatus::Draft, $item->getStatus());
        self::assertSame("DDR 1953\nFalz ?(", $item->getAiHint(), 'Notiz aus der App wird zum Hinweis an die KI');
        $image = $item->getImages()->first();
        self::assertInstanceOf(ItemImage::class, $image);
        $mediaDir = $container->getParameter('app.media_dir');
        self::assertIsString($mediaDir);
        self::assertMatchesRegularExpression('#^items/2030-01-02_101500/01-[0-9a-f]{8}$#', (string) $image->getPath(), 'Dateiname mit Inhalts-Hash');
        self::assertSame('2030-01-02_101500/01.webp', $image->getSourceFile(), 'Original im Upload-Ordner bleibt bekannt');
        self::assertFileExists($mediaDir.'/'.$image->getPath().'-400.webp');
        self::assertFileExists($dir.'/2030-01-02_101500/01.webp', 'Upload-Ordner bleibt unverändert');

        $em = $container->get(EntityManagerInterface::class);
        $failed = $em->getRepository(ScanJob::class)->findOneBy(['folder' => '2030-01-02_101700']);
        self::assertSame(ScanStatus::Failed, $failed?->getStatus());

        // zweiter Lauf importiert nichts doppelt
        self::assertSame([], $importer->findNewFolders());

        $item = $em->find(Item::class, $item->getId());
        self::assertInstanceOf(Item::class, $item);
        self::assertTrue($container->get(RecognitionService::class)->recognize($item));
        self::assertSame(ItemStatus::Review, $item->getStatus());
        self::assertNotNull($item->getRecognizedAt());
        self::assertSame('Deutschland › Bundesrepublik Deutschland', $item->getCategory()?->getPathLabel(), 'vorhandenes Gebiet wird wiederverwendet');
        self::assertNotNull($item->getAttributeValue('michel'));
        self::assertGreaterThan(0, $item->getTopics()->count());
        [$front, $back] = $item->getImages()->getValues();
        self::assertSame(ImageSide::Front, $front->getSide());
        self::assertSame(ImageSide::Back, $back->getSide());
        self::assertNotNull($front->getAlt());
        self::assertFalse($item->isShowEstimate(), 'Schätzwert wird nie automatisch freigegeben');
    }

    private function writeImage(string $file): void
    {
        $image = imagecreatetruecolor(300, 360);
        imagefilledrectangle($image, 0, 0, 300, 360, (int) imagecolorallocate($image, 40, 90, 160));
        imagewebp($image, $file);
    }
}
