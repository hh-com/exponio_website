<?php

namespace App\Import;

use App\Entity\Item;
use App\Entity\ItemImage;
use App\Entity\ItemUpload;
use App\Entity\ScanJob;
use App\Enum\DuplicateStatus;
use App\Enum\ScanStatus;
use App\Media\MediaStorage;
use App\Repository\CollectionAreaRepository;
use App\Repository\ScanJobRepository;
use App\Service\SettingsProvider;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Importiert neue Upload-Ordner der Exponio-App: ein Ordner = ein Objekt (Entwurf) mit einem Upload-Eintrag.
 * Der Upload-Ordner wird nur gelesen – die Originale bleiben unverändert und lassen sich jederzeit
 * erneut verarbeiten (app:media:rebuild, oder neuer Import bei leerer Datenbank).
 *
 * Das neue Objekt ist für die Duplikatprüfung vorgemerkt (DuplicateStatus::Pending): Nach der KI-Erkennung
 * prüft App\Duplicate\DuplicateDetector, ob es das Objekt schon gibt, und ordnet den Upload dann dem vorhandenen zu.
 *
 * Die App legt Ordner erst unter "_tmp_<name>" an und benennt sie nach vollständiger Prüfung
 * atomar in "<YYYY-MM-DD_HHMMSS>" (ggf. mit "_2") um. Es werden nur fertige Ordner importiert.
 */
final class FolderImporter
{
    public const FOLDER_PATTERN = '/^\d{4}-\d{2}-\d{2}_\d{6}(_\d+)?$/';
    private const IMAGE_PATTERN = '/\.(webp|jpe?g|png|avif)$/i';

    public function __construct(
        private readonly string $scanDir,
        private readonly EntityManagerInterface $em,
        private readonly ScanJobRepository $scanJobs,
        private readonly CollectionAreaRepository $areas,
        private readonly SettingsProvider $settings,
        private readonly MediaStorage $media,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getScanDir(): string
    {
        return $this->scanDir;
    }

    /** @return list<string> noch nicht verarbeitete, fertige Ordner (älteste zuerst) */
    public function findNewFolders(): array
    {
        if (!is_dir($this->scanDir)) {
            throw new \RuntimeException(sprintf('Upload-Verzeichnis "%s" existiert nicht (SCAN_DIR prüfen).', $this->scanDir));
        }
        $known = $this->scanJobs->findKnownFolders();
        $folders = [];
        foreach (scandir($this->scanDir) ?: [] as $entry) {
            if (preg_match(self::FOLDER_PATTERN, $entry) && !isset($known[$entry]) && is_dir($this->scanDir.'/'.$entry)) {
                $folders[] = $entry;
            }
        }
        sort($folders, \SORT_NATURAL);

        return $folders;
    }

    /**
     * @return array{imported: list<Item>, failed: array<string, string>}
     */
    public function importNew(int $limit = 50): array
    {
        $result = ['imported' => [], 'failed' => []];
        foreach (\array_slice($this->findNewFolders(), 0, $limit) as $folder) {
            try {
                $result['imported'][] = $this->importFolder($folder);
            } catch (\Throwable $e) {
                $this->logger->error('Import von {folder} fehlgeschlagen: {message}', ['folder' => $folder, 'message' => $e->getMessage(), 'exception' => $e]);
                $result['failed'][$folder] = $e->getMessage();
                if (!$this->em->isOpen()) {
                    throw $e;
                }
                $this->em->clear();
                $this->em->persist(new ScanJob($folder, ScanStatus::Failed, 0, null, $e->getMessage()));
                $this->em->flush();
            }
        }

        return $result;
    }

    public function importFolder(string $folder): Item
    {
        if (!preg_match(self::FOLDER_PATTERN, $folder)) {
            throw new \InvalidArgumentException(sprintf('Ungültiger Ordnername "%s".', $folder));
        }
        $dir = $this->scanDir.'/'.$folder;
        $files = array_values(array_filter(
            scandir($dir) ?: [],
            static fn (string $f): bool => (bool) preg_match(self::IMAGE_PATTERN, $f) && is_file($dir.'/'.$f),
        ));
        sort($files, \SORT_NATURAL);
        if ([] === $files) {
            throw new \RuntimeException('Der Ordner enthält keine Bilder.');
        }

        $item = new Item();
        $item->setTitle('Neuer Import '.$this->folderLabel($folder));
        $item->setSourceFolder($folder);
        $item->setArea($this->settings->get()->getDefaultScanArea() ?? ($this->areas->findActive()[0] ?? null));
        $item->setDuplicateStatus(DuplicateStatus::Pending);
        $item->addUpload(new ItemUpload($item, $folder));

        $stored = [];
        try {
            foreach ($files as $index => $file) {
                $stored[] = $saved = $this->media->store($dir.'/'.$file, sprintf('items/%s/%02d', $folder, $index + 1));
                $image = (new ItemImage())->setPath($saved['path'])->setPosition($index)->setDimensions($saved['width'], $saved['height'])
                    ->setSourceFile($folder.'/'.$file)->setHash($saved['hash']);
                $item->addImage($image);
            }

            $this->em->persist($item);
            $this->em->persist(new ScanJob($folder, ScanStatus::Imported, \count($files), $item));
            $this->em->flush();
        } catch (\Throwable $e) {
            foreach ($stored as $saved) {
                $this->media->delete($saved['path']);
            }
            throw $e;
        }

        return $item;
    }

    private function folderLabel(string $folder): string
    {
        $date = \DateTimeImmutable::createFromFormat('Y-m-d_His', substr($folder, 0, 17));

        return false === $date ? $folder : $date->format('d.m.Y H:i');
    }
}
