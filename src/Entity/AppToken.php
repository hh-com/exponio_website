<?php

namespace App\Entity;

use App\Repository\AppTokenRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Zugang der Exponio-App zur Upload-Schnittstelle (App\Controller\Api\AppUploadController), angelegt im Backend unter
 * System → App-Zugänge. Pro Gerät ein Zugang: Ein verlorenes Handy sperrst du, indem du seinen Zugang löschst.
 *
 * Gespeichert wird nur der SHA-256-Hash des Tokens. Das Token selbst gibt es nur direkt nach dem Anlegen
 * ({@see takePlainToken()}); danach lässt es sich nicht mehr anzeigen, nur durch einen neuen Zugang ersetzen.
 */
#[ORM\Entity(repositoryClass: AppTokenRepository::class)]
class AppToken implements \Stringable
{
    /** Kennzeichnet Exponio-Tokens (hilft beim Erkennen, z. B. in Passwort-Managern und Secret-Scannern) */
    public const PREFIX = 'exp_';

    /** „Zuletzt benutzt“ wird höchstens so oft gespeichert, nicht bei jeder Datei eines Uploads */
    private const TOUCH_INTERVAL = 600;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** z. B. „Pixel 10“ */
    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 80)]
    private ?string $label = null;

    #[ORM\Column(length: 64, unique: true)]
    private string $tokenHash;

    /** Die letzten vier Zeichen des Tokens, damit man Zugänge auseinanderhalten kann */
    #[ORM\Column(length: 8)]
    private string $hint;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    /** Nur bis zur ersten Anzeige nach dem Anlegen, nie gespeichert */
    private ?string $plainToken;

    public function __construct()
    {
        // 256 Bit Zufall, URL-sicher kodiert (43 Zeichen)
        $plain = self::PREFIX.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->plainToken = $plain;
        $this->tokenHash = self::hash($plain);
        $this->hint = substr($plain, -4);
        $this->createdAt = new \DateTimeImmutable();
    }

    public static function hash(#[\SensitiveParameter] string $token): string
    {
        return hash('sha256', $token);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): static
    {
        $this->label = null === $label ? null : trim($label);

        return $this;
    }

    public function getHint(): string
    {
        return $this->hint;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    /** Liefert das Token genau einmal (direkt nach dem Anlegen), danach null. */
    public function takePlainToken(): ?string
    {
        $plain = $this->plainToken;
        $this->plainToken = null;

        return $plain;
    }

    /** Merkt die Benutzung vor. true, wenn sich der gespeicherte Zeitpunkt geändert hat (dann speichern). */
    public function touch(\DateTimeImmutable $now): bool
    {
        if (null !== $this->lastUsedAt && $now->getTimestamp() - $this->lastUsedAt->getTimestamp() < self::TOUCH_INTERVAL) {
            return false;
        }
        $this->lastUsedAt = $now;

        return true;
    }

    public function __toString(): string
    {
        return (string) $this->label;
    }
}
