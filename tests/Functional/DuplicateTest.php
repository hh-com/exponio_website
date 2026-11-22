<?php

namespace App\Tests\Functional;

use App\Duplicate\Candidate;
use App\Duplicate\CandidateFinder;
use App\Duplicate\ComparisonMatch;
use App\Duplicate\DuplicateMerger;
use App\Entity\CollectionArea;
use App\Entity\Item;
use App\Entity\ItemUpload;
use App\Entity\ScanJob;
use App\Enum\DuplicateStatus;
use App\Enum\ItemStatus;
use App\Enum\ScanStatus;
use App\Enum\UploadResult;
use App\Import\FolderImporter;
use App\Media\ImageHasher;
use App\Recognition\RecognitionService;
use App\Repository\CategoryRepository;
use App\Repository\CollectionAreaRepository;
use App\Service\SettingsProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Duplikat-Erkennung mit AI_PROVIDER=fake: Kandidatensuche, Import eines Duplikats (zählen, bessere Fotos ersetzen,
 * Rückgängig, Vorschlag bei veröffentlichten Objekten), unsichere Fälle, Bestandsprüfung und Abtrennen.
 */
final class DuplicateTest extends KernelTestCase
{
    /** Wahrnehmungs-Hashes der Testfotos – willkürliche Bitmuster, die keinem Demo-Foto ähneln */
    private const FRONT = 'c3a5996e0f17b2d4';
    private const BACK = '5e21f08b7d3c94a6';
    private const OTHER = '9b4e27d1a6f0385c';
    private const THIRD = '36d9e1047ac25bf8';

    private EntityManagerInterface $em;
    private FolderImporter $importer;
    private RecognitionService $recognition;
    private DuplicateMerger $merger;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->importer = $container->get(FolderImporter::class);
        $this->recognition = $container->get(RecognitionService::class);
        $this->merger = $container->get(DuplicateMerger::class);
        $fs = new Filesystem();
        $fs->remove($this->importer->getScanDir());
        $fs->mkdir($this->importer->getScanDir());
    }

    public function testCandidatesAreFoundByCatalogNumberRegionAndYear(): void
    {
        $area = $this->stampArea();
        $region = static::getContainer()->get(CategoryRepository::class)->findOneByName('Bundesrepublik Deutschland');
        self::assertNotNull($region);
        $definition = $area->getDefinition('michel');
        self::assertNotNull($definition);
        $make = function (string $michel, int $year) use ($area, $region, $definition): Item {
            $item = (new Item())->setTitle('Kandidatentest '.$michel)->setArea($area)->setCategory($region)->setYear($year)
                ->setDuplicateStatus(DuplicateStatus::Unique);
            $item->setAttributeValue($definition, $michel);
            $this->em->persist($item);

            return $item;
        };
        $new = $make('Mi 9876 A', 1961);
        $same = $make('9876a', 1961);
        $other = $make('1234', 1990);
        $this->em->flush();

        $candidates = static::getContainer()->get(CandidateFinder::class)->find($new);
        $ids = array_map(static fn (Candidate $c): ?int => $c->item->getId(), $candidates);
        self::assertSame($same->getId(), $ids[0] ?? null, 'gleiche Michel-Nr., gleiches Land, gleiches Jahr');
        self::assertTrue($candidates[0]->catalogMatch);
        self::assertContains('gleiche Katalognummer', $candidates[0]->reasons);
        self::assertNotContains($other->getId(), $ids, 'andere Nummer und anderes Jahr');
        self::assertLessThanOrEqual(CandidateFinder::MAX_CANDIDATES, \count($candidates));
    }

    public function testDuplicateUploadIsCountedAndBetterPhotosReplaceTheOldOnes(): void
    {
        $mediaDir = static::getContainer()->getParameter('app.media_dir');
        self::assertIsString($mediaDir);

        // erster Upload: nur die Vorderseite
        $original = $this->importAndRecognize('2031-03-01_100000', [self::FRONT]);
        $originalId = (int) $original->getId();
        self::assertNull($original->getMergedUpload(), 'der erste Upload bleibt ein eigenes Objekt');
        self::assertNotSame(DuplicateStatus::Pending, $original->getDuplicateStatus());
        self::assertSame(1, $original->getUploadCount());
        $originalImage = $original->getImages()->first();
        self::assertNotFalse($originalImage);
        self::assertLessThanOrEqual(2, ImageHasher::distance(self::FRONT, (string) $originalImage->getHash()), 'Hash wird beim Import gespeichert');

        // zweiter Upload desselben Stücks mit Vorder- und Rückseite: bessere Fotos
        $second = $this->importAndRecognize('2031-03-02_100000', [self::FRONT, self::BACK]);
        self::assertNotNull($second->getMergedUpload(), 'kein neues Objekt, sondern dem vorhandenen zugeordnet');
        self::assertNull($second->getId());

        $item = $this->reload($originalId);
        self::assertSame(2, $item->getUploadCount());
        self::assertNull($this->em->getRepository(Item::class)->findOneBy(['sourceFolder' => '2031-03-02_100000']));
        $first = $this->upload($item, '2031-03-01_100000');
        $latest = $this->upload($item, '2031-03-02_100000');
        self::assertSame(UploadResult::New, $first->getResult());
        self::assertSame(UploadResult::Duplicate, $latest->getResult());
        self::assertTrue($latest->isBetter());
        self::assertTrue($latest->isImagesReplaced(), 'bessere Fotos ersetzen die alten');
        self::assertNotNull($latest->getReason());
        self::assertCount(2, $item->getImages());
        self::assertFalse($first->isShown(), 'die alten Fotos sind ausgeblendet …');
        $oldImage = $first->getImages()->first();
        self::assertNotFalse($oldImage, '… aber nicht gelöscht');
        self::assertFileExists($mediaDir.'/'.$oldImage->getPath().'.webp');
        $job = $this->em->getRepository(ScanJob::class)->findOneBy(['folder' => '2031-03-02_100000']);
        self::assertNotNull($job);
        self::assertSame(ScanStatus::Merged, $job->getStatus());
        self::assertSame($originalId, $job->getItem()?->getId());

        // Rückgängig: die alten Fotos kommen zurück, der Upload bleibt gezählt
        $this->merger->undo($latest);
        $item = $this->reload($originalId);
        $shown = $item->getImages()->first();
        self::assertNotFalse($shown);
        self::assertSame($oldImage->getId(), $shown->getId());
        self::assertCount(1, $item->getImages());
        $latest = $this->upload($item, '2031-03-02_100000');
        self::assertFalse($latest->isImagesReplaced());
        self::assertNotNull($latest->getUndoneAt());
        self::assertSame(2, $item->getUploadCount());

        // veröffentlichtes Objekt: bessere Fotos werden nur vorgeschlagen
        $item->setStatus(ItemStatus::Published);
        $item->setStorageLocation('Album 3')->setInternalNotes('geprüft');
        $this->em->flush();
        $third = $this->importAndRecognize('2031-03-03_100000', [self::FRONT, self::BACK]);
        self::assertNotNull($third->getMergedUpload());
        $item = $this->reload($originalId);
        $proposal = $this->upload($item, '2031-03-03_100000');
        self::assertTrue($proposal->isProposal());
        self::assertFalse($proposal->isImagesReplaced());
        self::assertCount(1, $item->getImages(), 'die Website zeigt weiter die bisherigen Fotos');

        $this->merger->useImages($proposal);
        $item = $this->reload($originalId);
        self::assertCount(2, $item->getImages(), 'nach der Bestätigung ersetzt');
        self::assertFalse($this->upload($item, '2031-03-03_100000')->isProposal());
        self::assertTrue($item->isPublished(), 'Status bleibt erhalten');
        self::assertSame('Album 3', $item->getStorageLocation(), 'Lagerort bleibt erhalten');
        self::assertSame('geprüft', $item->getInternalNotes(), 'interne Notizen bleiben erhalten');

        // schlechterer Upload (nur die Vorderseite): wird nur gezählt
        $fourth = $this->importAndRecognize('2031-03-04_100000', [self::FRONT]);
        self::assertNotNull($fourth->getMergedUpload());
        $item = $this->reload($originalId);
        $counted = $this->upload($item, '2031-03-04_100000');
        self::assertFalse($counted->isBetter());
        self::assertFalse($counted->isImagesReplaced());
        self::assertFalse($counted->isProposal());
        self::assertSame(4, $item->getUploadCount());
        self::assertCount(2, $item->getImages());

        // Testobjekt nicht auf der Website lassen
        $item->setStatus(ItemStatus::Archived);
        $this->em->flush();
    }

    public function testUncertainDuplicateIsMarkedAndDecidedByHand(): void
    {
        $settings = static::getContainer()->get(SettingsProvider::class)->get();
        $settings->setDuplicateThreshold(0.99); // die 0,95 des Fake-Vergleichs gelten jetzt als unsicher
        $this->em->flush();

        try {
            $original = $this->importAndRecognize('2031-04-01_100000', [self::OTHER]);
            $originalId = (int) $original->getId();

            $candidate = $this->importAndRecognize('2031-04-02_100000', [self::OTHER]);
            self::assertNull($candidate->getMergedUpload(), 'unsicher: nichts automatisch zusammenführen');
            self::assertSame(DuplicateStatus::Possible, $candidate->getDuplicateStatus());
            self::assertSame($originalId, $candidate->getDuplicateOf()?->getId());
            self::assertSame(0.95, $candidate->getDuplicateConfidence());

            // „Ist ein eigenes Objekt“
            $this->merger->separate($candidate);
            self::assertSame(DuplicateStatus::Separate, $candidate->getDuplicateStatus());
            self::assertNull($candidate->getDuplicateOf());

            // nächster unsicherer Upload: von Hand zuordnen, Bilder behalten
            $third = $this->importAndRecognize('2031-04-03_100000', [self::OTHER]);
            self::assertTrue($third->isPossibleDuplicate());
            $target = $third->getDuplicateOf();
            self::assertNotNull($target);
            $upload = $this->merger->merge($third, $target, ComparisonMatch::fromArray($third->getDuplicateResult() ?? []), false, true);
            self::assertTrue($upload->isManual());
            self::assertFalse($upload->isImagesReplaced());
            self::assertSame(UploadResult::Duplicate, $upload->getResult());
            self::assertSame(2, $upload->getItem()->getUploadCount());
        } finally {
            static::getContainer()->get(SettingsProvider::class)->get()->setDuplicateThreshold(0.85);
            $this->em->flush();
        }
    }

    public function testExistingItemsAreOnlyMarkedAndUploadsCanBeSplitOff(): void
    {
        // Bestand: zwei Objekte mit demselben Foto, noch nie auf Duplikate geprüft
        $first = $this->import('2031-05-01_100000', [self::THIRD])->setDuplicateStatus(null)->setStatus(ItemStatus::Review);
        $second = $this->import('2031-05-02_100000', [self::THIRD])->setDuplicateStatus(null)->setStatus(ItemStatus::Review);
        $this->em->flush();
        $firstId = (int) $first->getId();
        $secondId = (int) $second->getId();

        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $tester = new CommandTester((new Application($kernel))->find('app:duplicates:scan'));
        $tester->execute(['--dry-run' => true, '--item' => (string) $secondId]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('#'.$firstId, $tester->getDisplay());
        self::assertNull($this->reload($secondId)->getDuplicateStatus(), '--dry-run speichert nichts');

        $tester->execute(['--item' => (string) $secondId]);
        $tester->assertCommandIsSuccessful();
        $second = $this->reload($secondId);
        self::assertSame(DuplicateStatus::Possible, $second->getDuplicateStatus(), 'Bestand wird nie automatisch zusammengeführt');
        self::assertSame($firstId, $second->getDuplicateOf()?->getId());

        // von Hand zuordnen und wieder abtrennen
        $first = $this->em->find(Item::class, $firstId);
        self::assertNotNull($first);
        $this->merger->merge($second, $first, null, false, true);
        $first = $this->reload($firstId);
        self::assertSame(2, $first->getUploadCount());
        $split = $this->merger->split($this->upload($first, '2031-05-02_100000'));
        self::assertSame(DuplicateStatus::Separate, $split->getDuplicateStatus());
        self::assertSame(ItemStatus::Draft, $split->getStatus());
        self::assertSame('2031-05-02_100000', $split->getSourceFolder());
        self::assertCount(1, $split->getImages());
        self::assertSame(1, $this->reload($firstId)->getUploadCount());
    }

    /** @param list<string> $patterns ein Foto je Hash-Bitmuster */
    private function import(string $folder, array $patterns): Item
    {
        $dir = $this->importer->getScanDir().'/'.$folder;
        (new Filesystem())->mkdir($dir);
        foreach ($patterns as $n => $pattern) {
            self::writePattern(sprintf('%s/%02d.webp', $dir, $n + 1), $pattern);
        }
        $item = $this->importer->importFolder($folder);
        self::assertSame(DuplicateStatus::Pending, $item->getDuplicateStatus());

        return $item;
    }

    /** @param list<string> $patterns */
    private function importAndRecognize(string $folder, array $patterns): Item
    {
        $item = $this->import($folder, $patterns);
        self::assertTrue($this->recognition->recognize($item), (string) $item->getAiError());

        return $item;
    }

    /** Frischer Stand aus der Datenbank (auch die zwischengespeicherten Einstellungen neu laden) */
    private function reload(int $id): Item
    {
        $this->em->clear();
        static::getContainer()->get(SettingsProvider::class)->reset();
        $item = $this->em->find(Item::class, $id);
        self::assertInstanceOf(Item::class, $item);

        return $item;
    }

    private function upload(Item $item, string $folder): ItemUpload
    {
        foreach ($item->getUploads() as $upload) {
            if ($folder === $upload->getFolder()) {
                return $upload;
            }
        }
        self::fail(sprintf('Upload %s fehlt bei #%d.', $folder, $item->getId()));
    }

    private function stampArea(): CollectionArea
    {
        foreach (static::getContainer()->get(CollectionAreaRepository::class)->findAllWithDefinitions() as $area) {
            if (null !== $area->getDefinition('michel')) {
                return $area;
            }
        }
        self::fail('Die Objektart Briefmarken fehlt.');
    }

    /**
     * Testfoto aus 9 × 8 grauen Feldern, dessen Wahrnehmungs-Hash genau dem Bitmuster entspricht:
     * Ist ein Bit gesetzt, ist das Feld heller als sein rechter Nachbar.
     */
    private static function writePattern(string $file, string $hex): void
    {
        $image = imagecreatetruecolor(450, 320);
        foreach (str_split($hex, 2) as $row => $byte) {
            $bits = (int) hexdec($byte);
            $value = 128;
            for ($x = 0; $x < 9; ++$x) {
                imagefilledrectangle($image, $x * 50, $row * 40, $x * 50 + 49, $row * 40 + 39, (int) imagecolorallocate($image, $value, $value, $value));
                if ($x < 8) {
                    $value += 1 === (($bits >> (7 - $x)) & 1) ? -14 : 14;
                }
            }
        }
        imagewebp($image, $file, 95);
    }
}
