<?php

namespace App\Repository;

use App\Entity\CollectionArea;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CollectionArea>
 */
class CollectionAreaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CollectionArea::class);
    }

    /** @return list<CollectionArea> aktive Objektarten inkl. Merkmal-Definitionen und Texten */
    public function findActive(): array
    {
        return $this->baseQuery()->andWhere('a.active = true')->getQuery()->getResult();
    }

    /** @return list<CollectionArea> alle Objektarten inkl. Merkmal-Definitionen und Texten */
    public function findAllWithDefinitions(): array
    {
        return $this->baseQuery()->getQuery()->getResult();
    }

    private function baseQuery(): \Doctrine\ORM\QueryBuilder
    {
        return $this->createQueryBuilder('a')
            ->leftJoin('a.translations', 'at')->addSelect('at')
            ->leftJoin('a.attributeDefinitions', 'd')->addSelect('d')
            ->leftJoin('d.translations', 'dt')->addSelect('dt')
            ->orderBy('a.position', 'ASC')->addOrderBy('a.id', 'ASC')->addOrderBy('d.position', 'ASC')->addOrderBy('d.id', 'ASC');
    }
}
