<?php

namespace App\Service;

use App\Entity\Settings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Liefert den einen Einstellungs-Datensatz (legt ihn bei Bedarf an).
 */
final class SettingsProvider implements ResetInterface
{
    private ?Settings $settings = null;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function get(): Settings
    {
        if (null !== $this->settings) {
            return $this->settings;
        }

        $settings = $this->em->getRepository(Settings::class)->findOneBy([], ['id' => 'ASC']);
        if (null === $settings) {
            $settings = new Settings();
            $this->em->persist($settings);
            $this->em->flush();
        }

        return $this->settings = $settings;
    }

    public function reset(): void
    {
        $this->settings = null;
    }
}
