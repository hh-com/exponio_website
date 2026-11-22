<?php

namespace App\Entity;

use App\Enum\ScanStatus;
use App\Repository\ScanJobRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Protokoll: welcher Upload-Ordner wann verarbeitet wurde. Ein Ordner wird nur einmal importiert;
 * zum erneuten Import den Eintrag im Backend löschen. Erkannte Duplikate zeigen auf das vorhandene Objekt.
 */
#[ORM\Entity(repositoryClass: ScanJobRepository::class)]
class ScanJob
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100, unique: true)]
    private string $folder;

    #[ORM\Column(length: 20, enumType: ScanStatus::class)]
    private ScanStatus $status;

    #[ORM\Column]
    private int $imageCount = 0;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Item $item = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $message = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $folder, ScanStatus $status, int $imageCount = 0, ?Item $item = null, ?string $message = null)
    {
        $this->folder = $folder;
        $this->status = $status;
        $this->imageCount = $imageCount;
        $this->item = $item;
        $this->message = $message;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFolder(): string
    {
        return $this->folder;
    }

    public function getStatus(): ScanStatus
    {
        return $this->status;
    }

    public function getImageCount(): int
    {
        return $this->imageCount;
    }

    public function getItem(): ?Item
    {
        return $this->item;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    /** Ordner gehört jetzt zu einem anderen Objekt (als Duplikat zugeordnet oder wieder abgetrennt) */
    public function assign(Item $item, ScanStatus $status, ?string $message = null): static
    {
        $this->item = $item;
        $this->status = $status;
        $this->message = $message;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
