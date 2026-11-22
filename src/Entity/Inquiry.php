<?php

namespace App\Entity;

use App\Enum\InquiryStatus;
use App\Repository\InquiryRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Nachricht aus dem Kontaktformular (wird nach der eingestellten Frist automatisch gelöscht).
 */
#[ORM\Entity(repositoryClass: InquiryRepository::class)]
class Inquiry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(length: 180)]
    private string $email;

    #[ORM\Column(type: 'text')]
    private string $message;

    #[ORM\Column(length: 12)]
    private string $locale;

    #[ORM\Column(length: 20, enumType: InquiryStatus::class)]
    private InquiryStatus $status = InquiryStatus::New;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $adminNote = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $name, string $email, string $message, string $locale)
    {
        $this->name = $name;
        $this->email = $email;
        $this->message = $message;
        $this->locale = $locale;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function getStatus(): InquiryStatus
    {
        return $this->status;
    }

    public function setStatus(?InquiryStatus $status): static
    {
        $this->status = $status ?? InquiryStatus::New;

        return $this;
    }

    public function getAdminNote(): ?string
    {
        return $this->adminNote;
    }

    public function setAdminNote(?string $adminNote): static
    {
        $this->adminNote = $adminNote;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
