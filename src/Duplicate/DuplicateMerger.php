<?php

namespace App\Duplicate;

use App\Entity\Item;
use App\Entity\ItemImage;
use App\Entity\ItemUpload;
use App\Entity\ScanJob;
use App\Enum\DuplicateStatus;
use App\Enum\ScanStatus;
use App\Enum\UploadResult;
use App\Service\SettingsProvider;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Zusammenführen, Bilder ersetzen, Rückgängig und Abtrennen.
 *
 * - Fotos werden nie gelöscht: Ersetzte Fotos bleiben an ihrem Upload („früher“), nicht übernommene Fotos
 *   eines Duplikats ebenso. Die Originale in app_uploads/ bleiben ohnehin unangetastet.
 * - Beim Ersetzen bleiben Titel, Beschreibung, Übersetzungen, Status, Veröffentlichung, Schätzwert-Freigabe,
 *   Lagerort und interne Notizen erhalten. Hat sich die Erhaltung geändert (oder ist es ein anderes Exemplar),
 *   wechseln Mängel und Exemplar-Merkmale mit den Fotos.
 * - Jede Änderung an den Fotos erhöht die Inhaltsversion (ContentChangeListener); Alt-Texte der neuen Fotos
 *   stammen aus der Erkennung und werden von app:translate übersetzt.
 */
final class DuplicateMerger
{
    /** Merkmale, die vom einzelnen Exemplar abhängen (Erhaltung, Gummierung, Stempel, Seriennummer) */
    public const SPECIMEN_ATTRIBUTES = ['condition', 'gum', 'quality', 'grade', 'postmark', 'serial'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SettingsProvider $settings,
    ) {
    }

    /**
     * Ordnet $source (frischer Import oder mögliches Duplikat) dem vorhandenen Objekt $target zu.
     * Uploads und Fotos von $source wandern zu $target, danach wird $source gelöscht.
     *
     * Ohne Entscheidung von Hand ($replace = null) ersetzen die neuen Fotos die alten nur, wenn sie besser sind.
     * Bei veröffentlichten Objekten werden sie dann nur vorgeschlagen, außer die Einstellung erlaubt das Ersetzen.
     *
     * @param bool|null $replace null = automatisch, true/false = Entscheidung von Hand
     */
    public function merge(Item $source, Item $target, ?ComparisonMatch $match, ?bool $replace = null, bool $manual = false): ItemUpload
    {
        if ($source === $target) {
            throw new \InvalidArgumentException('Ein Objekt kann nicht sich selbst zugeordnet werden.');
        }
        $incoming = $source->getCurrentUpload();
        if (null === $incoming) {
            $incoming = new ItemUpload($source, $source->getSourceFolder());
            $source->addUpload($incoming);
            $this->em->persist($incoming);
        }
        $recognized = $this->specimenData($source);

        // die Fotos des Uploads bleiben erhalten, werden aber vorerst nicht gezeigt
        foreach ($source->getImages()->toArray() as $image) {
            if (null === $image->getUpload()) {
                $incoming->addImage($image);
            }
            $source->detachImage($image);
        }
        foreach ($source->getUploads()->toArray() as $upload) {
            $source->removeUpload($upload);
            $target->addUpload($upload);
            $upload->setResult(UploadResult::Duplicate);
        }
        $reason = null !== $match && '' !== $match->reason ? $match->reason : null;
        $incoming->setManual($manual)
            ->setRecognized($recognized)
            ->setComparison($match?->toArray())
            ->setSpecimen($match?->specimen)
            ->setConfidence($match?->confidence)
            ->setBetter($match?->isBetter())
            ->setReason($reason ?? ($manual ? 'Von Hand als Duplikat zugeordnet.' : null));

        $this->keepNotes($source, $target);
        foreach ($this->em->getRepository(ScanJob::class)->findBy(['item' => $source]) as $job) {
            $job->assign($target, ScanStatus::Merged, sprintf('Duplikat von #%d', $target->getId()));
        }
        foreach ($this->em->getRepository(Item::class)->findBy(['duplicateOf' => $source]) as $other) {
            $other->markPossibleDuplicate($target, $other->getDuplicateConfidence(), $other->getDuplicateReason(), $other->getDuplicateResult());
        }

        if (null === $replace) {
            $replace = $this->decide($target, $match, $incoming);
        }
        if ($replace) {
            $this->activate($incoming, $match?->conditionChanged() ?? true);
        }

        $source->setMergedUpload($incoming);
        $this->em->remove($source);
        $this->em->flush();

        return $incoming;
    }

    /** Hinweis „mögliches Duplikat“ verwerfen: das Objekt bleibt ein eigenes. */
    public function separate(Item $item): void
    {
        $item->markPossibleDuplicate(null);
        $item->setDuplicateStatus(DuplicateStatus::Separate);
        $this->em->flush();
    }

    /** Fotos eines Uploads zeigen (Vorschlag annehmen oder frühere Fotos wieder verwenden). */
    public function useImages(ItemUpload $upload): void
    {
        if ($upload->getImages()->isEmpty()) {
            throw new \LogicException('Zu diesem Upload gibt es keine Fotos.');
        }
        $match = ComparisonMatch::fromArray($upload->getComparison() ?? []);
        $this->activate($upload, $match?->conditionChanged() ?? false);
        $this->em->flush();
    }

    /** Vorgeschlagene Fotos ablehnen: Das Objekt bleibt, wie es ist; der Upload bleibt gezählt. */
    public function rejectProposal(ItemUpload $upload): void
    {
        $upload->setProposal(false);
        $this->em->flush();
    }

    /** Ersetzen der Fotos zurücknehmen: die vorherigen Fotos (und ggf. Mängel und Merkmale) wiederherstellen. */
    public function undo(ItemUpload $upload): void
    {
        if (!$upload->canUndo()) {
            throw new \LogicException('Dieses Ersetzen lässt sich nicht mehr rückgängig machen (die Fotos werden nicht mehr gezeigt).');
        }
        $item = $upload->getItem();
        $snapshot = $upload->getSnapshot() ?? [];
        $ids = array_map('intval', \is_array($snapshot['images'] ?? null) ? $snapshot['images'] : []);
        $previous = array_values(array_filter(
            [] === $ids ? [] : $this->em->getRepository(ItemImage::class)->findBy(['id' => $ids]),
            static fn (ItemImage $image): bool => $image->getUpload()?->getItem() === $item,
        ));
        if ([] !== $ids && [] === $previous) {
            throw new \LogicException('Die vorherigen Fotos gibt es nicht mehr.');
        }

        $this->hideAll($item);
        usort($previous, static fn (ItemImage $a, ItemImage $b): int => [$a->getPosition(), $a->getId()] <=> [$b->getPosition(), $b->getId()]);
        foreach ($previous as $image) {
            $item->addImage($image);
        }
        if (true === ($snapshot['conditionApplied'] ?? false)) {
            $item->setDefects(\is_string($snapshot['defects'] ?? null) ? $snapshot['defects'] : null);
            $this->applyAttributes($item, \is_array($snapshot['attributes'] ?? null) ? $snapshot['attributes'] : []);
        }
        $upload->setImagesReplaced(false)->setUndoneAt(new \DateTimeImmutable());
        $this->em->flush();
    }

    /**
     * Einen zugeordneten Upload wieder als eigenes Objekt anlegen (falsch erkanntes Duplikat).
     * Das neue Objekt ist ein Entwurf und wird beim nächsten app:scan erkannt – ohne erneute Duplikatprüfung.
     */
    public function split(ItemUpload $upload): Item
    {
        $source = $upload->getItem();
        if ($source->getUploads()->count() < 2) {
            throw new \LogicException('Das Objekt hat nur diesen einen Upload.');
        }
        if ($upload->isShown()) {
            throw new \LogicException('Die Fotos dieses Uploads werden gerade gezeigt. Zeige zuerst andere Fotos oder mache das Ersetzen rückgängig.');
        }
        if ($upload->getImages()->isEmpty()) {
            throw new \LogicException('Zu diesem Upload gibt es keine Fotos.');
        }

        $item = (new Item())
            ->setTitle(sprintf('Abgetrennt von #%d: %s', $source->getId(), $source->getTitle()))
            ->setArea($source->getArea())
            ->setDuplicateStatus(DuplicateStatus::Separate);
        $folder = $upload->getFolder();
        if (null !== $folder && null === $this->em->getRepository(Item::class)->findOneBy(['sourceFolder' => $folder])) {
            $item->setSourceFolder($folder);
        }
        $source->removeUpload($upload);
        $item->addUpload($upload);
        $upload->setResult(UploadResult::New)->setManual(true)->setProposal(false)->setImagesReplaced(false)
            ->setReason('Von Hand als eigenes Objekt abgetrennt.');
        $position = 0;
        foreach ($upload->getImages() as $image) {
            $item->addImage($image->setPosition($position++));
        }
        $this->em->persist($item);
        $this->em->flush();

        if (null !== $folder) {
            foreach ($this->em->getRepository(ScanJob::class)->findBy(['folder' => $folder]) as $job) {
                $job->assign($item, ScanStatus::Imported, sprintf('Von #%d abgetrennt', $source->getId()));
            }
            $this->em->flush();
        }

        return $item;
    }

    /**
     * Zeigt die Fotos eines Uploads und blendet die bisherigen aus. Der vorherige Zustand wird für „Rückgängig“ gemerkt.
     */
    public function activate(ItemUpload $upload, bool $applyCondition): void
    {
        $item = $upload->getItem();
        $snapshot = [
            'images' => array_values(array_filter($item->getImages()->map(static fn (ItemImage $i): ?int => $i->getId())->getValues())),
            'conditionApplied' => $applyCondition,
            'defects' => $item->getDefects(),
            'attributes' => $this->specimenAttributes($item),
        ];

        $this->hideAll($item);
        $position = 0;
        foreach ($upload->getImages() as $image) {
            $item->addImage($image->setPosition($position++));
        }
        if ($applyCondition) {
            $recognized = $upload->getRecognized() ?? [];
            if (\array_key_exists('defects', $recognized)) {
                $item->setDefects(\is_string($recognized['defects']) ? $recognized['defects'] : null);
            }
            $this->applyAttributes($item, \is_array($recognized['attributes'] ?? null) ? $recognized['attributes'] : []);
        }
        $upload->setImagesReplaced(true)->setProposal(false)->setSnapshot($snapshot)->setUndoneAt(null);
    }

    private function decide(Item $target, ?ComparisonMatch $match, ItemUpload $upload): bool
    {
        if (null === $match || !$match->isBetter()) {
            return false;
        }
        if ($target->isPublished() && !$this->settings->get()->isDuplicateAutoReplacePublished()) {
            $upload->setProposal(true);

            return false;
        }

        return true;
    }

    /** Alle gezeigten Fotos ausblenden; Fotos ohne Upload werden vorher dem ältesten Upload zugeordnet. */
    private function hideAll(Item $item): void
    {
        $fallback = $item->getUploads()->first() ?: null;
        foreach ($item->getImages()->toArray() as $image) {
            if (null === $image->getUpload()) {
                if (null === $fallback) {
                    continue;
                }
                $fallback->addImage($image);
            }
            $item->detachImage($image);
        }
    }

    /**
     * Mängel und Exemplar-Merkmale des hochgeladenen Stücks (aus der Erkennung), gespeichert am Upload.
     *
     * @return array<string, mixed>
     */
    private function specimenData(Item $item): array
    {
        return [
            'title' => $item->getTitle(),
            'defects' => $item->getDefects(),
            'attributes' => $this->specimenAttributes($item),
            'aiConfidence' => $item->getAiConfidence(),
        ];
    }

    /** @return array<string, string|null> */
    private function specimenAttributes(Item $item): array
    {
        $values = [];
        foreach ($item->getArea()?->getAttributeDefinitions() ?? [] as $definition) {
            $code = (string) $definition->getCode();
            if (\in_array($code, self::SPECIMEN_ATTRIBUTES, true)) {
                $values[$code] = $item->getAttributeValue($code);
            }
        }

        return $values;
    }

    /** @param array<mixed> $values Code => Wert, nur Exemplar-Merkmale */
    private function applyAttributes(Item $item, array $values): void
    {
        foreach ($item->getArea()?->getAttributeDefinitions() ?? [] as $definition) {
            $code = (string) $definition->getCode();
            if (\in_array($code, self::SPECIMEN_ATTRIBUTES, true) && \array_key_exists($code, $values)) {
                $item->setAttributeValue($definition, \is_string($values[$code]) ? $values[$code] : null);
            }
        }
    }

    /** Lagerort und Notizen des aufgelösten Objekts gehen nicht verloren (nur bei Bestandsobjekten belegt). */
    private function keepNotes(Item $source, Item $target): void
    {
        $notes = array_filter([
            null !== $source->getStorageLocation() ? 'Lagerort: '.$source->getStorageLocation() : null,
            $source->getInternalNotes(),
        ]);
        if ([] !== $notes) {
            $line = sprintf('Aus #%d (als Duplikat zugeordnet): %s', $source->getId(), implode('; ', $notes));
            $target->setInternalNotes(trim(($target->getInternalNotes() ?? '')."\n".$line));
        }
    }
}
