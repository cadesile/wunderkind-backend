<?php

declare(strict_types=1);

namespace App\Repository\Leaderboard;

use App\Entity\Leaderboard\LeaderboardAwardDefinition;
use App\Entity\Leaderboard\LeaderboardPeriodArchive;
use App\Enum\Leaderboard\PeriodArchiveStatus;
use App\Enum\LeaderboardCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LeaderboardPeriodArchive>
 */
class LeaderboardPeriodArchiveRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LeaderboardPeriodArchive::class);
    }

    /**
     * The claim lookup — keyed on (category, period_key), which is the unique the claim
     * INSERT contends on. Deliberately not keyed on the definition, which is nullable.
     */
    public function findOneForClaim(LeaderboardCategory $category, string $periodKey): ?LeaderboardPeriodArchive
    {
        return $this->findOneBy(['category' => $category, 'periodKey' => $periodKey]);
    }

    /**
     * A period that has been CLOSED — the read that makes a closed period read-only.
     *
     * Returns a row only in a terminal status: a `pending` archive means a close is in
     * flight or failed mid-way, and the live leaderboard_entry rows have NOT been pruned,
     * so the live board is still the truthful answer for it.
     */
    public function findClosed(LeaderboardCategory $category, string $periodKey): ?LeaderboardPeriodArchive
    {
        return $this->createQueryBuilder('a')
            ->where('a.category = :category')
            ->andWhere('a.periodKey = :periodKey')
            ->andWhere('a.status != :pending')
            ->setParameter('category', $category)
            ->setParameter('periodKey', $periodKey)
            ->setParameter('pending', PeriodArchiveStatus::PENDING)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * The landing page's "who won last period" read — the most recent ARCHIVED close per
     * definition, newest first, in one query on idx_lpa_cadence_closed.
     *
     * Skipped archives are excluded: a period with nothing to show is not a board worth a
     * card. Entries are fetched separately and in one batch by
     * LeaderboardPeriodArchiveEntryRepository::findTopEntriesForArchives().
     *
     * @return LeaderboardPeriodArchive[]
     */
    public function latestArchivedPerDefinition(int $limit = 4): array
    {
        /** @var LeaderboardPeriodArchive[] $rows */
        $rows = $this->createQueryBuilder('a')
            ->where('a.status = :archived')
            ->andWhere('a.archivedEntryCount > 0')
            ->setParameter('archived', PeriodArchiveStatus::ARCHIVED)
            ->orderBy('a.closedAt', 'DESC')
            ->addOrderBy('a.periodKey', 'DESC')
            // Over-fetch, because the per-definition collapse below happens in PHP: a
            // definition that closed four periods in a row would otherwise fill the whole
            // page with its own history.
            ->setMaxResults(max($limit * 8, $limit))
            ->getQuery()
            ->getResult();

        $byDefinition = [];
        foreach ($rows as $row) {
            // Key on the denormalised identity, not the nullable definition FK — an archive
            // whose definition was deleted still belongs to exactly one board.
            $key = $row->getCategory()->value . '/' . $row->getCadence()->value;

            if (!isset($byDefinition[$key])) {
                $byDefinition[$key] = $row;
            }

            if (count($byDefinition) >= $limit) {
                break;
            }
        }

        return array_values($byDefinition);
    }

    /**
     * Closed archives for one definition, newest period first — the admin detail read.
     *
     * @return LeaderboardPeriodArchive[]
     */
    public function findForDefinition(LeaderboardAwardDefinition $definition, int $limit = 24): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.definition = :definition')
            ->setParameter('definition', $definition)
            ->orderBy('a.periodKey', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Archives still pending past their claim lease — the operational signal that something
     * is failing mid-close.
     *
     * @return LeaderboardPeriodArchive[]
     */
    public function findStalePending(\DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.status = :pending')
            ->andWhere('a.claimedAt < :reclaimBefore')
            ->setParameter('pending', PeriodArchiveStatus::PENDING)
            ->setParameter('reclaimBefore', $now->modify('-' . LeaderboardPeriodArchive::CLAIM_LEASE_SECONDS . ' seconds'))
            ->orderBy('a.claimedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
