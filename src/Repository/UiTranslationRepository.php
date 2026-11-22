<?php

namespace App\Repository;

use App\Entity\UiTranslation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UiTranslation>
 */
class UiTranslationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UiTranslation::class);
    }

    /** @return array<string, UiTranslation> Schlüssel => Übersetzung */
    public function findByLocale(string $locale): array
    {
        $result = [];
        foreach ($this->findBy(['locale' => $locale]) as $translation) {
            $result[$translation->getMessageKey()] = $translation;
        }

        return $result;
    }

    /** @return array<string, string> Schlüssel => Text */
    public function messages(string $locale): array
    {
        $rows = $this->createQueryBuilder('t')
            ->select('t.messageKey AS k, t.text AS v')
            ->andWhere('t.locale = :locale')->setParameter('locale', $locale)
            ->getQuery()->getArrayResult();

        return array_column($rows, 'v', 'k');
    }
}
