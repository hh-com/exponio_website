<?php

namespace App\Media;

/**
 * Bildausschnitt in Anteilen (0–1) von Breite und Höhe, Ursprung oben links.
 *
 * Gespeichert wird er gegenüber dem Original nach der EXIF-Ausrichtung und vor der Drehung (ItemImage::$crop).
 * Die Prüfseite schickt dagegen den Ausschnitt des angezeigten Bildes, das schon gedreht und vielleicht
 * zugeschnitten ist: unrotate() und compose() rechnen ihn auf das Original um.
 */
final readonly class CropBox
{
    /** kleinster Ausschnitt: 2 % von Breite bzw. Höhe */
    public const MIN_SIZE = 0.02;
    private const PRECISION = 6;

    private function __construct(
        public float $x,
        public float $y,
        public float $w,
        public float $h,
    ) {
    }

    /**
     * Auf das Bild begrenzt. Fehlende oder nicht numerische Werte und zu kleine Ausschnitte lösen eine Ausnahme aus.
     *
     * @param array<mixed> $data {x, y, w, h}
     *
     * @throws \InvalidArgumentException
     */
    public static function fromArray(array $data): self
    {
        foreach (['x', 'y', 'w', 'h'] as $key) {
            if (!is_numeric($data[$key] ?? null)) {
                throw new \InvalidArgumentException('Ungültiger Bildausschnitt.');
            }
        }
        $x = self::clamp((float) $data['x'], 0, 1);
        $y = self::clamp((float) $data['y'], 0, 1);
        $w = self::clamp((float) $data['w'], 0, 1 - $x);
        $h = self::clamp((float) $data['h'], 0, 1 - $y);
        if ($w < self::MIN_SIZE || $h < self::MIN_SIZE) {
            throw new \InvalidArgumentException('Der Bildausschnitt ist zu klein.');
        }

        return new self(round($x, self::PRECISION), round($y, self::PRECISION), round($w, self::PRECISION), round($h, self::PRECISION));
    }

    /** @return array{x: float, y: float, w: float, h: float} */
    public function toArray(): array
    {
        return ['x' => $this->x, 'y' => $this->y, 'w' => $this->w, 'h' => $this->h];
    }

    /** Ausschnitt umfasst (fast) das ganze Bild: nichts zu tun */
    public function isFull(): bool
    {
        return $this->x < 0.001 && $this->y < 0.001 && $this->w > 0.999 && $this->h > 0.999;
    }

    /**
     * Ausschnitt eines um $rotation Grad im Uhrzeigersinn gedrehten Bildes → derselbe Ausschnitt im ungedrehten Bild.
     */
    public function unrotate(int $rotation): self
    {
        return match (MediaStorage::normalizeRotation($rotation)) {
            90 => self::fromArray(['x' => $this->y, 'y' => 1 - $this->x - $this->w, 'w' => $this->h, 'h' => $this->w]),
            180 => self::fromArray(['x' => 1 - $this->x - $this->w, 'y' => 1 - $this->y - $this->h, 'w' => $this->w, 'h' => $this->h]),
            270 => self::fromArray(['x' => 1 - $this->y - $this->h, 'y' => $this->x, 'w' => $this->h, 'h' => $this->w]),
            default => $this,
        };
    }

    /** $inner liegt innerhalb dieses Ausschnitts → Ausschnitt des ganzen Bildes */
    public function compose(self $inner): self
    {
        return self::fromArray([
            'x' => $this->x + $inner->x * $this->w,
            'y' => $this->y + $inner->y * $this->h,
            'w' => $inner->w * $this->w,
            'h' => $inner->h * $this->h,
        ]);
    }

    /** @return array{x: int, y: int, width: int, height: int} Pixelrechteck in einem Bild der Größe $width × $height */
    public function pixels(int $width, int $height): array
    {
        $x = min($width - 1, (int) round($this->x * $width));
        $y = min($height - 1, (int) round($this->y * $height));

        return [
            'x' => $x,
            'y' => $y,
            'width' => max(1, min($width - $x, (int) round($this->w * $width))),
            'height' => max(1, min($height - $y, (int) round($this->h * $height))),
        ];
    }

    private static function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }
}
