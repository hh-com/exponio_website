<?php

namespace App\Repository;

use App\Entity\AppToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AppToken>
 */
class AppTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AppToken::class);
    }

    public function findOneByToken(#[\SensitiveParameter] string $token): ?AppToken
    {
        if (!str_starts_with($token, AppToken::PREFIX)) {
            return null;
        }

        return $this->findOneBy(['tokenHash' => AppToken::hash($token)]);
    }
}
