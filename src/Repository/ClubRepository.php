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
     * The owners with the most money across every club they own, richest first —
     * the landing page's "Owner Spotlight".
     *
     * "Richest" is deliberately the SUM of the owner's clubs' balances, not
     * UserLedgerRepository::getCurrentBalance(). The centralized user ledger only
     * holds DIVIDEND_DRAW entries, which a client has to actually report — in
     * production that is a single-digit number of rows, so ranking on it would
     * show one owner with a figure and four zeroes. Club balance is synced by
     * every client on every sync, so it is the only wealth signal that is
     * populated for every owner.
     *
     * Spoof clubs (CompetitionSpoofEntrantService's filler entrants) are excluded —
     * they are admin-generated, carry no real owner, and have no business on the
     * public marketing site.
     *
     * @return array<array{userId: string, ownerName: ?string, ownerNationality: ?string,
     *     ownerAppearance: ?array, balance: int, clubCount: int}>
     */
    public function findRichestOwners(int $limit): array
    {
        $rows = $this->createQueryBuilder('c')
            ->select(
                'u.id AS userId',
                'u.name AS ownerName',
                'u.nationality AS ownerNationality',
                'u.appearance AS ownerAppearance',
                'SUM(c.balance) AS balance',
                'COUNT(c.id) AS clubCount',
            )
            ->innerJoin('c.user', 'u')
            ->where('c.isSpoof = false')
            ->groupBy('u.id')
            ->orderBy('balance', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        return array_map(
            static fn (array $r): array => [
                'userId'           => (string) $r['userId'],
                'ownerName'        => $r['ownerName'],
                'ownerNationality' => $r['ownerNationality'],
                'ownerAppearance'  => $r['ownerAppearance'],
                'balance'          => (int) $r['balance'],
                'clubCount'        => (int) $r['clubCount'],
            ],
            $rows,
        );
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
