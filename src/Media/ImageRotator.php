<?php

namespace App\Media;

use App\Entity\ItemImage;

/**
 * Dreht ein gespeichertes Foto (Backend: Objekt-Formular und „Prüfen & freigeben“), etwa wenn es quer
 * oder auf dem Kopf liegt.
 *
 * - Liegt das Original noch im Upload-Ordner der App, entstehen die Webbilder daraus neu – ohne zusätzlichen
 *   Qualitätsverlust. Die Gesamtdrehung steht in ItemImage::$rotation, damit app:media:rebuild sie beibehält.
 *   Ein Zuschnitt (ItemImage::$crop, siehe ImageCropper) bleibt dabei erhalten.
 * - Ohne Original (im Backend hochgeladen oder Original nicht mehr da) wird die große Webvariante selbst gedreht.
 *
 * Das gedrehte Bild bekommt neue Dateinamen (Browser-Cache), die alten Dateien werden gelöscht.
 * Den Wahrnehmungs-Hash für die Duplikat-Erkennung berechnet MediaStorage::store() neu.
 */
final class ImageRotator
{
    public function __construct(
        private readonly MediaStorage $media,
        private readonly string $scanDir,
    ) {
    }

    /** @param int $degrees im Uhrzeigersinn: 90, 180, 270 (bzw. -90 für gegen den Uhrzeigersinn) */
    public function rotate(ItemImage $image, int $degrees): void
    {
        $degrees = MediaStorage::normalizeRotation($degrees);
        $old = $image->getPath();
        if (0 === $degrees || null === $old) {
            return;
        }
        MediaStorage::raiseMemoryLimit();

        $base = MediaStorage::stripContentHash($old);
        // Gesamtdrehung gegenüber dem Original; ohne Original gibt es nichts, worauf sie sich beziehen könnte
        $rotation = null === $image->getSourceFile() ? 0 : MediaStorage::normalizeRotation($image->getRotation() + $degrees);
        $original = null === $image->getSourceFile() ? null : $this->scanDir.'/'.$image->getSourceFile();

        if (null !== $original && is_file($original)) {
            $saved = $this->media->store($original, $base, $rotation, $image->getCrop());
        } else {
            $current = $this->media->absolutePath($old);
            if (!is_file($current)) {
                throw new \RuntimeException(sprintf('Bilddatei "%s" fehlt.', $old));
            }
            $saved = $this->media->store($current, $base, $degrees);
        }

        $image->setPath($saved['path'])
            ->setDimensions($saved['width'], $saved['height'])
            ->setHash($saved['hash'])
            ->setRotation($rotation);
        if ($saved['path'] !== $old) {
            $this->media->delete($old);
        }
    }
}
