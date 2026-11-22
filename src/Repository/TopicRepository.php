<?php

namespace App\Repository;

use App\Entity\Language;
use App\Entity\Topic;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Topic>
 */
class TopicRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Topic::class);
    }

    /** @return list<Topic> inkl. Übersetzungen */
    public function findAllOrdered(bool $activeOnly = false): array
    {
        $qb = $this->createQueryBuilder('t')
            ->leftJoin('t.translations', 'tr')->addSelect('tr')
            ->orderBy('t.position', 'ASC')->addOrderBy('t.id', 'ASC');
        if ($activeOnly) {
            $qb->andWhere('t.active = true');
        }

        return $qb->getQuery()->getResult();
    }

    /** Sucht ein Thema anhand des deutschen Namens. */
    public function findOneByName(string $name): ?Topic
    {
        return $this->createQueryBuilder('t')
            ->innerJoin('t.translations', 'tr', 'WITH', 'tr.locale = :source')->setParameter('source', Language::SOURCE)
            ->andWhere('LOWER(tr.name) = :name')->setParameter('name', mb_strtolower(trim($name)))
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }
}
