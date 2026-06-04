<?php

namespace App\Repository;

use App\Entity\EmailVerification;
use App\Entity\User;
use App\Enum\VerificationPurpose;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class EmailVerificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmailVerification::class);
    }

    /**
     * Returns the most recent unverified, unexpired record for a user and purpose.
     */
    public function findActiveForUser(User $user, VerificationPurpose $purpose = VerificationPurpose::REGISTRATION): ?EmailVerification
    {
        return $this->createQueryBuilder('ev')
            ->where('ev.user = :user')
            ->andWhere('ev.purpose = :purpose')
            ->andWhere('ev.verifiedAt IS NULL')
            ->andWhere('ev.expiresAt > :now')
            ->setParameter('user', $user)
            ->setParameter('purpose', $purpose)
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('ev.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Returns the most recent unverified record regardless of expiry — used for
     * fallback error classification (expired vs locked-out).
     */
    public function findLatestUnverifiedForUser(User $user, VerificationPurpose $purpose = VerificationPurpose::REGISTRATION): ?EmailVerification
    {
        return $this->createQueryBuilder('ev')
            ->where('ev.user = :user')
            ->andWhere('ev.purpose = :purpose')
            ->andWhere('ev.verifiedAt IS NULL')
            ->setParameter('user', $user)
            ->setParameter('purpose', $purpose)
            ->orderBy('ev.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
