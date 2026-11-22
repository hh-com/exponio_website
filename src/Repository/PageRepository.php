<?php

namespace App\Repository;

use App\Entity\Page;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Page>
 */
class PageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Page::class);
    }

    /** @return list<Page> */
    public function findFooterPages(): array
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.translations', 't')->addSelect('t')
            ->andWhere('p.active = true')->andWhere('p.showInFooter = true')
            ->orderBy('p.position', 'ASC')->addOrderBy('p.id', 'ASC')
            ->getQuery()->getResult();
    }

    /** @return list<Page> */
    public function findActive(): array
    {
        return $this->findBy(['active' => true], ['position' => 'ASC', 'id' => 'ASC']);
    }
}
