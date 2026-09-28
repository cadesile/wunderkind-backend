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
            ['clubName' => 'Stoke-on-Trent City', 'weeklyAttendance' => 32776, 'serverTimestamp' => $now->modify('-10 minutes')],
        ];

        $events = LiveTelemetryService::buildAttendanceEvents($rows, $now);

        $this->assertSame('Stoke-on-Trent City recorded attendance of 32,776!', $events[0]['text']);
        $this->assertSame('10m ago', $events[0]['time']);
    }

    public function testMergeEventsByRecencyInterleavesSourcesByDateInsteadOfGroupingByType(): void
    {
        $now = new \DateTimeImmutable();

        $pyramidEvents = LiveTelemetryService::buildEvents([
            ['tier' => 2, 'promoted' => true, 'relegated' => false, 'finalPosition' => 3, 'createdAt' => $now->modify('-3 hours'), 'clubName' => 'Pyramid Club'],
        ], $now);

        $ledgerEvents = LiveTelemetryService::buildLedgerEvents([
            ['serverTimestamp' => $now->modify('-1 hour'), 'clubName' => 'Ledger Club', 'payload' => ['ledger' => [
                ['category' => 'wages', 'amount' => -500, 'description' => 'Week 1 payroll'],
            ]]],
        ], $now, 5);

        $attendanceEvents = LiveTelemetryService::buildAttendanceEvents([
            ['clubName' => 'Attendance Club', 'weeklyAttendance' => 1000, 'serverTimestamp' => $now->modify('-5 hours')],
        ], $now);

        $merged = LiveTelemetryService::mergeEventsByRecency($pyramidEvents, $ledgerEvents, $attendanceEvents);

        $this->assertSame(['1h ago', '3h ago', '5h ago'], array_column($merged, 'time'));
        $this->assertSame([
            'Ledger Club spent £5: Week 1 payroll',
            'Pyramid Club won promotion from Tier 2.',
            'Attendance Club recorded attendance of 1,000!',
        ], array_column($merged, 'text'));
        $this->assertSame(['OUTLAY', 'PYRAMID', 'ATTENDANCE'], array_column($merged, 'category'));
    }

    public function testMergedEventsCarryTheEnrichedShapeForModalRendering(): void
    {
        $now = new \DateTimeImmutable();

        $ledgerEvents = LiveTelemetryService::buildLedgerEvents([
            ['serverTimestamp' => $now, 'clubName' => 'Ledger Club', 'payload' => ['ledger' => [
                ['category' => 'wages', 'amount' => -500, 'description' => 'Week 1 payroll'],
            ]]],
        ], $now, 5);

        $merged = LiveTelemetryService::mergeEventsByRecency($ledgerEvents);

        $this->assertSame([
            'time', 'text', 'category', 'categoryLabel', 'club', 'detail', 'amountExact', 'timestampIso', 'meta', 'clubBadge',
        ], array_keys($merged[0]));
        $this->assertSame('[BOARDROOM // OUTLAY]', $merged[0]['categoryLabel']);
        $this->assertSame('Ledger Club', $merged[0]['club']);
        $this->assertSame('£5', $merged[0]['amountExact']);
        $this->assertSame(['home' => null, 'away' => null, 'badge' => null], $merged[0]['clubBadge']);
    }

    public function testEventsCarryTheClubsKitAndBadgeConfigWhenPresentOnTheRow(): void
    {
        $now = new \DateTimeImmutable();
        $syncRows = [
            [
                'serverTimestamp' => $now,
                'clubName'        => 'Toro SD',
                'homeKitConfig'   => ['kit' => 'stripes', 'primary' => '#c8202f', 'secondary' => '#f4f3ee'],
                'awayKitConfig'   => ['kit' => 'hoops', 'primary' => '#000000', 'secondary' => '#ffffff'],
                'badgeConfig'     => ['badgeShape' => 'shield', 'initials' => 'TSD'],
                'payload'         => ['ledger' => [
                    ['category' => 'wages', 'amount' => -500, 'description' => 'Week 1 payroll'],
                ]],
            ],
        ];

        $events = LiveTelemetryService::buildLedgerEvents($syncRows, $now, 5);

        $this->assertSame(
            ['kit' => 'stripes', 'primary' => '#c8202f', 'secondary' => '#f4f3ee'],
            $events[0]['clubBadge']['home'],
        );
        $this->assertSame(
            ['kit' => 'hoops', 'primary' => '#000000', 'secondary' => '#ffffff'],
            $events[0]['clubBadge']['away'],
        );
        $this->assertSame(['badgeShape' => 'shield', 'initials' => 'TSD'], $events[0]['clubBadge']['badge']);
    }

    public function testLedgerEventsExcludeInvestorIncomeAndFanInitiativeCategories(): void
    {
        $now = new \DateTimeImmutable();
        $syncRows = [
            ['serverTimestamp' => $now, 'clubName' => 'Toro SD', 'payload' => ['ledger' => [
                ['category' => 'investor_income', 'amount' => -100, 'description' => 'Equity deal'],
                ['category' => 'fan_initiative', 'amount' => -100, 'description' => 'Fan event'],
            ]]],
        ];

        $events = LiveTelemetryService::buildLedgerEvents($syncRows, $now, 5);

        $this->assertSame([], $events);
    }

    public function testDressingRoomEventsSurfaceResolvedExcursionsWithFriction(): void
    {
        $now = new \DateTimeImmutable();
        $syncRows = [
            ['serverTimestamp' => $now, 'clubName' => 'Toro SD', 'payload' => ['excursions' => [
                [
                    'slug'    => 'escape-room-challenge',
                    'outcome' => [
                        'frictionCount' => 3,
                        'attendeeCount' => 20,
                        'moraleDelta'   => 5,
                        'summary'       => 'Submarine Escape Room was soured by 3 fallouts. Morale +5 across 20, 4 relationships strengthened.',
                        'frictions'     => [
                            ['playerName' => 'Alfonso Iglesias', 'otherName' => 'Jonay Domínguez', 'delta' => -7],
                        ],
                        'bonds' => [],
                    ],
                ],
            ]]],
        ];

        $events = LiveTelemetryService::buildDressingRoomEvents($syncRows, $now);

        $this->assertCount(1, $events);
        $this->assertSame('[DRESSING ROOM // RIFT]', $events[0]['categoryLabel']);
        $this->assertSame(
            'Toro SD — Submarine Escape Room was soured by 3 fallouts. Morale +5 across 20, 4 relationships strengthened.',
            $events[0]['text'],
        );
        $this->assertSame(3, $events[0]['meta']['frictionCount']);
        $this->assertSame(
            [['a' => 'Alfonso Iglesias', 'b' => 'Jonay Domínguez', 'delta' => -7]],
            $events[0]['meta']['frictions'],
        );
    }

    public function testDressingRoomEventsExcludeExcursionsWithoutFrictionOrUnresolved(): void
    {
        $now = new \DateTimeImmutable();
        $syncRows = [
            ['serverTimestamp' => $now, 'clubName' => 'Toro SD', 'payload' => ['excursions' => [
                ['slug' => 'a', 'outcome' => null],
                ['slug' => 'b', 'outcome' => ['frictionCount' => 0, 'summary' => 'Fine']],
            ]]],
        ];

        $events = LiveTelemetryService::buildDressingRoomEvents($syncRows, $now);

        $this->assertSame([], $events);
    }

    public function testDressingRoomEventsHandleMissingExcursionsKeyGracefully(): void
    {
        $events = LiveTelemetryService::buildDressingRoomEvents([
            ['serverTimestamp' => new \DateTimeImmutable(), 'clubName' => 'Toro SD', 'payload' => []],
        ], new \DateTimeImmutable());

        $this->assertSame([], $events);
    }

    public function testDilutionEventsParseEquityPercentAndCrossMatchPromiseAmount(): void
    {
        $now = new \DateTimeImmutable();
        $syncRows = [
            ['serverTimestamp' => $now, 'clubName' => 'Toro SD', 'payload' => [
                'ledger' => [
                    ['category' => 'investor_income', 'amount' => 310000000, 'description' => "Bert's Fencing — season 1 payment (5% equity)"],
                ],
                'promises' => [
                    ['type' => 'investment_contract', 'partyA' => ['name' => "Bert's Fencing"], 'offer' => ['type' => 'seasonal_payment', 'amountPence' => 3100000, 'repeatSeasons' => 3]],
                ],
            ]],
        ];

        $events = LiveTelemetryService::buildDilutionEvents($syncRows, $now);

        $this->assertCount(1, $events);
        $this->assertSame(5, $events[0]['meta']['equityPercent']);
        $this->assertSame("Bert's Fencing", $events[0]['meta']['counterparty']);
        $this->assertSame('£31,000', $events[0]['amountExact']);
    }

    public function testDilutionEventsOmitAmountWhenNoMatchingPromiseFound(): void
    {
        $now = new \DateTimeImmutable();
        $syncRows = [
            ['serverTimestamp' => $now, 'clubName' => 'Toro SD', 'payload' => [
                'ledger'   => [
                    ['category' => 'investor_income', 'amount' => 999999999, 'description' => 'Mystery Investor — season 1 payment (10% equity)'],
                ],
                'promises' => [],
            ]],
        ];

        $events = LiveTelemetryService::buildDilutionEvents($syncRows, $now);

        $this->assertNull($events[0]['amountExact']);
        $this->assertSame(10, $events[0]['meta']['equityPercent']);
    }

    public function testDilutionEventsIgnoreNonInvestorIncomeLedgerCategories(): void
    {
        $now = new \DateTimeImmutable();
        $events = LiveTelemetryService::buildDilutionEvents([
            ['serverTimestamp' => $now, 'clubName' => 'Toro SD', 'payload' => ['ledger' => [
                ['category' => 'upkeep', 'amount' => -100, 'description' => 'Facility maintenance'],
            ]]],
        ], $now);

        $this->assertSame([], $events);
    }

    public function testCovenantEventsSurfaceActiveLeaguePositionTerminationRisk(): void
    {
        $now = new \DateTimeImmutable();
        $syncRows = [
            ['serverTimestamp' => $now, 'clubName' => 'Toro SD', 'payload' => ['promises' => [
                [
                    'id'        => 'p1',
                    'type'      => 'investment_contract',
                    'status'    => 'active',
                    'partyA'    => ['name' => "Bert's Fencing"],
                    'offer'     => ['type' => 'seasonal_payment', 'amountPence' => 3100000, 'repeatSeasons' => 3],
                    'condition' => ['type' => 'league_position', 'target' => 5],
                ],
            ]]],
        ];

        $events = LiveTelemetryService::buildCovenantEvents($syncRows, $now);

        $this->assertCount(1, $events);
        $this->assertSame("Toro SD: Bert's Fencing covenant voids if league position slips below 5", $events[0]['text']);
        $this->assertSame('£93,000 total', $events[0]['amountExact']);
    }

    public function testCovenantEventsExcludeInactiveOrUnrelatedConditionPromises(): void
    {
        $now = new \DateTimeImmutable();
        $syncRows = [
            ['serverTimestamp' => $now, 'clubName' => 'Toro SD', 'payload' => ['promises' => [
                ['id' => 'p1', 'status' => 'voided', 'condition' => ['type' => 'league_position', 'target' => 5]],
                ['id' => 'p2', 'status' => 'active', 'condition' => ['type' => 'something_else', 'target' => 5]],
            ]]],
        ];

        $events = LiveTelemetryService::buildCovenantEvents($syncRows, $now);

        $this->assertSame([], $events);
    }

    public function testCovenantEventsShowPerWeekAmountForWeeklyPaymentOffers(): void
    {
        $now = new \DateTimeImmutable();
        $syncRows = [
            ['serverTimestamp' => $now, 'clubName' => 'Toro SD', 'payload' => ['promises' => [
                [
                    'id'        => 'p1',
                    'status'    => 'active',
                    'partyA'    => ['name' => 'The County Works'],
                    'offer'     => ['type' => 'weekly_payment', 'amountPence' => 132024],
                    'condition' => ['type' => 'tier_reached', 'target' => 8],
                ],
            ]]],
        ];

        $events = LiveTelemetryService::buildCovenantEvents($syncRows, $now);

        $this->assertSame('£1,320/week', $events[0]['amountExact']);
    }

    public function testTalismanEventsSurfaceHighestRatedQualifyingPlayerPerClub(): void
    {
        $now = new \DateTimeImmutable();
        $syncRows = [
            ['serverTimestamp' => $now, 'clubName' => 'Toro SD', 'payload' => ['playerStats' => [
                ['playerName' => 'Manolo Garrido', 'playerAge' => 27, 'appearances' => 3, 'goals' => 3, 'assists' => 1, 'averageRating' => 8.5],
                ['playerName' => 'Álvaro López', 'playerAge' => 28, 'appearances' => 4, 'goals' => 0, 'assists' => 5, 'averageRating' => 8.275],
                ['playerName' => 'Low Rated Guy', 'playerAge' => 20, 'appearances' => 2, 'goals' => 0, 'assists' => 0, 'averageRating' => 6.0],
            ]]],
        ];

        $events = LiveTelemetryService::buildTalismanEvents($syncRows, $now);

        $this->assertCount(1, $events);
        $this->assertSame('Manolo Garrido', $events[0]['meta']['playerName']);
        $this->assertSame(8.5, $events[0]['meta']['rating']);
    }

    public function testTalismanEventsExcludePlayersBelowRatingThreshold(): void
    {
        $now = new \DateTimeImmutable();
        $events = LiveTelemetryService::buildTalismanEvents([
            ['serverTimestamp' => $now, 'clubName' => 'Toro SD', 'payload' => ['playerStats' => [
                ['playerName' => 'Average Joe', 'averageRating' => 7.9],
            ]]],
        ], $now);

        $this->assertSame([], $events);
    }

    public function testTalismanEventsSurfaceGoalkeeperPeakRatingFromFixtures(): void
    {
        $now = new \DateTimeImmutable();
        $events = LiveTelemetryService::buildTalismanEvents([
            ['serverTimestamp' => $now, 'clubName' => 'Toro SD', 'payload' => [
                'seasonRecord' => ['goalsAgainst' => 2],
                'fixtures'     => [
                    ['homePlayerRatings' => [
                        ['playerName' => 'Vicente Cruz', 'position' => 'GK', 'rating' => 9.6],
                    ], 'awayPlayerRatings' => []],
                ],
            ]],
        ], $now);

        $this->assertCount(1, $events);
        $this->assertSame('Toro SD: Vicente Cruz peak rated 9.6, 2 conceded this window.', $events[0]['text']);
    }

    public function testCommunityEventsSurfaceFanInitiativeSpend(): void
    {
        $now = new \DateTimeImmutable();
        $events = LiveTelemetryService::buildCommunityEvents([
            ['serverTimestamp' => $now, 'clubName' => 'Toro SD', 'payload' => ['ledger' => [
                ['category' => 'fan_initiative', 'amount' => -400000000, 'description' => 'Fan event — venue & catering'],
            ]]],
        ], $now);

        $this->assertCount(1, $events);
        $this->assertSame('Toro SD invested in fan initiative: Fan event — venue & catering', $events[0]['text']);
        $this->assertNull($events[0]['amountExact']);
    }

    public function testCommunityEventsSurfaceExtremeSentimentReadings(): void
    {
        $now = new \DateTimeImmutable();
        $events = LiveTelemetryService::buildCommunityEvents([
            ['serverTimestamp' => $now, 'clubName' => 'Toro SD', 'payload' => ['attendance' => ['fanSentiment' => 90, 'fanMorale' => 95]]],
        ], $now);

        $this->assertCount(1, $events);
        $this->assertSame(90, $events[0]['meta']['fanSentiment']);
    }

    public function testCommunityEventsExcludeModerateSentiment(): void
    {
        $now = new \DateTimeImmutable();
        $events = LiveTelemetryService::buildCommunityEvents([
            ['serverTimestamp' => $now, 'clubName' => 'Toro SD', 'payload' => ['attendance' => ['fanSentiment' => 62, 'fanMorale' => 66]]],
        ], $now);

        $this->assertSame([], $events);
    }

    public function testComputeCommunityMoraleDeltaAveragesAcrossQualifyingClubs(): void
    {
        $now = new \DateTimeImmutable();
        $rows = [
            ['serverTimestamp' => $now->modify('-2 hours'), 'clubName' => 'Toro SD', 'payload' => ['attendance' => ['fanMorale' => 60]]],
            ['serverTimestamp' => $now, 'clubName' => 'Toro SD', 'payload' => ['attendance' => ['fanMorale' => 70]]],
        ];

        $this->assertSame(10, LiveTelemetryService::computeCommunityMoraleDelta($rows));
    }

    public function testComputeCommunityMoraleDeltaExcludesClubsWithOnlyOneReading(): void
    {
        $rows = [
            ['serverTimestamp' => new \DateTimeImmutable(), 'clubName' => 'Toro SD', 'payload' => ['attendance' => ['fanMorale' => 60]]],
        ];

        $this->assertNull(LiveTelemetryService::computeCommunityMoraleDelta($rows));
    }

    public function testComputeCommunityMoraleDeltaReturnsNullForEmptyInput(): void
    {
        $this->assertNull(LiveTelemetryService::computeCommunityMoraleDelta([]));
    }

    public function testBuildDilutionSummaryPicksHighestPercentEntry(): void
    {
        $events = [
            ['club' => 'Club A', 'meta' => ['equityPercent' => 5, 'counterparty' => 'Bert']],
            ['club' => 'Club B', 'meta' => ['equityPercent' => 10, 'counterparty' => 'Walt']],
        ];

        $summary = LiveTelemetryService::buildDilutionSummary($events);

        $this->assertSame(10, $summary['percent']);
        $this->assertSame('Walt', $summary['counterparty']);
    }

    public function testBuildDilutionSummaryReturnsNullForEmptyInput(): void
    {
        $this->assertNull(LiveTelemetryService::buildDilutionSummary([]));
    }

    public function testBuildCovenantSummaryCountsAndSumsDistinctActiveCovenantsWithoutDoubleCounting(): void
    {
        $now = new \DateTimeImmutable();
        $promise = ['id' => 'p1', 'status' => 'active', 'condition' => ['type' => 'league_position', 'target' => 5], 'offer' => ['type' => 'seasonal_payment', 'amountPence' => 1000000, 'repeatSeasons' => 2]];
        $syncRows = [
            ['serverTimestamp' => $now, 'clubName' => 'Toro SD', 'payload' => ['promises' => [$promise]]],
            // Same promise synced again this window — must not be double counted.
            ['serverTimestamp' => $now, 'clubName' => 'Toro SD', 'payload' => ['promises' => [$promise]]],
        ];

        $summary = LiveTelemetryService::buildCovenantSummary($syncRows);

        $this->assertSame(1, $summary['count']);
        $this->assertSame(2000000, $summary['valuePence']);
    }

    public function testBuildCovenantSummaryReturnsZerosForEmptyInput(): void
    {
        $summary = LiveTelemetryService::buildCovenantSummary([]);

        $this->assertSame(0, $summary['count']);
        $this->assertSame(0, $summary['valuePence']);
    }
}
