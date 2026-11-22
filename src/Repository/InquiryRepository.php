<?php

namespace App\Repository;

use App\Entity\Inquiry;
use App\Enum\InquiryStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Inquiry>
 */
class InquiryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Inquiry::class);
    }

    public function countNew(): int
    {
        return $this->count(['status' => InquiryStatus::New]);
    }

    /** Löscht Nachrichten, die vor dem Stichtag eingegangen sind (Datensparsamkeit). */
    public function deleteOlderThan(\DateTimeImmutable $cutoff): int
    {
        return (int) $this->createQueryBuilder('i')
            ->delete()
            ->andWhere('i.createdAt < :cutoff')->setParameter('cutoff', $cutoff)
            ->getQuery()->execute();
    }
}
