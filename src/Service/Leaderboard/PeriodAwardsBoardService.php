<?php

declare(strict_types=1);

namespace App\Service\Leaderboard;

use App\Entity\Leaderboard\LeaderboardPeriodArchive;
use App\Entity\Leaderboard\LeaderboardPeriodArchiveEntry;
use App\Repository\Leaderboard\LeaderboardPeriodArchiveEntryRepository;
use App\Repository\Leaderboard\LeaderboardPeriodArchiveRepository;

/**
 * "Who won last period" for the landing page — ready-to-render rows, so nothing is summed
 * or re-derived in Twig.
 *
 * NO new snapshot entity and NO cache layer, and the reasoning is worth keeping:
 * ClubSpotlight is a snapshot row because its source is an aggregate over sync_record
 * payloads, far too expensive per request; OwnerSpotlightService is live behind a short
 * cache because it is two indexed queries and a snapshot would mean a migration and a cron
 * entry to forget. This case is better than either, because the ARCHIVE ALREADY IS the
 * cron-written denormalised row — it carries frozen club name, kit, badge and country, and
 * it never changes once written.
 *
 * Two indexed queries total. The entry fetch is BATCHED for the same reason
 * findByEntrants() / findBothSides() exist in the competition code: a snapshot must not be
 * traded for an N+1 of the same size, and the landing page renders every listed board at
 * once.
 *
 * If the enabled-definition count grows enough that four boards becomes forty, wrap
 * getBoards() in the same short cache WorldOverviewService uses. Not built now, because one
 * query on idx_lpa_cadence_closed plus one keyed IN() is cheaper than the cache lookup
 * would be at this size.
 */
class PeriodAwardsBoardService
{
    /** Rows shown per board. The design's own grid width, not a data limit. */
    public const ENTRIES_PER_BOARD = 5;

    public function __construct(
        private readonly LeaderboardPeriodArchiveRepository $archives,
        private readonly LeaderboardPeriodArchiveEntryRepository $entries,
    ) {}

    /**
     * The most recently closed period per definition, newest first, each with its top rows.
     *
     * Returns [] when nothing has been archived — which is the real state for every
     * environment until an admin enables a definition and a period ends, and which the
     * section and the nav link are both gated on.
     *
     * @return list<array<string, mixed>>
     */
    public function getBoards(int $limit = 4): array
    {
        $archives = $this->archives->latestArchivedPerDefinition($limit);

        if ($archives === []) {
            return [];
        }

        $archiveIds = array_map(
            static fn (LeaderboardPeriodArchive $a): string => (string) $a->getId(),
            $archives,
        );

        $entriesByArchive = $this->entries->findTopEntriesForArchives($archiveIds, self::ENTRIES_PER_BOARD);

        $boards = [];

        foreach ($archives as $archive) {
            $rows = $entriesByArchive[(string) $archive->getId()] ?? [];

            if ($rows === []) {
                continue;
            }

            $boards[] = [
                'name'        => $archive->getDefinitionName(),
                'category'    => $archive->getCategory()->value,
                'cadence'     => $archive->getCadence()->value,
                'cadenceLabel' => $archive->getCadence()->label(),
                'period'      => $archive->getPeriodKey(),
                // From the FROZEN instants, never re-derived from the key — the archive
                // exists so no reader has to parse a period string again.
                'periodLabel' => $archive->getPeriodStartsAt()->format('F Y'),
                'periodStart' => $archive->getPeriodStartsAt(),
                'periodEnd'   => $archive->getPeriodEndsAt(),
                'closedAt'    => $archive->getClosedAt(),
                'entryCount'  => $archive->getEntryCount(),
                'awardCount'  => $archive->getPlacementsAwarded(),
                'entries'     => array_map($this->row(...), $rows),
            ];
        }

        return $boards;
    }

    /**
     * One frozen row, flattened for the template.
     *
     * Club identity comes off the archive, not off the club — which is exactly why it was
     * frozen. A club that has since been deleted still renders its badge, which is what
     * keeps the page's "any club listed anywhere shows at least its badge" rule true.
     *
     * @return array<string, mixed>
     */
    private function row(LeaderboardPeriodArchiveEntry $entry): array
    {
        return [
            'rank'          => $entry->getRank(),
            'clubName'      => $entry->getClubName(),
            'score'         => $entry->getScore(),
            'displayLabel'  => $entry->getDisplayLabel(),
            'awarded'       => $entry->isAwarded(),
            'place'         => $entry->getPlace(),
            'countryCode'   => $entry->getCountryCode(),
            'homeKitConfig' => $entry->getHomeKitConfig(),
            'awayKitConfig' => $entry->getAwayKitConfig(),
            'badgeConfig'   => $entry->getBadgeConfig(),
        ];
    }
}
