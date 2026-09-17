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
    public function testTalliesFormEntriesIntoWinsDrawsLosses(): void
    {
        $result = LiveTelemetryService::aggregate([
            ['form' => ['W', 'W', 'L', 'D', 'W']],
            ['form' => ['L', 'D']],
        ]);

        $this->assertSame(3, $result['wins']);
        $this->assertSame(2, $result['draws']);
        $this->assertSame(2, $result['losses']);
        $this->assertSame(7, $result['fixturesSimulated']);
    }

    public function testIgnoresUnrecognisedFormValues(): void
    {
        $result = LiveTelemetryService::aggregate([
            ['form' => ['W', 'X', '', null]],
        ]);

        $this->assertSame(1, $result['wins']);
        $this->assertSame(0, $result['draws']);
        $this->assertSame(0, $result['losses']);
        $this->assertSame(1, $result['fixturesSimulated']);
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

    public function testCombinesFormAndLedgerAndTransferSpendAcrossPayloads(): void
    {
        $result = LiveTelemetryService::aggregate([
            [
                'form'       => ['W', 'W'],
                'ledger'     => [['category' => 'upkeep', 'amount' => -100]],
                'transfers'  => [['type' => 'signing', 'grossFee' => 5000]],
            ],
            [
                'form'      => ['L'],
                'ledger'    => [['category' => 'sponsor_payment', 'amount' => 200]],
                'transfers' => [['type' => 'sale', 'grossFee' => 9000]],
            ],
        ]);

        $this->assertSame(3, $result['fixturesSimulated']);
        $this->assertSame(2, $result['wins']);
        $this->assertSame(1, $result['losses']);
        $this->assertSame(5100, $result['capitalDeployedPence']);
    }

    public function testHandlesEmptyOrMissingKeysGracefully(): void
    {
        $result = LiveTelemetryService::aggregate([[], ['form' => []], ['ledger' => []], ['transfers' => []]]);

        $this->assertSame(0, $result['fixturesSimulated']);
        $this->assertSame(0, $result['capitalDeployedPence']);
        $this->assertSame(0, $result['wins']);
        $this->assertSame(0, $result['draws']);
        $this->assertSame(0, $result['losses']);
    }

    public function testTitleTakesPriorityOverPromotedForTheSameRow(): void
    {
        $now = new \DateTimeImmutable();
        $events = LiveTelemetryService::buildEvents([
            ['tier' => 3, 'promoted' => true, 'relegated' => false, 'finalPosition' => 1, 'createdAt' => $now, 'clubName' => 'Ferrington Athletic'],
        ], $now);

        $this->assertSame('Ferrington Athletic lifted the Tier 3 title.', $events[0]['text']);
    }

    public function testPromotedRowWithoutTitleDescribesPromotion(): void
    {
        $now = new \DateTimeImmutable();
        $events = LiveTelemetryService::buildEvents([
            ['tier' => 5, 'promoted' => true, 'relegated' => false, 'finalPosition' => 2, 'createdAt' => $now, 'clubName' => 'Stoke-on-Trent City'],
        ], $now);

        $this->assertSame('Stoke-on-Trent City won promotion from Tier 5.', $events[0]['text']);
    }

    public function testRelegatedRowDescribesRelegation(): void
    {
        $now = new \DateTimeImmutable();
        $events = LiveTelemetryService::buildEvents([
            ['tier' => 4, 'promoted' => false, 'relegated' => true, 'finalPosition' => 7, 'createdAt' => $now, 'clubName' => 'Reading County'],
        ], $now);

        $this->assertSame('Reading County was relegated from Tier 4.', $events[0]['text']);
    }

    public function testRowWithNoOutcomeIsFilteredOut(): void
    {
        $now = new \DateTimeImmutable();
        $events = LiveTelemetryService::buildEvents([
            ['tier' => 4, 'promoted' => false, 'relegated' => false, 'finalPosition' => 5, 'createdAt' => $now, 'clubName' => 'Bury Town'],
        ], $now);

        $this->assertSame([], $events);
    }

    public function testRelativeTimeBucketsIntoMinutesHoursAndDays(): void
    {
        $now = new \DateTimeImmutable('2026-01-08 12:00:00');
        $rows = [
            ['tier' => 1, 'promoted' => true, 'relegated' => false, 'finalPosition' => 2, 'createdAt' => $now->modify('-5 minutes'), 'clubName' => 'Club A'],
            ['tier' => 1, 'promoted' => true, 'relegated' => false, 'finalPosition' => 2, 'createdAt' => $now->modify('-3 hours'), 'clubName' => 'Club B'],
            ['tier' => 1, 'promoted' => true, 'relegated' => false, 'finalPosition' => 2, 'createdAt' => $now->modify('-2 days'), 'clubName' => 'Club C'],
        ];

        $events = LiveTelemetryService::buildEvents($rows, $now);

        $this->assertSame('5m ago', $events[0]['time']);
        $this->assertSame('3h ago', $events[1]['time']);
        $this->assertSame('2d ago', $events[2]['time']);
    }

    public function testLedgerEventsRankByMagnitudeRegardlessOfCategory(): void
    {
        $now = new \DateTimeImmutable();
        $syncRows = [
            ['serverTimestamp' => $now, 'clubName' => 'Ferrington Athletic', 'payload' => ['ledger' => [
                ['category' => 'wages', 'amount' => -344000, 'description' => 'Week 42 payroll'],
                ['category' => 'upkeep', 'amount' => -118690800, 'description' => 'DOF assigned scouting mission — Louie Norris (MID)'],
            ]]],
        ];

        $events = LiveTelemetryService::buildLedgerEvents($syncRows, $now, 5);

        $this->assertSame('Ferrington Athletic spent £1.2M: DOF assigned scouting mission — Louie Norris (MID)', $events[0]['text']);
        $this->assertCount(2, $events);
    }

    public function testLedgerEventsExcludeRevenueEntries(): void
    {
        $now = new \DateTimeImmutable();
        $syncRows = [
            ['serverTimestamp' => $now, 'clubName' => 'Ferrington Athletic', 'payload' => ['ledger' => [
                ['category' => 'matchday_income', 'amount' => 25590000, 'description' => 'Week 42 matchday income'],
            ]]],
        ];

        $events = LiveTelemetryService::buildLedgerEvents($syncRows, $now, 5);

        $this->assertSame([], $events);
    }

    public function testLedgerEventsExcludeEntriesWithNoDescription(): void
    {
        $now = new \DateTimeImmutable();
        $syncRows = [
            ['serverTimestamp' => $now, 'clubName' => 'Ferrington Athletic', 'payload' => ['ledger' => [
                ['category' => 'upkeep', 'amount' => -100, 'description' => ''],
            ]]],
        ];

        $events = LiveTelemetryService::buildLedgerEvents($syncRows, $now, 5);

        $this->assertSame([], $events);
    }

    public function testLedgerEventsAreCappedToTheLimit(): void
    {
        $now = new \DateTimeImmutable();
        $syncRows = [
            ['serverTimestamp' => $now, 'clubName' => 'Ferrington Athletic', 'payload' => ['ledger' => [
                ['category' => 'upkeep', 'amount' => -500, 'description' => 'a'],
                ['category' => 'upkeep', 'amount' => -400, 'description' => 'b'],
                ['category' => 'upkeep', 'amount' => -300, 'description' => 'c'],
            ]]],
        ];

        $events = LiveTelemetryService::buildLedgerEvents($syncRows, $now, 2);

        $this->assertCount(2, $events);
    }

    public function testAttendanceEventsNameTheRealClub(): void
    {
        $now = new \DateTimeImmutable();
        $rows = [
            ['clubName' => 'Stoke-on-Trent City', 'fanCount' => 32776, 'serverTimestamp' => $now->modify('-10 minutes')],
        ];

        $events = LiveTelemetryService::buildAttendanceEvents($rows, $now);

        $this->assertSame('Stoke-on-Trent City recorded attendance of 32,776!', $events[0]['text']);
        $this->assertSame('10m ago', $events[0]['time']);
    }
}
