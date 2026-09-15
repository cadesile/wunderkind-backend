<?php

namespace App\Tests\Service;

use App\Service\LiveTelemetryService;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the pure aggregation steps behind the landing page's
 * "Chairman's Terminal" widget. The DB-backed fetches (findValidPayloadsSince,
 * findLatestValidPayloadPerClub{Since,Before}) are not exercised here — this
 * only covers the counting/summing/diffing formulas.
 */
class LiveTelemetryServiceTest extends TestCase
{
    public function testSumsUpkeepAndWagesLedgerEntriesAsCapitalDeployed(): void
    {
        $result = LiveTelemetryService::aggregateCapitalDeployed([
            ['ledger' => [
                ['category' => 'upkeep', 'amount' => -23625000],
                ['category' => 'wages', 'amount' => -31940000],
            ]],
        ]);

        $this->assertSame(23625000 + 31940000, $result);
    }

    public function testExcludesRevenueLedgerCategories(): void
    {
        $result = LiveTelemetryService::aggregateCapitalDeployed([
            ['ledger' => [
                ['category' => 'matchday_income', 'amount' => 32220000],
                ['category' => 'sponsor_payment', 'amount' => 19506000],
            ]],
        ]);

        $this->assertSame(0, $result);
    }

    public function testCountsSigningAndAgentAssistedTransfersAsCapitalDeployed(): void
    {
        $result = LiveTelemetryService::aggregateCapitalDeployed([
            ['transfers' => [
                ['type' => 'signing', 'grossFee' => 35000000],
                ['type' => 'agent_assisted', 'grossFee' => 5000000],
            ]],
        ]);

        $this->assertSame(40000000, $result);
    }

    public function testExcludesSaleAndOtherOutgoingTransferTypes(): void
    {
        $result = LiveTelemetryService::aggregateCapitalDeployed([
            ['transfers' => [
                ['type' => 'sale', 'grossFee' => 35000000],
                ['type' => 'loan', 'grossFee' => 1000000],
                ['type' => 'free_release', 'grossFee' => 0],
                ['type' => 'guardian_withdrawal', 'grossFee' => 0],
            ]],
        ]);

        $this->assertSame(0, $result);
    }

    public function testCombinesLedgerAndTransferSpendAcrossPayloads(): void
    {
        $result = LiveTelemetryService::aggregateCapitalDeployed([
            ['ledger' => [['category' => 'upkeep', 'amount' => -100]], 'transfers' => [['type' => 'signing', 'grossFee' => 5000]]],
            ['ledger' => [['category' => 'sponsor_payment', 'amount' => 200]], 'transfers' => [['type' => 'sale', 'grossFee' => 9000]]],
        ]);

        $this->assertSame(5100, $result);
    }

    public function testCapitalDeployedHandlesEmptyOrMissingKeysGracefully(): void
    {
        $result = LiveTelemetryService::aggregateCapitalDeployed([[], ['ledger' => []], ['transfers' => []]]);

        $this->assertSame(0, $result);
    }

    public function testFixturesAndGoalsAreTheChangeInEachClubsSeasonRecord(): void
    {
        $latest = [
            'club-a' => ['seasonRecord' => ['wins' => 10, 'draws' => 2, 'losses' => 3, 'goalsFor' => 40]],
        ];
        $baseline = [
            'club-a' => ['seasonRecord' => ['wins' => 8, 'draws' => 2, 'losses' => 2, 'goalsFor' => 32]],
        ];

        $result = LiveTelemetryService::aggregateSeasonActivity($latest, $baseline);

        // games: (10+2+3) - (8+2+2) = 15 - 12 = 3; goals: 40 - 32 = 8
        $this->assertSame(3, $result['fixturesSimulated']);
        $this->assertSame(8, $result['goalsScored']);
    }

    public function testSumsDeltasAcrossMultipleClubs(): void
    {
        $latest = [
            'club-a' => ['seasonRecord' => ['wins' => 5, 'draws' => 0, 'losses' => 0, 'goalsFor' => 10]],
            'club-b' => ['seasonRecord' => ['wins' => 1, 'draws' => 1, 'losses' => 1, 'goalsFor' => 6]],
        ];
        $baseline = [
            'club-a' => ['seasonRecord' => ['wins' => 4, 'draws' => 0, 'losses' => 0, 'goalsFor' => 9]],
            'club-b' => ['seasonRecord' => ['wins' => 0, 'draws' => 1, 'losses' => 1, 'goalsFor' => 4]],
        ];

        $result = LiveTelemetryService::aggregateSeasonActivity($latest, $baseline);

        // club-a: 1 game, 1 goal. club-b: 1 game, 2 goals. Totals: 2 games, 3 goals.
        $this->assertSame(2, $result['fixturesSimulated']);
        $this->assertSame(3, $result['goalsScored']);
    }

    public function testClubWithNoBaselineContributesNothing(): void
    {
        $latest = [
            'club-new' => ['seasonRecord' => ['wins' => 4, 'draws' => 1, 'losses' => 0, 'goalsFor' => 15]],
        ];

        $result = LiveTelemetryService::aggregateSeasonActivity($latest, []);

        $this->assertSame(0, $result['fixturesSimulated']);
        $this->assertSame(0, $result['goalsScored']);
    }

    /** A season rollover resets the cumulative totals — clamp to 0 rather than go negative. */
    public function testNegativeDeltaFromASeasonRolloverClampsToZero(): void
    {
        $latest = [
            'club-a' => ['seasonRecord' => ['wins' => 1, 'draws' => 0, 'losses' => 0, 'goalsFor' => 2]],
        ];
        $baseline = [
            'club-a' => ['seasonRecord' => ['wins' => 20, 'draws' => 5, 'losses' => 3, 'goalsFor' => 60]],
        ];

        $result = LiveTelemetryService::aggregateSeasonActivity($latest, $baseline);

        $this->assertSame(0, $result['fixturesSimulated']);
        $this->assertSame(0, $result['goalsScored']);
    }

    public function testSeasonActivityHandlesMissingSeasonRecordGracefully(): void
    {
        $result = LiveTelemetryService::aggregateSeasonActivity(
            ['club-a' => []],
            ['club-a' => []],
        );

        $this->assertSame(0, $result['fixturesSimulated']);
        $this->assertSame(0, $result['goalsScored']);
    }

    public function testTitleTakesPriorityOverPromotedForTheSameRow(): void
    {
        $now = new \DateTimeImmutable();
        $events = LiveTelemetryService::buildEvents([
            ['tier' => 3, 'promoted' => true, 'relegated' => false, 'finalPosition' => 1, 'createdAt' => $now],
        ], $now);

        $this->assertSame('A Tier 3 club lifted the title.', $events[0]['text']);
    }

    public function testPromotedRowWithoutTitleDescribesPromotion(): void
    {
        $now = new \DateTimeImmutable();
        $events = LiveTelemetryService::buildEvents([
            ['tier' => 5, 'promoted' => true, 'relegated' => false, 'finalPosition' => 2, 'createdAt' => $now],
        ], $now);

        $this->assertSame('A Tier 5 club won promotion.', $events[0]['text']);
    }

    public function testRelegatedRowDescribesRelegation(): void
    {
        $now = new \DateTimeImmutable();
        $events = LiveTelemetryService::buildEvents([
            ['tier' => 4, 'promoted' => false, 'relegated' => true, 'finalPosition' => 7, 'createdAt' => $now],
        ], $now);

        $this->assertSame('A Tier 4 club was relegated.', $events[0]['text']);
    }

    public function testRowWithNoOutcomeIsFilteredOut(): void
    {
        $now = new \DateTimeImmutable();
        $events = LiveTelemetryService::buildEvents([
            ['tier' => 4, 'promoted' => false, 'relegated' => false, 'finalPosition' => 5, 'createdAt' => $now],
        ], $now);

        $this->assertSame([], $events);
    }

    public function testRelativeTimeBucketsIntoMinutesHoursAndDays(): void
    {
        $now = new \DateTimeImmutable('2026-01-08 12:00:00');
        $rows = [
            ['tier' => 1, 'promoted' => true, 'relegated' => false, 'finalPosition' => 2, 'createdAt' => $now->modify('-5 minutes')],
            ['tier' => 1, 'promoted' => true, 'relegated' => false, 'finalPosition' => 2, 'createdAt' => $now->modify('-3 hours')],
            ['tier' => 1, 'promoted' => true, 'relegated' => false, 'finalPosition' => 2, 'createdAt' => $now->modify('-2 days')],
        ];

        $events = LiveTelemetryService::buildEvents($rows, $now);

        $this->assertSame('5m ago', $events[0]['time']);
        $this->assertSame('3h ago', $events[1]['time']);
        $this->assertSame('2d ago', $events[2]['time']);
    }
}
