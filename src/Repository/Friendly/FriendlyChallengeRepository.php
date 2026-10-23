<?php

declare(strict_types=1);

namespace App\Repository\Friendly;

use App\Entity\Club;
use App\Entity\Friendly\FriendlyChallenge;
use App\Enum\Friendly\FriendlyChallengeStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FriendlyChallenge>
 */
class FriendlyChallengeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FriendlyChallenge::class);
    }

    public function countOpenForClub(Club $club, \DateTimeImmutable $now): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.challengerClub = :club')
            ->andWhere('c.status = :status')
            ->andWhere('c.expiresAt > :now')
            ->setParameter('club', $club->getId())
            ->setParameter('status', FriendlyChallengeStatus::OPEN)
            ->setParameter('now', $now)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @param list<string>|null $reputationTiers
     * @return list<FriendlyChallenge>
     */
    public function findOpenBoard(
        \DateTimeImmutable $now,
        ?string $stakeBand = null,
        ?array $reputationTiers = null,
        ?string $excludeUserId = null,
        int $offset = 0,
        int $limit = 20,
    ): array {
        $qb = $this->createQueryBuilder('c')
            ->where('c.status = :status')
            ->andWhere('c.expiresAt > :now')
            ->setParameter('status', FriendlyChallengeStatus::OPEN)
            ->setParameter('now', $now);

        if ($excludeUserId !== null) {
            $qb->andWhere('c.challengerUserId != :excludeUserId')
                ->setParameter('excludeUserId', $excludeUserId);
        }

        if ($stakeBand === 'none') {
            $qb->andWhere('c.stakePence = 0');
        } elseif ($stakeBand === 'low') {
            $qb->andWhere('c.stakePence > 0 AND c.stakePence <= :lowMax')
                ->setParameter('lowMax', 5_000_000);
        } elseif ($stakeBand === 'high') {
            $qb->andWhere('c.stakePence > :lowMax')
                ->setParameter('lowMax', 5_000_000);
        }

        if ($reputationTiers !== null && $reputationTiers !== []) {
            $qb->andWhere('c.challengerReputationTier IN (:tiers)')
                ->setParameter('tiers', $reputationTiers);
        }

        return $qb->orderBy('c.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** @return list<FriendlyChallenge> */
    public function findOpenForClub(Club $club, \DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.challengerClubId = :clubId')
            ->andWhere('c.status = :status')
            ->andWhere('c.expiresAt > :now')
            ->setParameter('clubId', (string) $club->getId())
            ->setParameter('status', FriendlyChallengeStatus::OPEN)
            ->setParameter('now', $now)
            ->orderBy('c.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<FriendlyChallenge> */
    public function findAcceptedForClub(Club $club): array
    {
        $clubId = (string) $club->getId();

        return $this->createQueryBuilder('c')
            ->where('(c.challengerClubId = :clubId OR c.acceptorClubId = :clubId)')
            ->andWhere('c.status = :status')
            ->setParameter('clubId', $clubId)
            ->setParameter('status', FriendlyChallengeStatus::ACCEPTED)
            ->orderBy('c.kickoffAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<FriendlyChallenge> */
    public function findCompletedForClub(Club $club, int $limit = 30): array
    {
        $clubId = (string) $club->getId();

        return $this->createQueryBuilder('c')
            ->where('(c.challengerClubId = :clubId OR c.acceptorClubId = :clubId)')
            ->andWhere('c.status = :status')
            ->setParameter('clubId', $clubId)
            ->setParameter('status', FriendlyChallengeStatus::COMPLETE)
            ->orderBy('c.resolvedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** @return list<FriendlyChallenge> */
    public function findCompletedBetweenClubs(string $clubAId, string $clubBId): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.status = :status')
            ->andWhere('((c.challengerClubId = :clubA AND c.acceptorClubId = :clubB) OR (c.challengerClubId = :clubB AND c.acceptorClubId = :clubA))')
            ->setParameter('status', FriendlyChallengeStatus::COMPLETE)
            ->setParameter('clubA', $clubAId)
            ->setParameter('clubB', $clubBId)
            ->orderBy('c.resolvedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<FriendlyChallenge> */
    public function findDueForResolution(\DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.status = :status')
            ->andWhere('c.kickoffAt <= :now')
            ->setParameter('status', FriendlyChallengeStatus::ACCEPTED)
            ->setParameter('now', $now)
            ->orderBy('c.kickoffAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<FriendlyChallenge> */
    public function findDueForExpiry(\DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.status = :status')
            ->andWhere('c.expiresAt <= :now')
            ->setParameter('status', FriendlyChallengeStatus::OPEN)
            ->setParameter('now', $now)
            ->orderBy('c.expiresAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
