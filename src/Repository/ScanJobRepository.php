<?php

namespace App\Repository;

use App\Entity\ScanJob;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ScanJob>
 */
class ScanJobRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ScanJob::class);
    }

    /** @return array<string, true> bereits verarbeitete Ordnernamen */
    public function findKnownFolders(): array
    {
        $rows = $this->createQueryBuilder('j')->select('j.folder')->getQuery()->getSingleColumnResult();

        return array_fill_keys($rows, true);
    }

    public function findLatest(): ?ScanJob
    {
        return $this->findOneBy([], ['createdAt' => 'DESC', 'id' => 'DESC']);
    }
}
