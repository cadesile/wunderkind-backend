<?php

namespace App\Service;

use App\Enum\StatsPeriod;
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
    ) {
    }

    public function getMostTransfers(StatsPeriod $period, int $limit): array
    {
        return $this->rank($this->transferRepo->getMostTransfersByClub($period, $limit, $this->periodResolver));
    }

    public function getMostDevelopment(StatsPeriod $period, int $limit): array
    {
        return $this->rank($this->transferRepo->getMostDevelopmentByClub($period, $limit, $this->periodResolver));
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
     * @param array<array{clubId: string, clubName: string, value: int|string, playerName?: string, secondaryValue?: int|string}> $rows
     * @return array<array{clubId: string, clubName: string, value: int, rank: int, playerName: ?string, rawValue: int|string, secondaryValue: int|string|null}>
     */
    private function rank(array $rows): array
    {
        $result = [];
        foreach ($rows as $i => $row) {
            $result[] = [
                'clubId'         => (string) $row['clubId'],
                'clubName'       => $row['clubName'],
                'value'          => (int) round((float) $row['value']),
                'rank'           => $i + 1,
                'playerName'     => $row['playerName'] ?? null,
                'rawValue'       => $row['value'],
                'secondaryValue' => $row['secondaryValue'] ?? null,
            ];
        }

        return $result;
    }
}
