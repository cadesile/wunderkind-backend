<?php

namespace App\Service;

use App\Enum\StatsPeriod;
use App\Repository\ClubRepository;
use App\Repository\MatchResultRepository;
use App\Repository\PlayerCareerStatSnapshotRepository;
use App\Repository\SeasonRecordRepository;
use App\Repository\TransferRepository;

class CommunityStatsService
{
    public function __construct(
        private readonly TransferRepository $transferRepo,
        private readonly SeasonRecordRepository $seasonRecordRepo,
        private readonly MatchResultRepository $matchResultRepo,
        private readonly PlayerCareerStatSnapshotRepository $careerSnapshotRepo,
        private readonly PeriodResolver $periodResolver,
        private readonly ClubRepository $clubRepository,
    ) {
    }

    public function getMostTransfers(StatsPeriod $period, int $limit): array
    {
        return $this->rank($this->transferRepo->getMostTransfersByClub($period, $limit, $this->periodResolver));
    }

    /**
     * Ranks clubs by Transfer::$developmentPoints — a field NOTHING currently writes.
     * `TransferSyncDto` has no developmentPoints member, so no sync can populate it,
     * and the only writer in the codebase is app:seed-community-stats-fixtures. Every
     * production row is therefore 0 and this endpoint ranks an all-zero column.
     *
     * Kept (rather than deleted) because it is a published endpoint — see
     * docs/api/community-stats.md — and will start returning real figures the moment
     * the client begins reporting CA gained per transfer. The landing page deliberately
     * shows getMostWins() in its place; do not put this back on the page until the
     * field is actually fed.
     */
    public function getMostDevelopment(StatsPeriod $period, int $limit): array
    {
        return $this->rank($this->transferRepo->getMostDevelopmentByClub($period, $limit, $this->periodResolver));
    }

    /**
     * Ranks clubs by matches won in the period, tiebroken on goal difference. Backed by
     * real MatchResult rows, which every syncing client writes — this is what the
     * landing page shows in the "most development" slot.
     */
    public function getMostWins(StatsPeriod $period, int $limit): array
    {
        return $this->rank($this->matchResultRepo->getMostWinsByClub($period, $limit, $this->periodResolver));
    }

    public function getMostSeasons(StatsPeriod $period, int $limit): array
    {
        return $this->rank($this->seasonRecordRepo->getMostSeasonsByClub($period, $limit, $this->periodResolver));
    }

    public function getMostTrophies(StatsPeriod $period, int $limit): array
    {
        return $this->rank($this->seasonRecordRepo->getMostTrophiesByClub($period, $limit, $this->periodResolver));
    }

    public function getBestForm(StatsPeriod $period, int $limit): array
    {
        return $this->rank($this->matchResultRepo->getMostWinsByClub($period, $limit, $this->periodResolver));
    }

    public function getBiggestRout(StatsPeriod $period, int $limit): array
    {
        return $this->rank($this->matchResultRepo->getBiggestRoutByClub($period, $limit, $this->periodResolver));
    }

    public function getFortressDefence(StatsPeriod $period, int $limit): array
    {
        return $this->rank($this->matchResultRepo->getBestDefenceByClub($period, $limit, $this->periodResolver));
    }

    public function getTransferSplurge(StatsPeriod $period, int $limit): array
    {
        return $this->rank($this->transferRepo->getBiggestSpendersByClub($period, $limit, $this->periodResolver));
    }

    public function getSuperStriker(StatsPeriod $period, int $limit): array
    {
        return $this->rank($this->careerSnapshotRepo->topGoalScorerInWindowByClub($period, $limit, $this->periodResolver));
    }

    /**
     * Assigns ranks and attaches each club's visual identity.
     *
     * The kit/badge/country lookup is one extra query over the ≤50 already-ranked
     * club ids, deliberately NOT folded into each ranking query: those are GROUP BY
     * aggregates, and widening six of them with three json columns apiece would mean
     * six places to keep in step for one presentational concern.
     *
     * @param array<array{clubId: string, clubName: string, value: int|string, playerName?: string, secondaryValue?: int|string}> $rows
     * @return array<array{clubId: string, clubName: string, value: int, rank: int, playerName: ?string,
     *     rawValue: int|string, secondaryValue: int|string|null, country: ?string,
     *     homeKitConfig: ?array, awayKitConfig: ?array, badgeConfig: ?array}>
     */
    private function rank(array $rows): array
    {
        $clubs = $this->clubRepository->findByIdsKeyed(array_map(
            static fn (array $row): string => (string) $row['clubId'],
            $rows,
        ));

        $result = [];
        foreach ($rows as $i => $row) {
            $clubId = (string) $row['clubId'];
            $club   = $clubs[$clubId] ?? null;

            $result[] = [
                'clubId'         => $clubId,
                'clubName'       => $row['clubName'],
                'value'          => (int) round((float) $row['value']),
                'rank'           => $i + 1,
                'playerName'     => $row['playerName'] ?? null,
                'rawValue'       => $row['value'],
                'secondaryValue' => $row['secondaryValue'] ?? null,
                // Same {country, homeKitConfig, awayKitConfig, badgeConfig} identity
                // block LeaderboardItemDto emits, so one client-side renderer serves
                // both boards. Null throughout if the club has since been deleted —
                // the ranking rows outlive it, since they are aggregates.
                'country'        => $club?->getCountry(),
                'homeKitConfig'  => $club?->getHomeKitConfig(),
                'awayKitConfig'  => $club?->getAwayKitConfig(),
                'badgeConfig'    => $club?->getBadgeConfig(),
            ];
        }

        return $result;
    }
}
