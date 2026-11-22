<?php

namespace App\Repository;

use App\Entity\Language;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Language>
 */
class LanguageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Language::class);
    }

    /** @return list<Language> freigeschaltete Sprachen (auch solche, deren Übersetzung noch läuft) */
    public function findActive(): array
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.active = true')
            ->orderBy('l.position', 'ASC')->addOrderBy('l.nameDe', 'ASC')
            ->getQuery()->getResult();
    }

    /** @return list<Language> aktive Sprachen ohne Deutsch (Ziele der Übersetzung) */
    public function findTranslationTargets(): array
    {
        return array_values(array_filter($this->findActive(), static fn (Language $l): bool => !$l->isSource()));
    }
}
