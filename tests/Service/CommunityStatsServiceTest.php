<?php

namespace App\Tests\Service;

use App\Enum\StatsPeriod;
use App\Repository\SeasonRecordRepository;
use App\Repository\TransferRepository;
use App\Service\CommunityStatsService;
use App\Service\PeriodResolver;
use PHPUnit\Framework\TestCase;

class CommunityStatsServiceTest extends TestCase
{
    private function makeService(TransferRepository $transferRepo, SeasonRecordRepository $seasonRepo): CommunityStatsService
    {
        return new CommunityStatsService($transferRepo, $seasonRepo, $this->createStub(PeriodResolver::class));
    }

    public function testGetMostTransfersRanksDescendingByValue(): void
    {
        $transferRepo = $this->createStub(TransferRepository::class);
        $transferRepo->method('getMostTransfersByClub')->willReturn([
            ['clubId' => 'a', 'clubName' => 'Alpha', 'value' => 30],
            ['clubId' => 'b', 'clubName' => 'Beta', 'value' => 20],
            ['clubId' => 'c', 'clubName' => 'Gamma', 'value' => 10],
        ]);

        $service = $this->makeService($transferRepo, $this->createStub(SeasonRecordRepository::class));
        $result = $service->getMostTransfers(StatsPeriod::ALL, 10);

        $this->assertSame([
            ['clubId' => 'a', 'clubName' => 'Alpha', 'value' => 30, 'rank' => 1],
            ['clubId' => 'b', 'clubName' => 'Beta', 'value' => 20, 'rank' => 2],
            ['clubId' => 'c', 'clubName' => 'Gamma', 'value' => 10, 'rank' => 3],
        ], $result);
    }

    public function testGetMostDevelopmentReturnsEmptyResultsWhenNoData(): void
    {
        $transferRepo = $this->createStub(TransferRepository::class);
        $transferRepo->method('getMostDevelopmentByClub')->willReturn([]);

        $service = $this->makeService($transferRepo, $this->createStub(SeasonRecordRepository::class));

        $this->assertSame([], $service->getMostDevelopment(StatsPeriod::WEEK, 10));
    }

    public function testValueIsCastToIntEvenWhenRepositoryReturnsNumericString(): void
    {
        $transferRepo = $this->createStub(TransferRepository::class);
        $transferRepo->method('getMostDevelopmentByClub')->willReturn([
            ['clubId' => 'a', 'clubName' => 'Alpha', 'value' => '42'],
        ]);

        $service = $this->makeService($transferRepo, $this->createStub(SeasonRecordRepository::class));
        $result = $service->getMostDevelopment(StatsPeriod::ALL, 10);

        $this->assertSame(42, $result[0]['value']);
    }

    public function testGetMostSeasonsDelegatesToSeasonRecordRepository(): void
    {
        $seasonRepo = $this->createStub(SeasonRecordRepository::class);
        $seasonRepo->method('getMostSeasonsByClub')->willReturn([
            ['clubId' => 'a', 'clubName' => 'Alpha', 'value' => 5],
        ]);

        $service = $this->makeService($this->createStub(TransferRepository::class), $seasonRepo);
        $result = $service->getMostSeasons(StatsPeriod::SEASON, 10);

        $this->assertSame(1, $result[0]['rank']);
        $this->assertSame(5, $result[0]['value']);
    }

    public function testGetMostTrophiesDelegatesToSeasonRecordRepository(): void
    {
        $seasonRepo = $this->createStub(SeasonRecordRepository::class);
        $seasonRepo->method('getMostTrophiesByClub')->willReturn([
            ['clubId' => 'a', 'clubName' => 'Alpha', 'value' => 2],
        ]);

        $service = $this->makeService($this->createStub(TransferRepository::class), $seasonRepo);
        $result = $service->getMostTrophies(StatsPeriod::ALL, 10);

        $this->assertSame(1, $result[0]['rank']);
        $this->assertSame(2, $result[0]['value']);
    }
}
