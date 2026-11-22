<?php

namespace App\Repository;

use App\Entity\Category;
use App\Entity\Language;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Category>
 */
class CategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Category::class);
    }

    /** @return list<Category> alle Länder/Gebiete inkl. Übersetzungen */
    public function findAllOrdered(bool $activeOnly = false): array
    {
        $qb = $this->createQueryBuilder('c')
            ->leftJoin('c.translations', 't')->addSelect('t')
            ->orderBy('c.position', 'ASC')->addOrderBy('c.id', 'ASC');
        if ($activeOnly) {
            $qb->andWhere('c.active = true');
        }

        return $qb->getQuery()->getResult();
    }

    /** Sucht ein Gebiet anhand des deutschen Namens unter einem Elternteil (null = oberste Ebene). */
    public function findOneByNameUnder(string $name, ?Category $parent): ?Category
    {
        $qb = $this->createQueryBuilder('c')
            ->innerJoin('c.translations', 't', 'WITH', 't.locale = :source')->setParameter('source', Language::SOURCE)
            ->andWhere('LOWER(t.name) = :name')->setParameter('name', mb_strtolower(trim($name)))
            ->setMaxResults(1);
        null === $parent ? $qb->andWhere('c.parent IS NULL') : $qb->andWhere('c.parent = :parent')->setParameter('parent', $parent);

        return $qb->getQuery()->getOneOrNullResult();
    }

    /** Sucht ein Gebiet anhand des deutschen Namens auf beliebiger Ebene. */
    public function findOneByName(string $name): ?Category
    {
        return $this->createQueryBuilder('c')
            ->innerJoin('c.translations', 't', 'WITH', 't.locale = :source')->setParameter('source', Language::SOURCE)
            ->andWhere('LOWER(t.name) = :name')->setParameter('name', mb_strtolower(trim($name)))
            ->orderBy('c.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }
}
