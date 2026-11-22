<?php

namespace App\Media;

/**
 * Wahrnehmungs-Hash (dHash, 64 Bit) eines Fotos, berechnet mit GD.
 *
 * Das Bild wird auf 9 × 8 Graustufen-Pixel verkleinert; jedes Bit sagt, ob ein Pixel heller ist als sein
 * rechter Nachbar. Ähnliche Fotos haben einen kleinen Hamming-Abstand (0 = gleich, 64 = gegensätzlich),
 * unabhängig von Größe, Kompression und leichter Änderung der Belichtung.
 *
 * Grenze: Wird dasselbe Stück auf anderem Untergrund oder schräg neu fotografiert, ist der Abstand oft groß.
 * Der Hash ist deshalb nur ein Signal für die Vorauswahl der Duplikat-Kandidaten – entscheiden muss der KI-Vergleich.
 */
final class ImageHasher
{
    /** Standardabweichung der Helligkeit, unter der ein Bild als einfarbig gilt (kein aussagekräftiger Hash) */
    private const MIN_CONTRAST = 4.0;

    /** @return string|null 16 Hex-Zeichen, null bei einfarbigen Bildern */
    public function hash(\GdImage $image): ?string
    {
        $small = imagecreatetruecolor(9, 8);
        imagecopyresampled($small, $image, 0, 0, 0, 0, 9, 8, imagesx($image), imagesy($image));

        $luma = [];
        $sum = 0.0;
        for ($y = 0; $y < 8; ++$y) {
            for ($x = 0; $x < 9; ++$x) {
                $rgb = (int) imagecolorat($small, $x, $y);
                $value = 0.299 * (($rgb >> 16) & 0xFF) + 0.587 * (($rgb >> 8) & 0xFF) + 0.114 * ($rgb & 0xFF);
                $luma[$y][$x] = $value;
                $sum += $value;
            }
        }

        $mean = $sum / 72;
        $variance = 0.0;
        foreach ($luma as $row) {
            foreach ($row as $value) {
                $variance += ($value - $mean) ** 2;
            }
        }
        if (sqrt($variance / 72) < self::MIN_CONTRAST) {
            return null;
        }

        $hex = '';
        foreach ($luma as $row) {
            $byte = 0;
            for ($x = 0; $x < 8; ++$x) {
                $byte = ($byte << 1) | ($row[$x] > $row[$x + 1] ? 1 : 0);
            }
            $hex .= sprintf('%02x', $byte);
        }

        return $hex;
    }

    /**
     * Hashes des Bildes in allen vier Drehungen (0°, 90°, 180°, 270°) – für den Vergleich mit Fotos,
     * die beim nächsten Upload anders herum aufgenommen wurden.
     *
     * @return list<string>
     */
    public function rotatedHashes(\GdImage $image): array
    {
        $hashes = [];
        foreach ([0, 90, 180, 270] as $angle) {
            $rotated = 0 === $angle ? $image : imagerotate($image, $angle, 0);
            if (false === $rotated) {
                continue;
            }
            $hash = $this->hash($rotated);
            if (null !== $hash && !\in_array($hash, $hashes, true)) {
                $hashes[] = $hash;
            }
        }

        return $hashes;
    }

    /** Hamming-Abstand zweier Hashes (Anzahl unterschiedlicher Bits, 0–64) */
    public static function distance(string $a, string $b): int
    {
        if (!self::isValid($a) || !self::isValid($b)) {
            return 64;
        }
        $distance = 0;
        for ($i = 0; $i < 16; ++$i) {
            $bits = \intval($a[$i], 16) ^ \intval($b[$i], 16);
            while ($bits > 0) {
                $distance += $bits & 1;
                $bits >>= 1;
            }
        }

        return $distance;
    }

    public static function isValid(string $hash): bool
    {
        return (bool) preg_match('/^[0-9a-f]{16}$/', $hash);
    }
}
