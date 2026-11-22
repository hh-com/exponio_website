<?php

namespace App\Entity;

use App\Enum\ImageSide;
use App\Translation\TranslatableInterface;
use App\Translation\TranslatableTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Ein Foto eines Objekts. Gespeichert unter public/media/<path>.webp plus Varianten -800 und -400.
 * Der Pfad enthält einen Inhalts-Hash, damit Browser die Datei ein Jahr lang cachen dürfen.
 *
 * Jedes Foto gehört zu dem Upload, aus dem es stammt (ItemUpload). Ist $item null, ist das Foto ausgeblendet:
 * ein früheres, durch bessere Fotos ersetztes Bild oder ein nicht übernommenes Foto eines Duplikats.
 *
 * @implements TranslatableInterface<ItemImageTranslation>
 */
#[ORM\Entity]
class ItemImage implements TranslatableInterface
{
    /** @use TranslatableTrait<ItemImageTranslation> */
    use TranslatableTrait;

    public const SIZES = [400, 800];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Objekt, auf dessen Seite das Foto erscheint; null = ausgeblendet (gehört nur noch zu seinem Upload) */
    #[ORM\ManyToOne(inversedBy: 'images')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?Item $item = null;

    #[ORM\ManyToOne(inversedBy: 'images')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?ItemUpload $upload = null;

    /** Wahrnehmungs-Hash (dHash, 16 Hex-Zeichen) für die Duplikat-Erkennung, siehe App\Media\ImageHasher */
    #[ORM\Column(length: 16, nullable: true)]
    private ?string $hash = null;

    /** Pfad relativ zu public/media ohne Endung, z. B. items/2026-11-20_211530/01-3f9a2c1d */
    #[ORM\Column(length: 190)]
    private ?string $path = null;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column]
    private int $width = 0;

    #[ORM\Column]
    private int $height = 0;

    #[ORM\Column(length: 10, nullable: true, enumType: ImageSide::class)]
    private ?ImageSide $side = null;

    /** Originaldatei relativ zum Upload-Ordner der App (SCAN_DIR), z. B. 2026-11-20_211530/01.webp */
    #[ORM\Column(length: 190, nullable: true)]
    private ?string $sourceFile = null;

    /**
     * Im Backend gesetzte Drehung gegenüber dem Original (Grad im Uhrzeigersinn: 0, 90, 180, 270),
     * damit app:media:rebuild sie beibehält. Ohne Original (im Backend hochgeladen) steckt die Drehung
     * direkt in den Webbildern und der Wert bleibt 0. Siehe App\Media\ImageRotator.
     */
    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $rotation = 0;

    /** @var Collection<string, ItemImageTranslation> */
    #[ORM\OneToMany(targetEntity: ItemImageTranslation::class, mappedBy: 'image', cascade: ['persist', 'remove'], orphanRemoval: true, indexBy: 'locale')]
    private Collection $translations;

    /** Nur für Uploads im Backend, nicht gespeichert */
    #[Assert\Image(maxSize: '25M', mimeTypes: ['image/jpeg', 'image/png', 'image/webp', 'image/avif'])]
    private ?UploadedFile $file = null;

    /** Nur für das Objekt-Formular im Backend, nicht gespeichert: beim Speichern so weit drehen (Grad im Uhrzeigersinn) */
    private int $rotate = 0;

    public function __construct()
    {
        $this->translations = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    protected function createTranslation(string $locale): ItemImageTranslation
    {
        return new ItemImageTranslation($this, $locale);
    }

    /** Relativer Pfad für asset(): media/…-800.webp; ohne Größe die große Variante */
    public function getRelativePath(?int $size = null): string
    {
        return 'media/'.$this->path.(null !== $size ? '-'.$size : '').'.webp';
    }

    /** Kurze Bildbeschreibung für Screenreader (alt-Attribut) */
    public function getAlt(?string $locale = null): ?string
    {
        return $this->translated('alt', $locale);
    }

    public function setAlt(?string $alt): static
    {
        $this->setSourceText('alt', null === $alt ? null : mb_substr($alt, 0, 255));

        return $this;
    }

    public function getItem(): ?Item
    {
        return $this->item;
    }

    public function setItem(?Item $item): static
    {
        $this->item = $item;

        return $this;
    }

    public function getUpload(): ?ItemUpload
    {
        return $this->upload;
    }

    public function setUpload(?ItemUpload $upload): static
    {
        $this->upload = $upload;

        return $this;
    }

    public function getHash(): ?string
    {
        return $this->hash;
    }

    public function setHash(?string $hash): static
    {
        $this->hash = $hash;

        return $this;
    }

    public function getPath(): ?string
    {
        return $this->path;
    }

    public function setPath(?string $path): static
    {
        $this->path = $path;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(?int $position): static
    {
        $this->position = $position ?? 0;

        return $this;
    }

    public function getWidth(): int
    {
        return $this->width;
    }

    public function getHeight(): int
    {
        return $this->height;
    }

    public function setDimensions(int $width, int $height): static
    {
        $this->width = $width;
        $this->height = $height;

        return $this;
    }

    public function getSide(): ?ImageSide
    {
        return $this->side;
    }

    public function setSide(?ImageSide $side): static
    {
        $this->side = $side;

        return $this;
    }

    public function getSourceFile(): ?string
    {
        return $this->sourceFile;
    }

    public function setSourceFile(?string $sourceFile): static
    {
        $this->sourceFile = $sourceFile;

        return $this;
    }

    public function getRotation(): int
    {
        return $this->rotation;
    }

    public function setRotation(int $rotation): static
    {
        $this->rotation = $rotation;

        return $this;
    }

    public function getRotate(): int
    {
        return $this->rotate;
    }

    public function setRotate(?int $rotate): static
    {
        $this->rotate = $rotate ?? 0;

        return $this;
    }

    public function getFile(): ?UploadedFile
    {
        return $this->file;
    }

    public function setFile(?UploadedFile $file): static
    {
        $this->file = $file;

        return $this;
    }
}
