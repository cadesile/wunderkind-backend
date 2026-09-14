<?php

namespace App\Tests\Service;

use App\Enum\StatsPeriod;
use App\Repository\MatchResultRepository;
use App\Repository\PlayerCareerStatSnapshotRepository;
use App\Repository\SeasonRecordRepository;
use App\Repository\TransferRepository;
use App\Service\CommunityStatsService;
use App\Service\PeriodResolver;
use PHPUnit\Framework\TestCase;

class CommunityStatsServiceTest extends TestCase
{
    private function makeService(
        ?TransferRepository $transferRepo = null,
        ?SeasonRecordRepository $seasonRepo = null,
        ?MatchResultRepository $matchResultRepo = null,
        ?PlayerCareerStatSnapshotRepository $careerSnapshotRepo = null,
    ): CommunityStatsService {
        return new CommunityStatsService(
            $transferRepo ?? $this->createStub(TransferRepository::class),
            $seasonRepo ?? $this->createStub(SeasonRecordRepository::class),
            $matchResultRepo ?? $this->createStub(MatchResultRepository::class),
            $careerSnapshotRepo ?? $this->createStub(PlayerCareerStatSnapshotRepository::class),
            $this->createStub(PeriodResolver::class),
        );
    }

    public function testGetMostTransfersRanksDescendingByValue(): void
    {
        $transferRepo = $this->createStub(TransferRepository::class);
        $transferRepo->method('getMostTransfersByClub')->willReturn([
            ['clubId' => 'a', 'clubName' => 'Alpha', 'value' => 30],
            ['clubId' => 'b', 'clubName' => 'Beta', 'value' => 20],
            ['clubId' => 'c', 'clubName' => 'Gamma', 'value' => 10],
        ]);

        $service = $this->makeService(transferRepo: $transferRepo);
        $result = $service->getMostTransfers(StatsPeriod::ALL, 10);

        $this->assertSame([
            ['clubId' => 'a', 'clubName' => 'Alpha', 'value' => 30, 'rank' => 1, 'playerName' => null, 'rawValue' => 30, 'secondaryValue' => null],
            ['clubId' => 'b', 'clubName' => 'Beta', 'value' => 20, 'rank' => 2, 'playerName' => null, 'rawValue' => 20, 'secondaryValue' => null],
            ['clubId' => 'c', 'clubName' => 'Gamma', 'value' => 10, 'rank' => 3, 'playerName' => null, 'rawValue' => 10, 'secondaryValue' => null],
        ], $result);
    }

    public function testGetMostDevelopmentReturnsEmptyResultsWhenNoData(): void
    {
        $transferRepo = $this->createStub(TransferRepository::class);
        $transferRepo->method('getMostDevelopmentByClub')->willReturn([]);

        $service = $this->makeService(transferRepo: $transferRepo);

        $this->assertSame([], $service->getMostDevelopment(StatsPeriod::WEEK, 10));
    }

    public function testValueIsCastToIntEvenWhenRepositoryReturnsNumericString(): void
    {
        $transferRepo = $this->createStub(TransferRepository::class);
        $transferRepo->method('getMostDevelopmentByClub')->willReturn([
            ['clubId' => 'a', 'clubName' => 'Alpha', 'value' => '42'],
        ]);

        $service = $this->makeService(transferRepo: $transferRepo);
        $result = $service->getMostDevelopment(StatsPeriod::ALL, 10);

        $this->assertSame(42, $result[0]['value']);
    }

    public function testValueIsRoundedNotTruncatedForFractionalAggregates(): void
    {
        // Regression guard: (int) 0.67 === 0 would silently zero out FORTRESS_DEFENCE's
        // AVG(goalsAgainst). round() first, then cast.
        $matchResultRepo = $this->createStub(MatchResultRepository::class);
        $matchResultRepo->method('getBestDefenceByClub')->willReturn([
            ['clubId' => 'a', 'clubName' => 'Alpha', 'value' => '0.67'],
        ]);

        $service = $this->makeService(matchResultRepo: $matchResultRepo);
        $result = $service->getFortressDefence(StatsPeriod::WEEK, 10);

        $this->assertSame(1, $result[0]['value']);
        $this->assertSame('0.67', $result[0]['rawValue']);
    }

    public function testGetMostSeasonsDelegatesToSeasonRecordRepository(): void
    {
        $seasonRepo = $this->createStub(SeasonRecordRepository::class);
        $seasonRepo->method('getMostSeasonsByClub')->willReturn([
            ['clubId' => 'a', 'clubName' => 'Alpha', 'value' => 5],
        ]);

        $service = $this->makeService(seasonRepo: $seasonRepo);
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

        $service = $this->makeService(seasonRepo: $seasonRepo);
        $result = $service->getMostTrophies(StatsPeriod::ALL, 10);

        $this->assertSame(1, $result[0]['rank']);
        $this->assertSame(2, $result[0]['value']);
    }

    public function testGetBestFormDelegatesToMatchResultRepositoryAndCarriesSecondaryValue(): void
    {
        $matchResultRepo = $this->createStub(MatchResultRepository::class);
        $matchResultRepo->method('getMostWinsByClub')->willReturn([
            ['clubId' => 'a', 'clubName' => 'Alpha', 'value' => 5, 'secondaryValue' => 8],
        ]);

        $service = $this->makeService(matchResultRepo: $matchResultRepo);
        $result = $service->getBestForm(StatsPeriod::LAST_24_HOURS, 10);

        $this->assertSame(5, $result[0]['value']);
        $this->assertSame(8, $result[0]['secondaryValue']);
    }

    public function testGetBiggestRoutDelegatesToMatchResultRepository(): void
    {
        $matchResultRepo = $this->createStub(MatchResultRepository::class);
        $matchResultRepo->method('getBiggestRoutByClub')->willReturn([
            ['clubId' => 'a', 'clubName' => 'Alpha', 'value' => 6],
        ]);

        $service = $this->makeService(matchResultRepo: $matchResultRepo);
        $result = $service->getBiggestRout(StatsPeriod::WEEK, 10);

        $this->assertSame(6, $result[0]['value']);
        $this->assertNull($result[0]['secondaryValue']);
    }

    public function testGetFortressDefenceDelegatesToMatchResultRepository(): void
    {
        $matchResultRepo = $this->createStub(MatchResultRepository::class);
        $matchResultRepo->method('getBestDefenceByClub')->willReturn([
            ['clubId' => 'a', 'clubName' => 'Alpha', 'value' => 1],
        ]);

        $service = $this->makeService(matchResultRepo: $matchResultRepo);
        $result = $service->getFortressDefence(StatsPeriod::MONTH, 10);

        $this->assertSame(1, $result[0]['value']);
    }

    public function testGetTransferSplurgeDelegatesToTransferRepository(): void
    {
        $transferRepo = $this->createStub(TransferRepository::class);
        $transferRepo->method('getBiggestSpendersByClub')->willReturn([
            ['clubId' => 'a', 'clubName' => 'Alpha', 'value' => 125000000],
        ]);

        $service = $this->makeService(transferRepo: $transferRepo);
        $result = $service->getTransferSplurge(StatsPeriod::WEEK, 10);

        $this->assertSame(125000000, $result[0]['value']);
    }

    public function testGetSuperStrikerDelegatesToPlayerCareerStatSnapshotRepositoryAndCarriesPlayerName(): void
    {
        $careerSnapshotRepo = $this->createStub(PlayerCareerStatSnapshotRepository::class);
        $careerSnapshotRepo->method('topGoalScorerInWindowByClub')->willReturn([
            ['clubId' => 'a', 'clubName' => 'Alpha', 'value' => 4, 'playerName' => 'J. Silva'],
        ]);

        $service = $this->makeService(careerSnapshotRepo: $careerSnapshotRepo);
        $result = $service->getSuperStriker(StatsPeriod::LAST_24_HOURS, 10);

        $this->assertSame(4, $result[0]['value']);
        $this->assertSame('J. Silva', $result[0]['playerName']);
    }
}
