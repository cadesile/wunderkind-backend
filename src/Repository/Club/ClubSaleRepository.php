<?php

declare(strict_types=1);

namespace App\Repository\Club;

use App\Entity\Club\ClubSale;
use App\Entity\User;
use App\Enum\Club\ClubSaleStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ClubSale>
 */
class ClubSaleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ClubSale::class);
    }

    /**
     * The live sale for one club, if any — PENDING or COMPLETED, never FAILED.
     *
     * Mirrors uq_club_sale_club_live's own predicate, so what this returns and what the index
     * refuses are the same set. A FAILED row is deliberately invisible here: the admin screen
     * should offer the sell button again after a failure, and the index allows it.
     */
    public function findLiveForClub(string $clubId): ?ClubSale
    {
        return $this->createQueryBuilder('s')
            ->where('s.clubId = :clubId')
            ->andWhere('s.status != :failed')
            ->setParameter('clubId', $clubId)
            ->setParameter('failed', ClubSaleStatus::FAILED)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Live sales for a set of clubs, keyed by club id.
     *
     * The admin user page renders every club a user owns, so the per-club lookup above would be
     * one query per row. Same reasoning as the valuation batch on that page.
     *
     * @param list<string> $clubIds
     *
     * @return array<string, ClubSale>
     */
    public function findLiveForClubs(array $clubIds): array
    {
        if ($clubIds === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('s')
            ->where('s.clubId IN (:clubIds)')
            ->andWhere('s.status != :failed')
            ->setParameter('clubIds', $clubIds, ArrayParameterType::STRING)
            ->setParameter('failed', ClubSaleStatus::FAILED)
            ->getQuery()
            ->getResult();

        $out = [];

        foreach ($rows as $row) {
            $out[$row->getClubId()] = $row;
        }

        return $out;
    }

    /** @return list<ClubSale> */
    public function findForUser(User $user): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.user = :user')
            ->setParameter('user', $user)
            ->orderBy('s.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
