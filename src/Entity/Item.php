<?php

namespace App\Entity;

use App\Enum\AttributeType;
use App\Enum\DuplicateStatus;
use App\Enum\ImageSide;
use App\Enum\ItemStatus;
use App\Repository\ItemRepository;
use App\Translation\TranslatableInterface;
use App\Translation\TranslatableTrait;
use App\Util\Slug;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Ein Sammlungsobjekt (Briefmarke, Beleg, Münze, …). Ein Upload-Ordner der App ergibt ein Objekt; lädst du
 * dasselbe Objekt noch einmal hoch, ordnet die Duplikat-Erkennung den Upload dem vorhandenen Objekt zu
 * (Upload-Historie in ItemUpload, Zähler $uploadCount).
 * Texte (Titel, Beschreibung, Serie, Mängel) liegen je Sprache in ItemTranslation.
 *
 * @implements TranslatableInterface<ItemTranslation>
 */
#[ORM\Entity(repositoryClass: ItemRepository::class)]
#[ORM\Index(name: 'idx_item_status_published', columns: ['status', 'published_at'])]
#[ORM\Index(name: 'idx_item_year', columns: ['year'])]
#[ORM\Index(name: 'idx_item_upload_count', columns: ['upload_count'])]
#[ORM\HasLifecycleCallbacks]
class Item implements \Stringable, TranslatableInterface
{
    /** @use TranslatableTrait<ItemTranslation> */
    use TranslatableTrait;

    public const AI_HINT_MAX = 2000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20, enumType: ItemStatus::class)]
    private ItemStatus $status = ItemStatus::Draft;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?CollectionArea $area = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Category $category = null;

    /** @var Collection<int, Topic> */
    #[ORM\ManyToMany(targetEntity: Topic::class)]
    #[ORM\JoinTable(name: 'item_topic')]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $topics;

    /** @var Collection<string, ItemTranslation> */
    #[ORM\OneToMany(targetEntity: ItemTranslation::class, mappedBy: 'item', cascade: ['persist', 'remove'], orphanRemoval: true, indexBy: 'locale')]
    #[Assert\Valid]
    private Collection $translations;

    #[ORM\Column(type: 'smallint', nullable: true)]
    #[Assert\Range(min: 1000, max: 2200)]
    private ?int $year = null;

    #[ORM\Column(type: 'smallint', nullable: true)]
    #[Assert\Range(min: 1000, max: 2200)]
    private ?int $yearTo = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $issueDate = null;

    /** Von der KI geschätzter Katalogwert. Nur ein Richtwert, öffentlich nur mit $showEstimate. */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    #[Assert\PositiveOrZero]
    private ?string $catalogValue = null;

    #[ORM\Column]
    private bool $showEstimate = false;

    #[ORM\Column]
    private bool $featured = false;

    /** Ordnername aus dem Upload-Verzeichnis (z. B. 2026-11-20_211530) */
    #[ORM\Column(length: 100, unique: true, nullable: true)]
    private ?string $sourceFolder = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $aiResult = null;

    #[ORM\Column(nullable: true)]
    private ?float $aiConfidence = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $aiModel = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $aiError = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $recognizedAt = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $storageLocation = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $internalNotes = null;

    /**
     * Hinweis an die KI, z. B. „DDR 1953, 24 Pf Karl Marx“ oder „Der Text unter der Marke gehört nicht dazu“.
     * Geht bei jeder Erkennung mit und hat Vorrang vor der Einschätzung der KI (PromptBuilder). Höchstens AI_HINT_MAX Zeichen.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $aiHint = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    /** @var Collection<int, ItemImage> */
    #[ORM\OneToMany(targetEntity: ItemImage::class, mappedBy: 'item', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    #[Assert\Valid]
    private Collection $images;

    /** @var Collection<int, ItemAttribute> */
    #[ORM\OneToMany(targetEntity: ItemAttribute::class, mappedBy: 'item', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $attributeValues;

    /** @var Collection<int, ItemUpload> Upload-Historie, ältester zuerst */
    #[ORM\OneToMany(targetEntity: ItemUpload::class, mappedBy: 'item', cascade: ['persist', 'remove'])]
    #[ORM\OrderBy(['uploadedAt' => 'ASC', 'id' => 'ASC'])]
    private Collection $uploads;

    /** @var Collection<int, RecognitionResult> Ergebnisse je Modell bei der letzten Erkennung, Hauptzeile zuerst */
    #[ORM\OneToMany(targetEntity: RecognitionResult::class, mappedBy: 'item', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $recognitions;

    /** Wie oft das Objekt hochgeladen wurde (Anzahl der ItemUpload-Einträge, gespeichert zum Sortieren und Filtern) */
    #[ORM\Column(options: ['default' => 1])]
    private int $uploadCount = 1;

    #[ORM\Column(length: 10, nullable: true, enumType: DuplicateStatus::class)]
    private ?DuplicateStatus $duplicateStatus = null;

    /** Vorhandenes Objekt, von dem dieses vermutlich ein Duplikat ist (Hinweis in „Prüfen & freigeben“) */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Item $duplicateOf = null;

    #[ORM\Column(nullable: true)]
    private ?float $duplicateConfidence = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $duplicateReason = null;

    /** @var array<string, mixed>|null Antwort der KI zum Kandidaten $duplicateOf (ComparisonMatch::toArray()) */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $duplicateResult = null;

    /** Nicht gespeichert: Upload, mit dem dieses (frisch importierte) Objekt einem vorhandenen zugeordnet wurde */
    private ?ItemUpload $mergedUpload = null;

    public function __construct()
    {
        $this->topics = new ArrayCollection();
        $this->images = new ArrayCollection();
        $this->uploads = new ArrayCollection();
        $this->recognitions = new ArrayCollection();
        $this->attributeValues = new ArrayCollection();
        $this->translations = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return $this->getTitle();
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    /** Jedes Objekt hat mindestens einen Upload-Eintrag; Fotos ohne Upload gehören zum aktuellen. */
    #[ORM\PrePersist]
    public function ensureUpload(): void
    {
        if ($this->uploads->isEmpty()) {
            $this->addUpload(new ItemUpload($this, $this->sourceFolder));
        }
        $current = $this->getCurrentUpload();
        foreach ($this->images as $image) {
            if (null === $image->getUpload() && null !== $current) {
                $current->addImage($image);
            }
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    protected function createTranslation(string $locale): ItemTranslation
    {
        return new ItemTranslation($this, $locale);
    }

    // ---- Texte (ohne Sprache = Deutsch) ---------------------------------------

    #[Assert\NotBlank(message: 'Bitte einen Titel eingeben.')]
    #[Assert\Length(max: 255)]
    public function getTitle(?string $locale = null): string
    {
        return (string) $this->translated('title', $locale);
    }

    public function setTitle(?string $title): static
    {
        $this->setSourceText('title', null === $title ? null : mb_substr($title, 0, 255));

        return $this;
    }

    public function getDescription(?string $locale = null): ?string
    {
        return $this->translated('description', $locale);
    }

    public function setDescription(?string $description): static
    {
        $this->setSourceText('description', $description);

        return $this;
    }

    public function getSeries(?string $locale = null): ?string
    {
        return $this->translated('series', $locale);
    }

    public function setSeries(?string $series): static
    {
        $this->setSourceText('series', null === $series ? null : mb_substr($series, 0, 255));

        return $this;
    }

    /** Sichtbare Mängel, z. B. „kurzer Zahn oben rechts“ */
    public function getDefects(?string $locale = null): ?string
    {
        return $this->translated('defects', $locale);
    }

    public function setDefects(?string $defects): static
    {
        $this->setSourceText('defects', $defects);

        return $this;
    }

    public function getMetaDescription(?string $locale = null): ?string
    {
        return $this->translated('metaDescription', $locale);
    }

    public function setMetaDescription(?string $metaDescription): static
    {
        $this->setSourceText('metaDescription', null === $metaDescription ? null : mb_substr($metaDescription, 0, 300));

        return $this;
    }

    public function getSlug(?string $locale = null): string
    {
        return Slug::make($this->getTitle($locale), 'objekt');
    }

    // ---- Darstellung ------------------------------------------------------------

    public function getYearLabel(): ?string
    {
        if (null === $this->year) {
            return null;
        }

        return (null !== $this->yearTo && $this->yearTo !== $this->year) ? $this->year.'–'.$this->yearTo : (string) $this->year;
    }

    /** Vorderseite, sonst das erste Bild */
    public function getMainImage(): ?ItemImage
    {
        foreach ($this->images as $image) {
            if (ImageSide::Front === $image->getSide()) {
                return $image;
            }
        }

        return $this->images->first() ?: null;
    }

    public function isPublished(): bool
    {
        return ItemStatus::Published === $this->status;
    }

    /** Darf der Schätzwert öffentlich angezeigt werden? */
    public function isEstimateVisible(): bool
    {
        return $this->showEstimate && null !== $this->catalogValue;
    }

    // ---- Merkmale ---------------------------------------------------------------

    /** @return list<ItemAttribute> nach Position der Definition sortiert, nur Merkmale der aktuellen Objektart */
    public function getSortedAttributes(): array
    {
        $values = array_filter(
            $this->attributeValues->toArray(),
            fn (ItemAttribute $a): bool => $a->getDefinition()->getArea() === $this->area && '' !== $a->getValue(),
        );
        usort($values, static fn (ItemAttribute $a, ItemAttribute $b): int => [$a->getDefinition()->getPosition(), $a->getDefinition()->getId()] <=> [$b->getDefinition()->getPosition(), $b->getDefinition()->getId()]);

        return $values;
    }

    /** @return list<ItemAttribute> */
    public function getCardAttributes(): array
    {
        return array_values(array_filter($this->getSortedAttributes(), static fn (ItemAttribute $a): bool => $a->getDefinition()->isShowInCard()));
    }

    public function getAttributeValue(string $code): ?string
    {
        foreach ($this->attributeValues as $attribute) {
            if ($attribute->getDefinition()->getCode() === $code && $attribute->getDefinition()->getArea() === $this->area) {
                return $attribute->getValue();
            }
        }

        return null;
    }

    /** Setzt (oder entfernt bei null/'') den Wert eines Merkmals. */
    public function setAttributeValue(AttributeDefinition $definition, ?string $value): void
    {
        $value = null === $value ? '' : trim($value);
        foreach ($this->attributeValues as $attribute) {
            if ($attribute->getDefinition() === $definition) {
                if ('' === $value) {
                    $this->attributeValues->removeElement($attribute);
                } else {
                    $attribute->setValue($value);
                }

                return;
            }
        }
        if ('' !== $value) {
            $this->attributeValues->add(new ItemAttribute($this, $definition, $value));
        }
    }

    /** Entfernt Werte von Merkmalen, die nicht zur aktuellen Objektart gehören. */
    public function pruneForeignAttributes(): void
    {
        foreach ($this->attributeValues->toArray() as $attribute) {
            if ($attribute->getDefinition()->getArea() !== $this->area) {
                $this->attributeValues->removeElement($attribute);
            }
        }
    }

    /**
     * Für das Backend-Formular: Werte gruppiert nach Objektart (area_<id> => [code => wert]).
     *
     * @return array<string, array<string, mixed>>
     */
    public function getAttributeData(): array
    {
        if (null === $this->area?->getId()) {
            return [];
        }
        $values = [];
        foreach ($this->area->getAttributeDefinitions() as $definition) {
            $raw = $this->getAttributeValue((string) $definition->getCode());
            $values[(string) $definition->getCode()] = match ($definition->getType()) {
                AttributeType::Number => null === $raw || !is_numeric($raw) ? null : (float) $raw,
                default => $raw,
            };
        }

        return ['area_'.$this->area->getId() => $values];
    }

    /** @param array<string, array<string, mixed>>|null $data */
    public function setAttributeData(?array $data): void
    {
        if (null === $this->area?->getId()) {
            return;
        }
        $values = $data['area_'.$this->area->getId()] ?? [];
        foreach ($this->area->getAttributeDefinitions() as $definition) {
            $value = $values[(string) $definition->getCode()] ?? null;
            if (\is_float($value) || \is_int($value)) {
                $value = rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');
            }
            $this->setAttributeValue($definition, \is_scalar($value) ? (string) $value : null);
        }
        $this->pruneForeignAttributes();
    }

    /** @return Collection<int, ItemAttribute> */
    public function getAttributeValues(): Collection
    {
        return $this->attributeValues;
    }

    // ---- Bilder -----------------------------------------------------------------

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
        $image->setItem($this);
        if (null === $image->getUpload()) {
            $this->getCurrentUpload()?->addImage($image);
        }

        return $this;
    }

    /** Entfernt ein Foto endgültig (orphanRemoval löscht Datensatz und Dateien). */
    public function removeImage(ItemImage $image): static
    {
        $this->images->removeElement($image);

        return $this;
    }

    /**
     * Blendet ein Foto aus, ohne es zu löschen: Es bleibt an seinem Upload (früheres Foto) und lässt sich
     * wieder zeigen. Nur die Zuordnung ändert sich (item_id = NULL).
     */
    public function detachImage(ItemImage $image): static
    {
        $images = $this->images;
        if ($images instanceof PersistentCollection) {
            // an orphanRemoval vorbei, das beim Entfernen aus der Sammlung sonst löschen würde
            $images->initialize();
            $images = $images->unwrap();
        }
        $images->removeElement($image);
        $image->setItem(null);

        return $this;
    }

    // ---- Ergebnisse der Modelle (Einstellungen → KI → Modelle für die Erkennung) ----

    /** @return Collection<int, RecognitionResult> */
    public function getRecognitions(): Collection
    {
        return $this->recognitions;
    }

    /**
     * Ersetzt die Ergebnisse der vorigen Erkennung (die alten werden beim Speichern gelöscht).
     *
     * @param list<RecognitionResult> $results
     */
    public function replaceRecognitions(array $results): static
    {
        $this->recognitions->clear();
        foreach ($results as $result) {
            $this->recognitions->add($result);
        }

        return $this;
    }

    /** Markiert das Ergebnis, das im Formular steht (null = keines). */
    public function markAppliedRecognition(?RecognitionResult $applied): static
    {
        foreach ($this->recognitions as $result) {
            $result->setApplied($result === $applied);
        }

        return $this;
    }

    // ---- Uploads und Duplikate -----------------------------------------------------

    /** @return Collection<int, ItemUpload> */
    public function getUploads(): Collection
    {
        return $this->uploads;
    }

    public function addUpload(ItemUpload $upload): static
    {
        if (!$this->uploads->contains($upload)) {
            $this->uploads->add($upload);
        }
        $upload->setItem($this);
        $this->uploadCount = max(1, $this->uploads->count());

        return $this;
    }

    public function removeUpload(ItemUpload $upload): static
    {
        $this->uploads->removeElement($upload);
        $this->uploadCount = max(1, $this->uploads->count());

        return $this;
    }

    /** Upload, dessen Fotos gerade gezeigt werden – sonst der neueste */
    public function getCurrentUpload(): ?ItemUpload
    {
        foreach ($this->images as $image) {
            if (null !== $image->getUpload()) {
                return $image->getUpload();
            }
        }
        $last = $this->uploads->last();

        return false === $last ? null : $last;
    }

    public function getUploadCount(): int
    {
        return $this->uploadCount;
    }

    public function getDuplicateStatus(): ?DuplicateStatus
    {
        return $this->duplicateStatus;
    }

    public function setDuplicateStatus(?DuplicateStatus $duplicateStatus): static
    {
        $this->duplicateStatus = $duplicateStatus;

        return $this;
    }

    public function getDuplicateOf(): ?Item
    {
        return $this->duplicateOf;
    }

    public function getDuplicateConfidence(): ?float
    {
        return $this->duplicateConfidence;
    }

    public function getDuplicateReason(): ?string
    {
        return $this->duplicateReason;
    }

    /** @return array<string, mixed>|null */
    public function getDuplicateResult(): ?array
    {
        return $this->duplicateResult;
    }

    /**
     * Hinweis „mögliches Duplikat von #ID“ setzen (oder mit null entfernen).
     *
     * @param array<string, mixed>|null $result
     */
    public function markPossibleDuplicate(?Item $of, ?float $confidence = null, ?string $reason = null, ?array $result = null): static
    {
        $this->duplicateOf = $of;
        $this->duplicateConfidence = $confidence;
        $this->duplicateReason = $reason;
        $this->duplicateResult = $result;
        if (null !== $of) {
            $this->duplicateStatus = DuplicateStatus::Possible;
        }

        return $this;
    }

    public function isPossibleDuplicate(): bool
    {
        return DuplicateStatus::Possible === $this->duplicateStatus && null !== $this->duplicateOf;
    }

    public function getMergedUpload(): ?ItemUpload
    {
        return $this->mergedUpload;
    }

    public function setMergedUpload(?ItemUpload $mergedUpload): static
    {
        $this->mergedUpload = $mergedUpload;

        return $this;
    }

    // ---- Themen -----------------------------------------------------------------

    /** @return Collection<int, Topic> */
    public function getTopics(): Collection
    {
        return $this->topics;
    }

    public function addTopic(Topic $topic): static
    {
        if (!$this->topics->contains($topic)) {
            $this->topics->add($topic);
        }

        return $this;
    }

    public function removeTopic(Topic $topic): static
    {
        $this->topics->removeElement($topic);

        return $this;
    }

    // ---- Status -----------------------------------------------------------------

    public function getStatus(): ItemStatus
    {
        return $this->status;
    }

    public function setStatus(?ItemStatus $status): static
    {
        $status ??= ItemStatus::Draft;
        if (ItemStatus::Published === $status && null === $this->publishedAt) {
            $this->publishedAt = new \DateTimeImmutable();
        }
        $this->status = $status;

        return $this;
    }

    // ---- Einfache Getter/Setter -------------------------------------------------

    public function getArea(): ?CollectionArea
    {
        return $this->area;
    }

    public function setArea(?CollectionArea $area): static
    {
        $this->area = $area;

        return $this;
    }

    public function getCategory(): ?Category
    {
        return $this->category;
    }

    public function setCategory(?Category $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getYear(): ?int
    {
        return $this->year;
    }

    public function setYear(?int $year): static
    {
        $this->year = $year;

        return $this;
    }

    public function getYearTo(): ?int
    {
        return $this->yearTo;
    }

    public function setYearTo(?int $yearTo): static
    {
        $this->yearTo = $yearTo;

        return $this;
    }

    public function getIssueDate(): ?\DateTimeImmutable
    {
        return $this->issueDate;
    }

    public function setIssueDate(?\DateTimeImmutable $issueDate): static
    {
        $this->issueDate = $issueDate;

        return $this;
    }

    public function getCatalogValue(): ?string
    {
        return $this->catalogValue;
    }

    public function setCatalogValue(string|float|int|null $catalogValue): static
    {
        $this->catalogValue = null === $catalogValue || '' === $catalogValue ? null : number_format((float) $catalogValue, 2, '.', '');

        return $this;
    }

    public function isShowEstimate(): bool
    {
        return $this->showEstimate;
    }

    public function setShowEstimate(?bool $showEstimate): static
    {
        $this->showEstimate = (bool) $showEstimate;

        return $this;
    }

    public function isFeatured(): bool
    {
        return $this->featured;
    }

    public function setFeatured(?bool $featured): static
    {
        $this->featured = (bool) $featured;

        return $this;
    }

    public function getSourceFolder(): ?string
    {
        return $this->sourceFolder;
    }

    public function setSourceFolder(?string $sourceFolder): static
    {
        $this->sourceFolder = $sourceFolder;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getAiResult(): ?array
    {
        return $this->aiResult;
    }

    /** @param array<string, mixed>|null $aiResult */
    public function setAiResult(?array $aiResult): static
    {
        $this->aiResult = $aiResult;

        return $this;
    }

    public function getAiConfidence(): ?float
    {
        return $this->aiConfidence;
    }

    public function setAiConfidence(?float $aiConfidence): static
    {
        $this->aiConfidence = $aiConfidence;

        return $this;
    }

    public function getAiModel(): ?string
    {
        return $this->aiModel;
    }

    public function setAiModel(?string $aiModel): static
    {
        $this->aiModel = $aiModel;

        return $this;
    }

    public function getAiError(): ?string
    {
        return $this->aiError;
    }

    public function setAiError(?string $aiError): static
    {
        $this->aiError = $aiError;

        return $this;
    }

    public function getRecognizedAt(): ?\DateTimeImmutable
    {
        return $this->recognizedAt;
    }

    public function setRecognizedAt(?\DateTimeImmutable $recognizedAt): static
    {
        $this->recognizedAt = $recognizedAt;

        return $this;
    }

    public function getStorageLocation(): ?string
    {
        return $this->storageLocation;
    }

    public function setStorageLocation(?string $storageLocation): static
    {
        $this->storageLocation = $storageLocation;

        return $this;
    }

    public function getInternalNotes(): ?string
    {
        return $this->internalNotes;
    }

    public function setInternalNotes(?string $internalNotes): static
    {
        $this->internalNotes = $internalNotes;

        return $this;
    }

    public function getAiHint(): ?string
    {
        return $this->aiHint;
    }

    public function setAiHint(?string $aiHint): static
    {
        $aiHint = null === $aiHint ? '' : trim(mb_substr($aiHint, 0, self::AI_HINT_MAX));
        $this->aiHint = '' === $aiHint ? null : $aiHint;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function setPublishedAt(?\DateTimeImmutable $publishedAt): static
    {
        $this->publishedAt = $publishedAt;

        return $this;
    }
}
