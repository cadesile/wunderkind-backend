<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Club;
use App\Entity\SyncRecord;
use App\Entity\User;
use App\Entity\UserLedger;
use App\Enum\UserLedgerEntryType;
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
            // FILTERED BY TYPE, and it must stay that way. This was an unfiltered
            // SUM(amountPence) when DIVIDEND_DRAW was the only entry kind. It no longer is:
            // LAND_PURCHASE and STADIUM_PURCHASE are stored NEGATIVE, so without this clause a
            // club's "earnings drawn" figure would be quietly reduced by its owner's spending.
            ->andWhere('l.type = :dividendDraw')
            ->setParameter('club', $club)
            ->setParameter('dividendDraw', UserLedgerEntryType::DIVIDEND_DRAW)
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
                 -- Restricted to accounts that have actually DRAWN a dividend, which is what
                 -- this board has always meant (see the docblock: \"deliberately NOT the same
                 -- population as every user who owns a club\"). Without it, the moment
                 -- STARTING_GRANT exists every account in the game appears on the public Rich
                 -- List at the grant value — turning a board about achievement into a roster.
                 WHERE EXISTS (
                     SELECT 1 FROM user_ledger d
                      WHERE d.user_id = l.user_id
                        AND d.type = :dividendDraw
                 )
                 ORDER BY l.user_id, l.recorded_at DESC, l.id DESC
             ) AS latest
             JOIN \"user\" u ON u.id = latest.user_id
             ORDER BY latest.balance_after_pence DESC
             LIMIT :limit",
            ['limit' => $limit, 'dividendDraw' => UserLedgerEntryType::DIVIDEND_DRAW->value],
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
            // THE HIGHEST-CONSEQUENCE FILTER IN THIS CLASS. This feeds
            // LeaderboardCategory::CAREER_EARNINGS, which is PUBLIC. It was an unfiltered sum
            // when dividend draws were the only rows in the table; the first negative
            // LAND_PURCHASE row would have silently deflated a club's public career-earnings
            // score, and a club that spent more than it drew would have gone negative on a
            // leaderboard whose score column is unsigned.
            ->andWhere('l.type = :dividendDraw')
            // club IS NULL on account-level entries (grants, pre-club purchases); they belong
            // to no club and must not be grouped into one.
            ->andWhere('l.club IS NOT NULL')
            ->setParameter('dividendDraw', UserLedgerEntryType::DIVIDEND_DRAW)
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

    /**
     * The backend-originated entry for this exact source, if one already exists.
     *
     * The read half of the idempotency guard that uq_user_ledger_backend_source enforces. Used
     * before writing so a repeated purchase returns the original entry rather than hitting the
     * unique index — a violation out of flush() would close the shared EntityManager, and this
     * runs on a user-facing write path.
     */
    public function findByBackendSource(
        string $sourceKind,
        string $sourceRef,
        UserLedgerEntryType $type,
    ): ?UserLedger {
        return $this->createQueryBuilder('l')
            ->where('l.sourceKind = :kind')
            ->andWhere('l.sourceRef = :ref')
            ->andWhere('l.type = :type')
            ->setParameter('kind', $sourceKind)
            ->setParameter('ref', $sourceRef)
            ->setParameter('type', $type)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * One page of a user's entries, newest first, for GET /api/account/ledger.
     *
     * KEYSET, NOT OFFSET. A ledger grows at the head: every new entry shifts an offset-paged
     * window, so a client reading page 2 after a sync lands on rows it already showed, or skips
     * rows entirely. A keyset cursor names a position rather than a distance, so inserts at the
     * head cannot disturb a page the client already holds.
     *
     * ORDERED BY recordedAt THEN id, exactly as getCurrentBalance() is, and for the same
     * reason: that method returns the balanceAfterPence of whichever row sorts first here, so
     * ordering the list any other way would let the headline balance disagree with the top row
     * of its own history. recordedAt is the historical server timestamp even for backfilled
     * rows (BackfillUserLedgerCommand passes SyncRecord::getServerTimestamp()), so this is a
     * true chronology and not an artefact of when the backfill ran. idx_user_ledger_user_recorded
     * serves it directly.
     *
     * Fetches limit + 1 rows so the caller can tell "more to come" from "that was the last page"
     * without a second COUNT query.
     *
     * @return UserLedger[] up to $limit + 1 rows
     */
    public function findPageForUser(
        User $user,
        int $limit,
        ?\DateTimeImmutable $afterRecordedAt = null,
        ?string $afterId = null,
    ): array {
        $qb = $this->createQueryBuilder('l')
            // JOIN-FETCHED so rendering `club` costs no extra query. Club is a to-one
            // association, so this is safe alongside setMaxResults() — the row count is
            // unchanged by the join, which is not true of fetch-joining a collection. LEFT,
            // because club is NULL on every account-level entry (grants, pre-club purchases).
            ->leftJoin('l.club', 'c')
            ->addSelect('c')
            ->where('l.user = :user')
            ->setParameter('user', $user)
            ->orderBy('l.recordedAt', 'DESC')
            ->addOrderBy('l.id', 'DESC')
            ->setMaxResults($limit + 1);

        if ($afterRecordedAt !== null && $afterId !== null) {
            // The row-wise comparison a keyset cursor needs. Spelled out rather than written as
            // a ROW() tuple because DQL has no tuple comparison: "strictly older, or the same
            // instant with a lower id".
            $qb->andWhere('(l.recordedAt < :cursorAt OR (l.recordedAt = :cursorAt AND l.id < :cursorId))')
                ->setParameter('cursorAt', $afterRecordedAt)
                ->setParameter('cursorId', $afterId);
        }

        return $qb->getQuery()->getResult();
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
