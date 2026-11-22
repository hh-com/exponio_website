<?php

namespace App\Repository;

use App\Entity\ItemUpload;
use App\Enum\UploadResult;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ItemUpload>
 */
class ItemUploadRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ItemUpload::class);
    }

    /**
     * Kennzahlen für die Übersicht im Backend.
     *
     * @return array{total: int, duplicates: int, replaced: int, recent: int, proposals: int}
     */
    public function stats(\DateTimeImmutable $since): array
    {
        $row = $this->createQueryBuilder('u')
            ->select('COUNT(u.id) AS total')
            ->addSelect('SUM(CASE WHEN u.result = :duplicate THEN 1 ELSE 0 END) AS duplicates')
            ->addSelect('SUM(CASE WHEN u.imagesReplaced = true THEN 1 ELSE 0 END) AS replaced')
            ->addSelect('SUM(CASE WHEN u.result = :duplicate AND u.createdAt >= :since THEN 1 ELSE 0 END) AS recent')
            ->addSelect('SUM(CASE WHEN u.proposal = true THEN 1 ELSE 0 END) AS proposals')
            ->setParameter('duplicate', UploadResult::Duplicate)
            ->setParameter('since', $since)
            ->getQuery()->getSingleResult();

        return [
            'total' => (int) $row['total'],
            'duplicates' => (int) $row['duplicates'],
            'replaced' => (int) $row['replaced'],
            'recent' => (int) $row['recent'],
            'proposals' => (int) $row['proposals'],
        ];
    }

    public function countProposals(): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->andWhere('u.proposal = true')
            ->getQuery()->getSingleScalarResult();
    }

    /** @return list<int> Objekte mit vorgeschlagenen, besseren Fotos */
    public function findItemIdsWithProposals(): array
    {
        $rows = $this->createQueryBuilder('u')
            ->select('DISTINCT IDENTITY(u.item) AS itemId')
            ->andWhere('u.proposal = true')
            ->getQuery()->getArrayResult();

        return array_map(static fn (array $row): int => (int) $row['itemId'], $rows);
    }

    /** @return list<ItemUpload> zuletzt als Duplikat erkannte Uploads */
    public function findLatestDuplicates(int $limit): array
    {
        return $this->createQueryBuilder('u')
            ->addSelect('i')
            ->join('u.item', 'i')
            ->andWhere('u.result = :duplicate')->setParameter('duplicate', UploadResult::Duplicate)
            ->orderBy('u.createdAt', 'DESC')->addOrderBy('u.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }
}
