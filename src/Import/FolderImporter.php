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
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;

/**
 * Importiert neue Upload-Ordner der Exponio-App: ein Ordner = ein Objekt (Entwurf) mit einem Upload-Eintrag.
 * Der Upload-Ordner wird nur gelesen – die Originale bleiben unverändert und lassen sich jederzeit
 * erneut verarbeiten (app:media:rebuild, oder neuer Import bei leerer Datenbank). Gelöscht wird ein Ordner
 * nur zusammen mit seinem Objekt (App\Import\ItemRemover, „Löschen“ in Prüfen & freigeben).
 *
 * Das neue Objekt ist für die Duplikatprüfung vorgemerkt (DuplicateStatus::Pending): Nach der KI-Erkennung
 * prüft App\Duplicate\DuplicateDetector, ob es das Objekt schon gibt, und ordnet den Upload dann dem vorhandenen zu.
 *
 * Uploads der App landen erst unter "_tmp_<name>" und werden nach vollständiger Prüfung atomar in
 * "<YYYY-MM-DD_HHMMSS>" (ggf. mit "_2") umbenannt (App\Import\UploadInbox). Es werden nur fertige Ordner importiert.
 * Eine Notiz aus der App ("info.txt") wird zum Hinweis an die KI des neuen Objekts.
 *
 * Importe laufen nie gleichzeitig (Sperre „import“ in config/packages/lock.yaml): Cronjob, „Ordner scannen“ und der
 * Sofortimport nach einem Upload der App (App\EventSubscriber\AppUploadImportSubscriber) könnten sonst denselben Ordner
 * zweimal importieren – der zweite Versuch scheitert am Scan-Protokoll und räumte dabei die Webbilder des ersten ab.
 */
final class FolderImporter
{
    public const FOLDER_PATTERN = '/^\d{4}-\d{2}-\d{2}_\d{6}(_\d+)?$/';
    /** Notiz zur Fotogruppe aus der App (UTF-8), siehe UploadInbox::NOTE_FILE */
    public const NOTE_FILE = 'info.txt';
    private const IMAGE_PATTERN = '/\.(webp|jpe?g|png|avif)$/i';
    /** Mehr nimmt die App-Schnittstelle nicht an (UploadInbox::MAX_NOTE_SIZE); größere Dateien werden nur angelesen */
    private const MAX_NOTE_BYTES = 65536;

    public function __construct(
        private readonly string $scanDir,
        private readonly EntityManagerInterface $em,
        private readonly ScanJobRepository $scanJobs,
        private readonly CollectionAreaRepository $areas,
        private readonly SettingsProvider $settings,
        private readonly MediaStorage $media,
        private readonly LoggerInterface $logger,
        #[Target('import')]
        private readonly LockFactory $locks,
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
     * Importiert neue Ordner. Läuft schon ein Import, wird auf ihn gewartet; danach ist ein Ordner, den er übernommen
     * hat, nicht mehr neu.
     *
     * @param string|null $only nur diesen Ordner, falls er neu ist (Sofortimport nach einem Upload der App)
     *
     * @return array{imported: list<Item>, failed: array<string, string>}
     */
    public function importNew(int $limit = 50, ?string $only = null): array
    {
        $lock = $this->locks->createLock('app-import', 1800);
        $lock->acquire(true);
        try {
            $folders = $this->findNewFolders();
            if (null !== $only) {
                $folders = \in_array($only, $folders, true) ? [$only] : [];
            }
            $result = ['imported' => [], 'failed' => []];
            foreach (\array_slice($folders, 0, $limit) as $folder) {
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
        } finally {
            $lock->release();
        }
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
        $item->setAiHint($this->readNote($dir, $folder));
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

    /**
     * Löscht einen Upload-Ordner samt Inhalt.
     *
     * @return bool false, wenn es den Ordner nicht (mehr) gibt
     *
     * @throws \InvalidArgumentException bei einem Namen, der kein Upload-Ordner der App ist
     * @throws \RuntimeException         wenn er sich nicht löschen lässt (z. B. fehlende Schreibrechte)
     */
    public function removeFolder(string $folder): bool
    {
        // das Muster lässt weder "/" noch ".." zu: gelöscht wird nur direkt im Upload-Verzeichnis
        if (!preg_match(self::FOLDER_PATTERN, $folder)) {
            throw new \InvalidArgumentException(sprintf('Ungültiger Ordnername "%s".', $folder));
        }
        $dir = $this->scanDir.'/'.$folder;
        if (!is_dir($dir) && !is_link($dir)) {
            return false;
        }
        try {
            (new Filesystem())->remove($dir);
        } catch (IOExceptionInterface $e) {
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }

        return true;
    }

    /**
     * Notiz aus der App als Hinweis an die KI: höchstens Item::AI_HINT_MAX Zeichen (der Rest bleibt in info.txt),
     * ungültiges UTF-8 wird ersetzt statt abgelehnt (Ordner können auch per SFTP kommen).
     */
    private function readNote(string $dir, string $folder): ?string
    {
        $path = $dir.'/'.self::NOTE_FILE;
        if (!is_file($path)) {
            return null;
        }
        $text = @file_get_contents($path, false, null, 0, self::MAX_NOTE_BYTES);
        if (false === $text) {
            $this->logger->warning('Notiz {file} lässt sich nicht lesen.', ['file' => $folder.'/'.self::NOTE_FILE]);

            return null;
        }
        $text = trim(str_replace(["\r\n", "\r"], "\n", mb_scrub(preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? '', 'UTF-8')));
        if (mb_strlen($text) > Item::AI_HINT_MAX) {
            $this->logger->notice('Notiz {file} ist länger als {max} Zeichen und wurde für den Hinweis an die KI gekürzt.', ['file' => $folder.'/'.self::NOTE_FILE, 'max' => Item::AI_HINT_MAX]);
        }

        return '' === $text ? null : $text;
    }

    private function folderLabel(string $folder): string
    {
        $date = \DateTimeImmutable::createFromFormat('Y-m-d_His', substr($folder, 0, 17));

        return false === $date ? $folder : $date->format('d.m.Y H:i');
    }
}
