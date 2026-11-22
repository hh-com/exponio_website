<?php

namespace App\EventListener;

use App\Entity\ItemImage;
use App\Media\MediaStorage;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;

/**
 * Entfernt die Bilddateien, sobald ein ItemImage gelöscht wurde (auch per Kaskade beim Objekt).
 */
#[AsEntityListener(event: Events::postRemove, method: 'postRemove', entity: ItemImage::class)]
final class ItemImageFileListener
{
    public function __construct(private readonly MediaStorage $media)
    {
    }

    public function postRemove(ItemImage $image): void
    {
        if (null !== $image->getPath()) {
            $this->media->delete($image->getPath());
        }
    }
}
