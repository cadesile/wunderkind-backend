<?php

namespace App\Service;

use App\Enum\StatsPeriod;
use App\Repository\SeasonRecordRepository;
use App\Repository\TransferRepository;

class CommunityStatsService
{
    public function __construct(
        private readonly TransferRepository $transferRepo,
        private readonly SeasonRecordRepository $seasonRecordRepo,
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

    /**
     * @param array<array{clubId: string, clubName: string, value: int|string}> $rows
     * @return array<array{clubId: string, clubName: string, value: int, rank: int}>
     */
    private function rank(array $rows): array
    {
        $result = [];
        foreach ($rows as $i => $row) {
            $result[] = [
                'clubId'   => (string) $row['clubId'],
                'clubName' => $row['clubName'],
                'value'    => (int) $row['value'],
                'rank'     => $i + 1,
            ];
        }

        return $result;
    }
}
