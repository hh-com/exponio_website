<?php

namespace App\Cache;

use App\Entity\AppToken;
use App\Entity\Inquiry;
use App\Entity\ScanJob;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * Erhöht nach jedem Speichern von Inhalten die ContentVersion (und leert damit alle Caches).
 * Kontaktnachrichten, Scan-Protokoll, Benutzer und App-Zugänge zählen nicht als Inhalt.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class ContentChangeListener
{
    private const IGNORED = [AppToken::class, Inquiry::class, ScanJob::class, User::class];

    private bool $changed = false;

    public function __construct(private readonly ContentVersion $version)
    {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $uow = $args->getObjectManager()->getUnitOfWork();
        $entities = [
            ...$uow->getScheduledEntityInsertions(),
            ...$uow->getScheduledEntityUpdates(),
            ...$uow->getScheduledEntityDeletions(),
        ];
        foreach ($entities as $entity) {
            if (!\in_array($entity::class, self::IGNORED, true)) {
                $this->changed = true;

                return;
            }
        }
        if ([] !== $uow->getScheduledCollectionUpdates() || [] !== $uow->getScheduledCollectionDeletions()) {
            $this->changed = true;
        }
    }

    public function postFlush(): void
    {
        if ($this->changed) {
            $this->changed = false;
            $this->version->bump();
        }
    }
}
