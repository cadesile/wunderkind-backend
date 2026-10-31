<?php

namespace App\Repository;

use App\Entity\Club;
use App\Entity\LeaderboardEntry;
use App\Enum\LeaderboardCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

class LeaderboardEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LeaderboardEntry::class);
    }

    /**
     * All entries for a category/period regardless of club eligibility — used only where
     * every stored row must be inspected (e.g. resetting stale scores), never for a public read.
     */
    public function findAllForCategoryAndPeriod(LeaderboardCategory $category, string $period): array
    {
        return $this->createQueryBuilder('e')
            ->join('e.club', 'c')
            ->addSelect('c')
            ->where('e.category = :category')
            ->andWhere('e.period = :period')
            ->setParameter('category', $category)
            ->setParameter('period', $period)
            ->orderBy('e.score', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * All entries for a category/period, ordered by score descending (full result — used for ranking passes and cache hydration).
     * Only eligible clubs are surfaced — see applyEligibility().
     */
    public function findAllOrderedByScore(LeaderboardCategory $category, string $period): array
    {
        $qb = $this->createQueryBuilder('e')
            ->join('e.club', 'c')
            ->addSelect('c')
            ->where('e.category = :category')
            ->andWhere('e.period = :period')
            ->setParameter('category', $category)
            ->setParameter('period', $period)
            ->orderBy('e.score', 'DESC');

        return self::applyEligibility($qb)->getQuery()->getResult();
    }

    /**
     * The ranked, eligible board for one category/period — the close job's read.
     *
     * score DESC, club_id ASC: deterministic, so a reclaimed retry re-reading the same data
     * produces the same places. A tie therefore goes to the lower UUID, which is arbitrary
     * but stable, and stability is the property that matters for an award.
     *
     * addSelect on the club AND its user, so the award's frozen user_id and the inbox
     * message's club both arrive in one query rather than one lazy-load per rank.
     *
     * @return LeaderboardEntry[]
     */
    public function findRankedForAward(LeaderboardCategory $category, string $period, int $limit): array
    {
        $qb = $this->createQueryBuilder('e')
            ->join('e.club', 'c')
            ->addSelect('c')
            ->leftJoin('c.user', 'u')
            ->addSelect('u')
            ->where('e.category = :category')
            ->andWhere('e.period = :period')
            ->setParameter('category', $category)
            ->setParameter('period', $period)
            ->orderBy('e.score', 'DESC')
            ->addOrderBy('c.id', 'ASC')
            ->setMaxResults($limit);

        return self::applyEligibility($qb)->getQuery()->getResult();
    }

    /** How many eligible clubs are on this board — reported on the archive as entry_count. */
    public function countEligible(LeaderboardCategory $category, string $period): int
    {
        $qb = $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->join('e.club', 'c')
            ->where('e.category = :category')
            ->andWhere('e.period = :period')
            ->setParameter('category', $category)
            ->setParameter('period', $period);

        return (int) self::applyEligibility($qb)->getQuery()->getSingleScalarResult();
    }

    /**
     * Drops a closed period's live rows. Raw SQL, and called only AFTER the flush that
     * commits the archive has succeeded: committing a delete before a failed flush would
     * clear the board with nothing archived — the *_squad-prune precedent.
     */
    public function deleteForPeriod(LeaderboardCategory $category, string $period): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM leaderboard_entry WHERE category = :category AND period = :period',
            ['category' => $category->value, 'period' => $period],
        );
    }

    /**
     * THE definition of "may appear on, and may win, a leaderboard". Hoisted into one
     * place because the award path and the public board must agree: a prize paid to a
     * club the board does not list is indefensible either way round.
     *
     * Two predicates, both on the already-joined club alias 'c':
     *
     *  - currentSeason > 1 — a club still on its first season has no completed body of
     *    work to compare. Pre-existing behaviour, unchanged.
     *  - isSpoof = false — admin-generated spoof clubs (CompetitionSpoofEntrantService).
     *    NEW, and a visible behaviour change: spoof clubs disappear from live public
     *    boards, shifting `total` and every rank below them. Only ClubRepository had ever
     *    filtered them; the board never did. Paying a bot a real cash prize is the actual
     *    bug this closes, and once the predicate is shared, leaving the public board
     *    inconsistent with the award path is the worse outcome. LeaderboardEligibilityTest
     *    is the record of the change.
     *
     * The caller owns the join and the alias; this only adds the WHERE clauses.
     */
    public static function applyEligibility(QueryBuilder $qb, string $clubAlias = 'c'): QueryBuilder
    {
        return $qb
            ->andWhere("{$clubAlias}.currentSeason > 1")
            ->andWhere("{$clubAlias}.isSpoof = false");
    }

    /**
     * Every existing entry for one club in one category, across all periods.
     *
     * Used by app:reputation:decay to write a decayed reputation through to the board.
     * club_reputation is a non-aggregate category — its score is only ever upserted by
     * SyncService at sync time, and app:leaderboards:generate merely re-ranks it — so a
     * club that stops syncing would otherwise sit on its last-synced score forever,
     * which is exactly the dormant-club case decay exists to correct.
     *
     * Deliberately does NOT create missing periods: a club that has not synced this
     * ISO week has no entry for it, and inventing one would put a dormant club onto a
     * weekly board it never appeared on.
     *
     * @return LeaderboardEntry[]
     */
    public function findAllForClubAndCategory(Club $club, LeaderboardCategory $category): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.club = :club')
            ->andWhere('e.category = :category')
            ->setParameter('club', $club)
            ->setParameter('category', $category)
            ->getQuery()
            ->getResult();
    }

    public function findOrCreate(Club $club, LeaderboardCategory $category, string $period): LeaderboardEntry
    {
        $entry = $this->findOneBy([
            'club'  => $club,
            'category' => $category,
            'period'   => $period,
        ]);

        if ($entry === null) {
            $entry = new LeaderboardEntry($club, $category, $period);
            $this->getEntityManager()->persist($entry);
        }

        return $entry;
    }
}
