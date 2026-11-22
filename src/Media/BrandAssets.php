<?php

namespace App\Media;

use App\Service\SettingsProvider;
use Symfony\Component\Asset\Packages;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Logo der Website und die daraus erzeugten Dateien: Favicon, App-Symbole, Vorschaubild für soziale Netzwerke.
 *
 * - Ohne eigenes Logo gelten die mitgelieferten Dateien in public/brand/ (erzeugt von bin/build-brand-assets.php).
 * - Ein im Backend hochgeladenes Logo wird mittig quadratisch zugeschnitten und als media/brand/logo-<hash>…
 *   in allen Größen gespeichert. Der Inhalts-Hash im Namen sorgt dafür, dass Browser nach einem Wechsel
 *   sofort das neue Logo laden (Dateien unter /media/ werden ein Jahr gecacht).
 * - Die Dateien liegen im Medienordner (MEDIA_DIR), damit ein Update der Website sie nicht überschreibt.
 */
final class BrandAssets
{
    /** Variante => [mitgelieferte Datei, Endung der Datei eines eigenen Logos] */
    private const VARIANTS = [
        'logo' => ['brand/logo.webp', '.webp'],
        'favicon' => ['brand/favicon-32.png', '-32.png'],
        'apple-touch-icon' => ['brand/apple-touch-icon.png', '-180.png'],
        'icon-192' => ['brand/icon-192.png', '-192.png'],
        'icon-512' => ['brand/icon-512.png', '-512.png'],
        'og' => ['brand/og-default.jpg', '-og.jpg'],
    ];

    /** Grund der Logo-Kachel (.logo-tile im CSS): für Bilder ohne Transparenz (iOS-Symbol, Vorschaubild) */
    private const TILE_COLOR = [0x1E, 0x28, 0x35];

    private readonly Filesystem $fs;

    public function __construct(
        private readonly string $mediaDir,
        private readonly SettingsProvider $settings,
        private readonly Packages $packages,
    ) {
        $this->fs = new Filesystem();
    }

    /** Öffentliche Adresse einer Variante des aktuellen Logos (z. B. /media/brand/logo-3f9a2c1d-32.png) */
    public function url(string $variant): string
    {
        return $this->packages->getUrl($this->relativePath($variant));
    }

    public function isCustom(): bool
    {
        return null !== $this->settings->get()->getLogoPath();
    }

    /**
     * Erzeugt alle Varianten aus einer Bilddatei (PNG, JPEG oder WebP).
     *
     * @return string Pfad im Medienordner ohne Endung, z. B. brand/logo-3f9a2c1d (für Settings::setLogoPath())
     */
    public function store(string $sourceFile): string
    {
        if (!\function_exists('imagewebp')) {
            throw new \RuntimeException('Die PHP-Erweiterung GD mit WebP-Unterstützung fehlt.');
        }
        MediaStorage::raiseMemoryLimit();

        $data = @file_get_contents($sourceFile);
        $image = false === $data ? false : @imagecreatefromstring($data);
        if (false === $data || false === $image) {
            throw new \RuntimeException('Das Bild kann nicht gelesen werden. Erlaubt sind PNG, JPEG und WebP.');
        }
        if (!imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        // mittiger quadratischer Ausschnitt
        $edge = min(imagesx($image), imagesy($image));
        $crop = [intdiv(imagesx($image) - $edge, 2), intdiv(imagesy($image) - $edge, 2), $edge];

        $base = 'brand/logo-'.substr(sha1($data), 0, 8);
        $this->fs->mkdir($this->mediaDir.'/brand');
        $file = fn (string $variant): string => $this->mediaDir.'/'.$base.self::VARIANTS[$variant][1];

        $this->check(imagewebp($this->square($image, $crop, 256), $file('logo'), 90), $file('logo'));
        $this->check(imagepng($this->square($image, $crop, 32), $file('favicon'), 9), $file('favicon'));
        // iOS füllt Transparenz schwarz – deshalb auf dem Grund der Logo-Kachel
        $this->check(imagepng($this->square($image, $crop, 180, opaque: true), $file('apple-touch-icon'), 9), $file('apple-touch-icon'));
        $this->check(imagepng($this->square($image, $crop, 192), $file('icon-192'), 9), $file('icon-192'));
        $this->check(imagepng($this->square($image, $crop, 512), $file('icon-512'), 9), $file('icon-512'));

        // Vorschaubild 1200 × 630: Logo mittig auf dem Grund der Logo-Kachel
        $og = $this->canvas(1200, 630, opaque: true);
        imagecopyresampled($og, $image, 345, 60, $crop[0], $crop[1], 510, 510, $crop[2], $crop[2]);
        $this->check(imagejpeg($og, $file('og'), 85), $file('og'));

        return $base;
    }

    /** Löscht alle Dateien eines eigenen Logos (nach einem Wechsel oder beim Zurücksetzen) */
    public function delete(string $base): void
    {
        if (!str_starts_with($base, 'brand/logo-') || str_contains($base, '..')) {
            return; // nur selbst erzeugte Dateien
        }
        $this->fs->remove(array_map(fn (array $v): string => $this->mediaDir.'/'.$base.$v[1], self::VARIANTS));
    }

    private function relativePath(string $variant): string
    {
        if (!isset(self::VARIANTS[$variant])) {
            throw new \InvalidArgumentException(sprintf('Unbekannte Logo-Variante "%s".', $variant));
        }
        $base = $this->settings->get()->getLogoPath();

        return null === $base ? self::VARIANTS[$variant][0] : 'media/'.$base.self::VARIANTS[$variant][1];
    }

    /**
     * @param array{int, int, int} $crop x, y und Kantenlänge des Ausschnitts
     * @param int<1, max>          $edge Kantenlänge des Ergebnisses
     */
    private function square(\GdImage $source, array $crop, int $edge, bool $opaque = false): \GdImage
    {
        $target = $this->canvas($edge, $edge, $opaque);
        imagecopyresampled($target, $source, 0, 0, $crop[0], $crop[1], $edge, $edge, $crop[2], $crop[2]);

        return $target;
    }

    /**
     * @param int<1, max> $width
     * @param int<1, max> $height
     */
    private function canvas(int $width, int $height, bool $opaque): \GdImage
    {
        $image = imagecreatetruecolor($width, $height);
        if ($opaque) {
            imagefill($image, 0, 0, (int) imagecolorallocate($image, ...self::TILE_COLOR));
            imagealphablending($image, true);
        } else {
            imagealphablending($image, false);
            imagesavealpha($image, true);
            imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
        }

        return $image;
    }

    private function check(bool $written, string $file): void
    {
        if (!$written) {
            throw new \RuntimeException(sprintf('"%s" konnte nicht geschrieben werden.', basename($file)));
        }
    }
}
