<?php

/**
 * Erzeugt das mitgelieferte Standardlogo: Logo, Favicons, App-Symbole und das Vorschaubild (Open Graph)
 * aus .spec/design/stamp_logo.webp. Einmal ausführen, wenn sich das Standardlogo ändert:
 *
 *   php bin/build-brand-assets.php
 *
 * Ergebnis: public/brand/* (wird eingecheckt). Ein eigenes Logo lädst du im Backend hoch
 * (Einstellungen → Website → Logo, siehe App\Media\BrandAssets); diese Dateien gelten dann nicht mehr.
 * Das Web-App-Manifest erzeugt die Website selbst (/site.webmanifest, Name aus den Einstellungen).
 */
$root = dirname(__DIR__);
$source = $root.'/.spec/design/stamp_logo.webp';
$target = $root.'/public/brand';

if (!is_file($source)) {
    fwrite(STDERR, "Logo-Quelle fehlt: $source (Maintainer-Skript, die fertigen Dateien liegen in public/brand/)\n");
    exit(1);
}
if (!function_exists('imagecreatefromwebp')) {
    fwrite(STDERR, "Die PHP-Erweiterung GD mit WebP-Unterstützung fehlt.\n");
    exit(1);
}
$logo = imagecreatefromwebp($source);
if (false === $logo) {
    fwrite(STDERR, "Logo nicht lesbar: $source\n");
    exit(1);
}
@mkdir($target, 0775, true);

// Das Logo steht mittig auf dunklem Grund: quadratischen Ausschnitt um die Briefmarke nehmen
$size = 560;
$x = intdiv(imagesx($logo) - $size, 2);
$y = intdiv(imagesy($logo) - $size, 2);

$square = static function (int $edge) use ($logo, $x, $y, $size): GdImage {
    $image = imagecreatetruecolor($edge, $edge);
    imagecopyresampled($image, $logo, 0, 0, $x, $y, $edge, $edge, $size, $size);

    return $image;
};

imagewebp($square(256), $target.'/logo.webp', 90);
imagepng($square(32), $target.'/favicon-32.png', 9);
imagepng($square(180), $target.'/apple-touch-icon.png', 9);
imagepng($square(192), $target.'/icon-192.png', 9);
imagepng($square(512), $target.'/icon-512.png', 9);

// Vorschaubild für soziale Netzwerke: Logo auf dunklem Grund, 1200 × 630
$og = imagecreatetruecolor(1200, 630);
imagefilledrectangle($og, 0, 0, 1200, 630, imagecolorallocate($og, 0x1E, 0x28, 0x35));
imagecopyresampled($og, $logo, 345, 30, $x, $y, 510, 510, $size, $size);
imagejpeg($og, $target.'/og-default.jpg', 85);

echo "Fertig: $target\n";
