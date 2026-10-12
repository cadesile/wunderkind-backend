<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Club;
use App\Entity\SyncRecord;
use App\Entity\User;
use App\Entity\UserLedger;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserLedger>
 */
class UserLedgerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserLedger::class);
    }

    /**
     * The user's current overall centralized balance — the balanceAfterPence of their most
     * recently recorded entry, or 0 if they have none. Ordered by recordedAt then id (a
     * UuidV7 is itself time-ordered) so a tie on recordedAt still resolves deterministically.
     */
    public function getCurrentBalance(User $user): int
    {
        $result = $this->createQueryBuilder('l')
            ->select('l.balanceAfterPence')
            ->where('l.user = :user')
            ->setParameter('user', $user)
            ->orderBy('l.recordedAt', 'DESC')
            ->addOrderBy('l.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $result !== null ? (int) $result['balanceAfterPence'] : 0;
    }

    /**
     * Total pence drawn against one specific club — distinct from getCurrentBalance(), which
     * is the user's overall balance across every club they own. Used by the admin user profile
     * panel to show "earnings drawn from this club" alongside the club's own totalCareerEarnings.
     */
    public function getTotalDividendsByClub(Club $club): int
    {
        return (int) ($this->createQueryBuilder('l')
            ->select('SUM(l.amountPence)')
            ->where('l.club = :club')
            ->setParameter('club', $club)
            ->getQuery()
            ->getSingleScalarResult() ?? 0);
    }

    /**
     * The owners with the largest centralized balance, richest first — the landing
     * page's "Rich List" (OwnerSpotlightService).
     *
     * Each owner's figure is their most recent entry's `balanceAfterPence`, i.e. exactly
     * what getCurrentBalance() returns for that user, so the board and a single owner's
     * balance can never disagree. Ordered by `recorded_at` then `id` (a UuidV7 is itself
     * time-ordered) for the same deterministic tie-break getCurrentBalance() uses.
     *
     * Raw SQL for `DISTINCT ON`: "the latest row per user" needs a window function or a
     * correlated subquery, neither of which DQL expresses cleanly. Postgres-only, same as
     * SyncRecordRepository::findTopAttendanceSince().
     *
     * Only owners who actually hold ledger entries are returned — an owner with no
     * recorded dividend draw is absent, not listed at zero. The ledger holds
     * client-reported draws only, so this is deliberately NOT the same population as
     * "every user who owns a club".
     *
     * @return array<array{userId: string, ownerName: ?string, ownerNationality: ?string,
     *     ownerAppearance: ?array, balancePence: int}>
     */
    public function findTopOwnersByBalance(int $limit): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            "SELECT latest.user_id          AS user_id,
                    latest.balance_after_pence AS balance_pence,
                    u.name                  AS owner_name,
                    u.nationality           AS owner_nationality,
                    u.appearance            AS owner_appearance
             FROM (
                 SELECT DISTINCT ON (l.user_id)
                        l.user_id, l.balance_after_pence
                 FROM user_ledger l
                 ORDER BY l.user_id, l.recorded_at DESC, l.id DESC
             ) AS latest
             JOIN \"user\" u ON u.id = latest.user_id
             ORDER BY latest.balance_after_pence DESC
             LIMIT :limit",
            ['limit' => $limit],
            ['limit' => ParameterType::INTEGER],
        );

        return array_map(
            static fn (array $row): array => [
                'userId'           => (string) $row['user_id'],
                'ownerName'        => $row['owner_name'],
                'ownerNationality' => $row['owner_nationality'],
                // A json column comes back as a raw string over DBAL's fetchAllAssociative(),
                // unlike the hydrated arrays DQL returns.
                'ownerAppearance'  => $row['owner_appearance'] !== null
                    ? json_decode((string) $row['owner_appearance'], true)
                    : null,
                'balancePence'     => (int) $row['balance_pence'],
            ],
            $rows,
        );
    }

    /**
     * Total dividends drawn per club, shaped like the other aggregate leaderboard row
     * sets — backs the `career_earnings` category.
     *
     * Replaced `Club::$totalCareerEarnings` as that board's source: the accumulator is a
     * client-supplied running total of *every* income the club has ever had, whereas this
     * is money the owner actually took out. Clubs with no draws are absent, not zero —
     * LeaderboardCalculationService zeroes their stale rows explicitly.
     *
     * @return array<array{clubId: string, score: int, displayLabel: null}>
     */
    public function sumDividendsByClub(): array
    {
        $rows = $this->createQueryBuilder('l')
            ->select('IDENTITY(l.club) AS clubId', 'SUM(l.amountPence) AS score')
            ->groupBy('l.club')
            ->getQuery()
            ->getArrayResult();

        return array_map(
            static fn (array $row): array => [
                'clubId'       => (string) $row['clubId'],
                'score'        => (int) $row['score'],
                'displayLabel' => null,
            ],
            $rows,
        );
    }

    /** @return UserLedger[] */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('l')
            ->where('l.user = :user')
            ->setParameter('user', $user)
            ->orderBy('l.recordedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Whether an entry has already been recorded for this exact (sync record, ledger-array
     * index) pair — the idempotency check that makes re-running app:backfill-user-ledger, or
     * re-processing a resent sync, a safe no-op. Backs uq_user_ledger_source_entry.
     */
    public function existsForSource(SyncRecord $syncRecord, int $ledgerIndex): bool
    {
        $result = $this->createQueryBuilder('l')
            ->select('1')
            ->where('l.sourceSyncRecord = :syncRecord')
            ->andWhere('l.sourceLedgerIndex = :ledgerIndex')
            ->setParameter('syncRecord', $syncRecord)
            ->setParameter('ledgerIndex', $ledgerIndex)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $result !== null;
    }
}
