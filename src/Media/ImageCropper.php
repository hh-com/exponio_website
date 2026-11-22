<?php

namespace App\Media;

use App\Entity\ItemImage;

/**
 * Schneidet ein gespeichertes Foto zu („Prüfen & freigeben“), etwa wenn neben der Marke Albumtext zu sehen ist,
 * den die KI sonst mitliest. Die KI-Erkennung arbeitet mit den Webbildern, also danach mit dem Ausschnitt.
 *
 * - Liegt das Original noch im Upload-Ordner der App, entstehen die Webbilder daraus neu. Der Ausschnitt steht
 *   gegenüber dem Original in ItemImage::$crop: Er bleibt beim Drehen und bei app:media:rebuild erhalten und
 *   lässt sich mit reset() wieder aufheben.
 * - Ohne Original (im Backend hochgeladen) wird die große Webvariante selbst zugeschnitten, endgültig.
 *
 * Wie beim Drehen bekommt das Bild neue Dateinamen, die alten Dateien werden gelöscht.
 */
final class ImageCropper
{
    public function __construct(
        private readonly MediaStorage $media,
        private readonly string $scanDir,
    ) {
    }

    /** @param CropBox $box Ausschnitt des Bildes, wie es gerade angezeigt wird (gedreht, vielleicht schon zugeschnitten) */
    public function crop(ItemImage $image, CropBox $box): void
    {
        $old = $image->getPath();
        if ($box->isFull() || null === $old) {
            return;
        }
        $base = MediaStorage::stripContentHash($old);
        $crop = null;
        if (null !== $image->getSourceFile()) {
            // auch ohne vorhandene Originaldatei mitrechnen, damit ein späteres app:media:rebuild denselben Ausschnitt ergibt
            $inner = $box->unrotate($image->getRotation());
            $crop = $image->getCrop()?->compose($inner) ?? $inner;
        }
        $original = $this->original($image);
        $saved = null !== $original
            ? $this->media->store($original, $base, $image->getRotation(), $crop)
            : $this->media->store($this->current($old), $base, 0, $box);

        $this->apply($image, $saved, $crop, $old);
    }

    /** Zuschnitt aufheben: Webbilder wieder aus dem ganzen Original (mit der Drehung) */
    public function reset(ItemImage $image): void
    {
        $old = $image->getPath();
        if (null === $image->getCrop() || null === $old) {
            return;
        }
        $original = $this->original($image);
        if (null === $original) {
            throw new \RuntimeException('Das Original im Upload-Ordner fehlt, deshalb lässt sich der Zuschnitt nicht aufheben.');
        }
        $saved = $this->media->store($original, MediaStorage::stripContentHash($old), $image->getRotation());

        $this->apply($image, $saved, null, $old);
    }

    private function original(ItemImage $image): ?string
    {
        $file = null === $image->getSourceFile() ? null : $this->scanDir.'/'.$image->getSourceFile();

        return null !== $file && is_file($file) ? $file : null;
    }

    private function current(string $path): string
    {
        $file = $this->media->absolutePath($path);
        if (!is_file($file)) {
            throw new \RuntimeException(sprintf('Bilddatei "%s" fehlt.', $path));
        }

        return $file;
    }

    /** @param array{path: string, width: int, height: int, hash: string|null} $saved */
    private function apply(ItemImage $image, array $saved, ?CropBox $crop, string $old): void
    {
        $image->setPath($saved['path'])
            ->setDimensions($saved['width'], $saved['height'])
            ->setHash($saved['hash'])
            ->setCrop($crop);
        if ($saved['path'] !== $old) {
            $this->media->delete($old);
        }
    }
}
