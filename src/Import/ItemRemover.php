<?php

namespace App\Import;

use App\Entity\Item;
use App\Entity\ItemUpload;
use App\Entity\ScanJob;
use App\Enum\DuplicateStatus;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Löscht ein Objekt endgültig („Löschen“ in Prüfen & freigeben): Datensatz, alle Fotos (App\EventListener\ItemImageFileListener)
 * und die Upload-Ordner aller seiner Uploads, auch die von zugeordneten Duplikaten.
 *
 * Das Scan-Protokoll behält die Ordner. Lässt sich ein Ordner nicht löschen, wird er so nicht erneut importiert.
 */
final class ItemRemover
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FolderImporter $importer,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Upload-Ordner, die mit dem Objekt gelöscht werden.
     *
     * Item::$sourceFolder zählt nur, wenn kein Upload den Ordner führt: Nach dem Abtrennen gehört er zu einem anderen Objekt.
     *
     * @return list<string>
     */
    public function folders(Item $item): array
    {
        $folders = [];
        foreach ($item->getUploads() as $upload) {
            if (null !== $upload->getFolder()) {
                $folders[$upload->getFolder()] = true;
            }
        }
        $source = $item->getSourceFolder();
        if (null !== $source && !isset($folders[$source]) && null === $this->em->getRepository(ItemUpload::class)->findOneBy(['folder' => $source])) {
            $folders[$source] = true;
        }
        $folders = array_keys($folders);
        sort($folders, \SORT_NATURAL);

        return $folders;
    }

    /**
     * @return array{folders: list<string>, failed: array<string, string>} gelöschte Ordner, nicht löschbare Ordner mit Fehlermeldung
     */
    public function remove(Item $item): array
    {
        $id = $item->getId();
        $folders = $this->folders($item);

        if ([] !== $folders) {
            foreach ($this->em->getRepository(ScanJob::class)->findBy(['folder' => $folders]) as $job) {
                $job->detach(sprintf('Objekt #%d gelöscht', $id));
            }
        }
        // Hinweise „mögliches Duplikat von #ID“ zeigen sonst ins Leere
        foreach ($this->em->getRepository(Item::class)->findBy(['duplicateOf' => $item]) as $other) {
            $other->markPossibleDuplicate(null);
            $other->setDuplicateStatus(DuplicateStatus::Unique);
        }
        $this->em->remove($item);
        $this->em->flush();

        $result = ['folders' => [], 'failed' => []];
        foreach ($folders as $folder) {
            try {
                if ($this->importer->removeFolder($folder)) {
                    $result['folders'][] = $folder;
                }
            } catch (\RuntimeException|\InvalidArgumentException $e) {
                $this->logger->error('Upload-Ordner {folder} von Objekt #{id} nicht gelöscht: {message}', ['folder' => $folder, 'id' => $id, 'message' => $e->getMessage(), 'exception' => $e]);
                $result['failed'][$folder] = $e->getMessage();
            }
        }

        return $result;
    }
}
