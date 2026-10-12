<?php

namespace App\Repository;

use App\Entity\Club;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Club>
 */
class ClubRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Club::class);
    }

    public function findByUser(User $user): ?Club
    {
        return $this->createQueryBuilder('c')
            ->where('c.user = :user')
            ->setParameter('user', $user)
            ->orderBy('c.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return Club[] */
    public function findAllByUser(User $user): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.user = :user')
            ->setParameter('user', $user)
            ->orderBy('c.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Every non-spoof club owned by any of $userIds, richest club first, with the
     * club's current league eagerly joined so rendering a division name per club
     * does not fire one lazy-load query per row.
     *
     * @param  string[] $userIds
     * @return Club[]
     */
    public function findByUserIds(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        return $this->createQueryBuilder('c')
            ->addSelect('l')
            ->leftJoin('c.currentLeague', 'l')
            ->where('c.user IN (:userIds)')
            ->andWhere('c.isSpoof = false')
            ->setParameter('userIds', $userIds)
            ->orderBy('c.balance', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Clubs by id, keyed by id string — used to decorate an already-ranked list of
     * clubIds (community stats) with kit/badge/country identity in one query rather
     * than widening every ranking query with json columns.
     *
     * @param  string[] $ids
     * @return array<string, Club>
     */
    public function findByIdsKeyed(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $clubs = $this->createQueryBuilder('c')
            ->where('c.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        $keyed = [];
        foreach ($clubs as $club) {
            $keyed[(string) $club->getId()] = $club;
        }

        return $keyed;
    }
}
