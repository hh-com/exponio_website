<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Backend-Benutzer.
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'admin_user')]
#[UniqueEntity(fields: ['email'], message: 'Diese E-Mail-Adresse ist bereits vergeben.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface, \Stringable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Email]
    private ?string $email = null;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $roles = ['ROLE_ADMIN'];

    #[ORM\Column]
    private ?string $password = null;

    /** Nur für Formulare (Backend), nicht gespeichert */
    #[Assert\Length(min: 10, max: 4096)]
    private ?string $plainPassword = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(?string $email = null)
    {
        $this->email = $email;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return (string) $this->email;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = null === $email ? null : mb_strtolower(trim($email));

        return $this;
    }

    public function getUserIdentifier(): string
    {
        if (null === $this->email || '' === $this->email) {
            throw new \LogicException('Der Benutzer hat keine E-Mail-Adresse.');
        }

        return $this->email;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return array_values(array_unique([...$this->roles, 'ROLE_ADMIN']));
    }

    /** @param list<string> $roles */
    public function setRoles(?array $roles): static
    {
        $this->roles = $roles ?? [];

        return $this;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function getPlainPassword(): ?string
    {
        return $this->plainPassword;
    }

    public function setPlainPassword(?string $plainPassword): static
    {
        $this->plainPassword = $plainPassword;

        return $this;
    }

    #[\Deprecated]
    public function eraseCredentials(): void
    {
        // @deprecated, wird mit Symfony 8 entfernt; plainPassword wird nach dem Hashen direkt geleert
    }

    /** Der Passwort-Hash gehört nicht in die Session */
    public function __serialize(): array
    {
        $data = (array) $this;
        $data["\0".self::class."\0password"] = hash('crc32c', (string) $this->password);

        return $data;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
