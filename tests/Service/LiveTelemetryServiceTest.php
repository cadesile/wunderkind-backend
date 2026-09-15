<?php

namespace App\Tests\Service;

use App\Service\LiveTelemetryService;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the pure aggregation step behind the landing page's
 * "Chairman's Terminal" widget. The DB-backed fetch (findValidPayloadsSince)
 * is not exercised here — this only covers the counting/summing formula.
 */
class LiveTelemetryServiceTest extends TestCase
{
    public function testCountsMatchResultsAcrossPayloadsAsFixturesSimulated(): void
    {
        $result = LiveTelemetryService::aggregate([
            ['matchResults' => [['fixtureId' => 'a'], ['fixtureId' => 'b']]],
            ['matchResults' => [['fixtureId' => 'c']]],
            ['matchResults' => []],
        ]);

        $this->assertSame(3, $result['fixturesSimulated']);
    }

    public function testSumsUpkeepAndWagesLedgerEntriesAsCapitalDeployed(): void
    {
        $result = LiveTelemetryService::aggregate([
            ['ledger' => [
                ['category' => 'upkeep', 'amount' => -23625000],
                ['category' => 'wages', 'amount' => -31940000],
            ]],
        ]);

        $this->assertSame(23625000 + 31940000, $result['capitalDeployedPence']);
    }

    public function testExcludesRevenueLedgerCategories(): void
    {
        $result = LiveTelemetryService::aggregate([
            ['ledger' => [
                ['category' => 'matchday_income', 'amount' => 32220000],
                ['category' => 'sponsor_payment', 'amount' => 19506000],
            ]],
        ]);

        $this->assertSame(0, $result['capitalDeployedPence']);
    }

    public function testCountsSigningAndAgentAssistedTransfersAsCapitalDeployed(): void
    {
        $result = LiveTelemetryService::aggregate([
            ['transfers' => [
                ['type' => 'signing', 'grossFee' => 35000000],
                ['type' => 'agent_assisted', 'grossFee' => 5000000],
            ]],
        ]);

        $this->assertSame(40000000, $result['capitalDeployedPence']);
    }

    public function testExcludesSaleAndOtherOutgoingTransferTypes(): void
    {
        $result = LiveTelemetryService::aggregate([
            ['transfers' => [
                ['type' => 'sale', 'grossFee' => 35000000],
                ['type' => 'loan', 'grossFee' => 1000000],
                ['type' => 'free_release', 'grossFee' => 0],
                ['type' => 'guardian_withdrawal', 'grossFee' => 0],
            ]],
        ]);

        $this->assertSame(0, $result['capitalDeployedPence']);
    }

    public function testCombinesLedgerAndTransferSpendAcrossPayloads(): void
    {
        $result = LiveTelemetryService::aggregate([
            [
                'matchResults' => [['fixtureId' => 'a']],
                'ledger'       => [['category' => 'upkeep', 'amount' => -100]],
                'transfers'    => [['type' => 'signing', 'grossFee' => 5000]],
            ],
            [
                'matchResults' => [['fixtureId' => 'b'], ['fixtureId' => 'c']],
                'ledger'       => [['category' => 'sponsor_payment', 'amount' => 200]],
                'transfers'    => [['type' => 'sale', 'grossFee' => 9000]],
            ],
        ]);

        $this->assertSame(3, $result['fixturesSimulated']);
        $this->assertSame(5100, $result['capitalDeployedPence']);
    }

    public function testHandlesEmptyOrMissingKeysGracefully(): void
    {
        $result = LiveTelemetryService::aggregate([[], ['matchResults' => []], ['ledger' => []], ['transfers' => []]]);

        $this->assertSame(0, $result['fixturesSimulated']);
        $this->assertSame(0, $result['capitalDeployedPence']);
        $this->assertSame(0, $result['goalsScored']);
    }

    public function testSumsLegacyGoalsForWhenNoV2FieldsPresent(): void
    {
        $result = LiveTelemetryService::aggregate([
            ['matchResults' => [
                ['fixtureId' => 'a', 'goalsFor' => 2, 'goalsAgainst' => 1],
                ['fixtureId' => 'b', 'goalsFor' => 0, 'goalsAgainst' => 3],
            ]],
        ]);

        $this->assertSame(2, $result['goalsScored']);
    }

    public function testSumsV2HomeAwayGoalsByIsHomeFlag(): void
    {
        $result = LiveTelemetryService::aggregate([
            ['matchResults' => [
                ['fixtureId' => 'a', 'isHome' => true, 'homeGoals' => 3, 'awayGoals' => 1],
                ['fixtureId' => 'b', 'isHome' => false, 'homeGoals' => 2, 'awayGoals' => 4],
            ]],
        ]);

        // 3 (home side scored 3) + 4 (away side scored 4) = 7
        $this->assertSame(7, $result['goalsScored']);
    }

    public function testV2FieldsWinOverLegacyGoalsForWhenBothPresent(): void
    {
        $result = LiveTelemetryService::aggregate([
            ['matchResults' => [
                // A client that sends both shapes: v2 says the away side scored 5, legacy
                // (defaulted to 0 by SyncRequest::setMatchResults) must not override that.
                ['fixtureId' => 'a', 'isHome' => false, 'homeGoals' => 1, 'awayGoals' => 5, 'goalsFor' => 0],
            ]],
        ]);

        $this->assertSame(5, $result['goalsScored']);
    }

    public function testZeroZeroDrawCountsAsZeroGoalsNotMisdetectedAsLegacy(): void
    {
        $result = LiveTelemetryService::aggregate([
            ['matchResults' => [
                ['fixtureId' => 'a', 'isHome' => true, 'homeGoals' => 0, 'awayGoals' => 0],
            ]],
        ]);

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
