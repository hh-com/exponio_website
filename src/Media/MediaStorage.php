<?php

namespace App\Media;

use App\Entity\ItemImage;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Speichert Fotos web-optimiert als WebP unter public/media/<pfad>.webp (max. 2000 px)
 * plus <pfad>-800.webp und <pfad>-400.webp. Benötigt GD mit WebP-Unterstützung.
 *
 * - Der Pfad endet auf einen Hash des Bildinhalts (z. B. 01-3f9a2c1d). Ein neues Bild bekommt
 *   dadurch eine neue Adresse, und Browser dürfen die Dateien ein Jahr lang cachen.
 * - Das Neu-Kodieren mit GD entfernt alle Metadaten (EXIF, GPS-Position, Kameradaten).
 * - Zu jedem Bild entsteht ein Wahrnehmungs-Hash (ImageHasher) aus der 400-px-Variante – für die Duplikat-Erkennung.
 */
final class MediaStorage
{
    private const MAX_EDGE = 2000;
    private const QUALITY = [0 => 86, 800 => 82, 400 => 78];
    /** Aus dieser Variante wird der Wahrnehmungs-Hash berechnet (beim Speichern und beim Nachberechnen gleich) */
    private const HASH_SIZE = 400;

    private readonly Filesystem $fs;

    public function __construct(
        private readonly string $mediaDir,
        private readonly ImageHasher $hasher = new ImageHasher(),
    ) {
        $this->fs = new Filesystem();
    }

    /**
     * @param string $base Zielpfad relativ zu media/ ohne Endung, z. B. items/2026-11-20_211530/01
     *
     * @return array{path: string, width: int, height: int, hash: string|null} path = $base plus Inhalts-Hash, hash = Wahrnehmungs-Hash
     */
    public function store(string $sourceFile, string $base): array
    {
        if (!\function_exists('imagewebp')) {
            throw new \RuntimeException('Die PHP-Erweiterung GD mit WebP-Unterstützung fehlt.');
        }
        self::raiseMemoryLimit();

        $data = @file_get_contents($sourceFile);
        $image = false === $data ? false : @imagecreatefromstring($data);
        if (false === $image) {
            throw new \RuntimeException(sprintf('Bild "%s" kann nicht gelesen werden.', basename($sourceFile)));
        }
        $image = $this->applyExifOrientation($image, $sourceFile);
        $base .= '-'.substr((string) sha1_file($sourceFile), 0, 8);
        if (!imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        $this->fs->mkdir(\dirname($this->mediaDir.'/'.$base));

        $large = $this->resize($image, self::MAX_EDGE);
        $this->writeWebp($large, $this->mediaDir.'/'.$base.'.webp', self::QUALITY[0]);
        $result = ['path' => $base, 'width' => imagesx($large), 'height' => imagesy($large), 'hash' => null];

        foreach (ItemImage::SIZES as $size) {
            $variant = $this->resize($large, $size);
            $this->writeWebp($variant, $this->mediaDir.'/'.$base.'-'.$size.'.webp', self::QUALITY[$size]);
            if (self::HASH_SIZE === $size) {
                $result['hash'] = $this->hasher->hash($variant);
            }
        }

        return $result;
    }

    public function delete(string $base): void
    {
        $files = [$this->mediaDir.'/'.$base.'.webp'];
        foreach (ItemImage::SIZES as $size) {
            $files[] = $this->mediaDir.'/'.$base.'-'.$size.'.webp';
        }
        $this->fs->remove($files);

        // leere Ordner aufräumen
        $dir = \dirname($this->mediaDir.'/'.$base);
        if (is_dir($dir) && [] === array_diff(scandir($dir) ?: [], ['.', '..'])) {
            @rmdir($dir);
        }
    }

    public function absolutePath(string $base, ?int $size = null): string
    {
        return $this->mediaDir.'/'.$base.(null !== $size ? '-'.$size : '').'.webp';
    }

    /** Base64-Daten der großen Variante (Bilderkennung) oder einer kleineren (Duplikat-Vergleich) */
    public function base64(string $base, ?int $size = null): string
    {
        $data = @file_get_contents($this->absolutePath($base, $size));
        if (false === $data && null !== $size) {
            $data = @file_get_contents($this->absolutePath($base));
        }
        if (false === $data) {
            throw new \RuntimeException(sprintf('Bilddatei "%s" fehlt.', $base));
        }

        return base64_encode($data);
    }

    /** Wahrnehmungs-Hash eines gespeicherten Bildes (für Bilder, die vor der Duplikat-Erkennung entstanden sind) */
    public function hash(string $base): ?string
    {
        $image = $this->load($base);

        return null === $image ? null : $this->hasher->hash($image);
    }

    /**
     * Hashes eines gespeicherten Bildes in allen vier Drehungen.
     *
     * @return list<string>
     */
    public function rotatedHashes(string $base): array
    {
        $image = $this->load($base);

        return null === $image ? [] : $this->hasher->rotatedHashes($image);
    }

    private function load(string $base): ?\GdImage
    {
        foreach ([$this->absolutePath($base, self::HASH_SIZE), $this->absolutePath($base)] as $file) {
            $data = is_file($file) ? @file_get_contents($file) : false;
            $image = false === $data ? false : @imagecreatefromstring($data);
            if (false !== $image) {
                return $image;
            }
        }

        return null;
    }

    private function resize(\GdImage $image, int $maxEdge): \GdImage
    {
        $w = imagesx($image);
        $h = imagesy($image);
        $scale = $maxEdge / max($w, $h);
        if ($scale >= 1) {
            return $image; // nie vergrößern
        }
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $target = imagecreatetruecolor($nw, $nh);
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagecopyresampled($target, $image, 0, 0, 0, 0, $nw, $nh, $w, $h);

        return $target;
    }

    private function writeWebp(\GdImage $image, string $file, int $quality): void
    {
        imagesavealpha($image, true);
        if (!imagewebp($image, $file, $quality)) {
            throw new \RuntimeException(sprintf('"%s" konnte nicht geschrieben werden.', $file));
        }
    }

    private function applyExifOrientation(\GdImage $image, string $file): \GdImage
    {
        if (!\function_exists('exif_read_data')) {
            return $image;
        }
        $exif = @exif_read_data($file);
        $orientation = \is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };

        return false === $rotated ? $image : $rotated;
    }

    /** Große Fotos brauchen als GD-Bild viel Speicher (4 Byte je Pixel plus Kopien) */
    public static function raiseMemoryLimit(): void
    {
        $limit = \ini_get('memory_limit');
        if ('-1' === $limit) {
            return;
        }
        $bytes = (int) $limit * match (strtolower(substr($limit, -1))) {
            'g' => 1024 ** 3,
            'm' => 1024 ** 2,
            'k' => 1024,
            default => 1,
        };
        if ($bytes < 512 * 1024 ** 2) {
            @ini_set('memory_limit', '512M');
        }
    }
}
