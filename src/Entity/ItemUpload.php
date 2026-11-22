<?php

namespace App\Entity;

use App\Enum\Specimen;
use App\Enum\UploadResult;
use App\Repository\ItemUploadRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ein Upload (Ordner der App) eines Objekts – die Upload-Historie. Das erste Hochladen legt das Objekt an,
 * jedes weitere erkannte Duplikat kommt als neuer Eintrag hinzu. Daraus ergibt sich der Zähler Item::$uploadCount.
 *
 * Die Fotos eines Uploads bleiben immer erhalten: Entweder werden sie auf der Website gezeigt (ItemImage::$item gesetzt),
 * oder sie sind ausgeblendet („früher“ bzw. „nicht übernommen“). So lassen sich ersetzte Bilder jederzeit zurückholen.
 */
#[ORM\Entity(repositoryClass: ItemUploadRepository::class)]
#[ORM\Index(name: 'idx_item_upload_result', columns: ['result', 'uploaded_at'])]
class ItemUpload
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'uploads')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Item $item;

    /** Ordnername im Upload-Verzeichnis, null bei Objekten aus dem Backend */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $folder = null;

    #[ORM\Column]
    private \DateTimeImmutable $uploadedAt;

    #[ORM\Column(length: 20, enumType: UploadResult::class)]
    private UploadResult $result = UploadResult::New;

    /** Von Hand zugeordnet (Prüfen & freigeben) statt automatisch */
    #[ORM\Column(options: ['default' => false])]
    private bool $manual = false;

    #[ORM\Column(length: 10, nullable: true, enumType: Specimen::class)]
    private ?Specimen $specimen = null;

    /** Sicherheit der KI, dass es dasselbe Objekt ist */
    #[ORM\Column(nullable: true)]
    private ?float $confidence = null;

    /** Ist das neue Exemplar bzw. sind die neuen Fotos besser (Erhaltung zuerst, dann Fotoqualität)? */
    #[ORM\Column(nullable: true)]
    private ?bool $better = null;

    /** Die Fotos dieses Uploads haben die vorherigen ersetzt (zuletzt rückgängig gemacht: false) */
    #[ORM\Column(options: ['default' => false])]
    private bool $imagesReplaced = false;

    /** Bessere Fotos, die auf Bestätigung warten (veröffentlichte Objekte) */
    #[ORM\Column(options: ['default' => false])]
    private bool $proposal = false;

    /** Begründung der KI (oder Hinweis bei manueller Zuordnung) */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $reason = null;

    /** @var array<string, mixed>|null Antwort der KI für diesen Kandidaten (ComparisonMatch::toArray()) */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $comparison = null;

    /** @var array<string, mixed>|null Bestimmung des hochgeladenen Exemplars: Mängel und Exemplar-Merkmale */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $recognized = null;

    /** @var array<string, mixed>|null Zustand vor dem Ersetzen der Bilder (für „Rückgängig“) */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $snapshot = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $undoneAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, ItemImage> alle Fotos dieses Uploads, angezeigt oder ausgeblendet */
    #[ORM\OneToMany(targetEntity: ItemImage::class, mappedBy: 'upload', cascade: ['persist', 'remove'])]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $images;

    public function __construct(Item $item, ?string $folder = null, ?\DateTimeImmutable $uploadedAt = null)
    {
        $this->item = $item;
        $this->folder = $folder;
        $this->createdAt = new \DateTimeImmutable();
        $this->uploadedAt = $uploadedAt ?? (null !== $folder ? self::dateFromFolder($folder) : null) ?? $this->createdAt;
        $this->images = new ArrayCollection();
    }

    /** Zeitpunkt aus dem Ordnernamen der App (2026-11-20_211530) */
    public static function dateFromFolder(string $folder): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d_His', substr($folder, 0, 17));

        return false === $date ? null : $date;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getItem(): Item
    {
        return $this->item;
    }

    /** Nur für Item::addUpload() – ein Upload wandert beim Zusammenführen zum vorhandenen Objekt. */
    public function setItem(Item $item): static
    {
        $this->item = $item;

        return $this;
    }

    public function getFolder(): ?string
    {
        return $this->folder;
    }

    public function setFolder(?string $folder): static
    {
        $this->folder = $folder;

        return $this;
    }

    public function getUploadedAt(): \DateTimeImmutable
    {
        return $this->uploadedAt;
    }

    public function getResult(): UploadResult
    {
        return $this->result;
    }

    public function setResult(UploadResult $result): static
    {
        $this->result = $result;

        return $this;
    }

    public function isDuplicate(): bool
    {
        return UploadResult::Duplicate === $this->result;
    }

    public function isManual(): bool
    {
        return $this->manual;
    }

    public function setManual(bool $manual): static
    {
        $this->manual = $manual;

        return $this;
    }

    public function getSpecimen(): ?Specimen
    {
        return $this->specimen;
    }

    public function setSpecimen(?Specimen $specimen): static
    {
        $this->specimen = $specimen;

        return $this;
    }

    public function getConfidence(): ?float
    {
        return $this->confidence;
    }

    public function setConfidence(?float $confidence): static
    {
        $this->confidence = $confidence;

        return $this;
    }

    public function isBetter(): ?bool
    {
        return $this->better;
    }

    public function setBetter(?bool $better): static
    {
        $this->better = $better;

        return $this;
    }

    public function isImagesReplaced(): bool
    {
        return $this->imagesReplaced;
    }

    public function setImagesReplaced(bool $imagesReplaced): static
    {
        $this->imagesReplaced = $imagesReplaced;

        return $this;
    }

    public function isProposal(): bool
    {
        return $this->proposal;
    }

    public function setProposal(bool $proposal): static
    {
        $this->proposal = $proposal;

        return $this;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function setReason(?string $reason): static
    {
        $this->reason = $reason;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getComparison(): ?array
    {
        return $this->comparison;
    }

    /** @param array<string, mixed>|null $comparison */
    public function setComparison(?array $comparison): static
    {
        $this->comparison = $comparison;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getRecognized(): ?array
    {
        return $this->recognized;
    }

    /** @param array<string, mixed>|null $recognized */
    public function setRecognized(?array $recognized): static
    {
        $this->recognized = $recognized;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getSnapshot(): ?array
    {
        return $this->snapshot;
    }

    /** @param array<string, mixed>|null $snapshot */
    public function setSnapshot(?array $snapshot): static
    {
        $this->snapshot = $snapshot;

        return $this;
    }

    public function getUndoneAt(): ?\DateTimeImmutable
    {
        return $this->undoneAt;
    }

    public function setUndoneAt(?\DateTimeImmutable $undoneAt): static
    {
        $this->undoneAt = $undoneAt;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, ItemImage> */
    public function getImages(): Collection
    {
        return $this->images;
    }

    public function addImage(ItemImage $image): static
    {
        if (!$this->images->contains($image)) {
            $this->images->add($image);
        }
        $image->setUpload($this);

        return $this;
    }

    /** Werden die Fotos dieses Uploads gerade auf der Website gezeigt? */
    public function isShown(): bool
    {
        foreach ($this->images as $image) {
            if (null !== $image->getItem()) {
                return true;
            }
        }

        return false;
    }

    /** Lässt sich das Ersetzen der Bilder zurücknehmen? */
    public function canUndo(): bool
    {
        return $this->imagesReplaced && null !== $this->snapshot && $this->isShown();
    }
}
