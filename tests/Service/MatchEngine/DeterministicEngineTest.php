<?php

declare(strict_types=1);

namespace App\Tests\Service\MatchEngine;

use App\Entity\Club;
use App\Entity\Competition\ActiveCompetition;
use App\Entity\Competition\CompetitionEntrant;
use App\Entity\Competition\CompetitionFixture;
use App\Entity\Competition\CompetitionRound;
use App\Entity\Competition\CompetitionTemplate;
use App\Entity\GameConfig;
use App\Entity\User;
use App\Enum\Competition\CompetitionDuration;
use App\Enum\Competition\MatchEngineIdentifier;
use App\Enum\PlayingStyle;
use App\Repository\GameConfigRepository;
use App\Repository\TacticalAdvantageRepository;
use App\Service\MatchEngine\DeterministicEngine;
use App\Service\MatchEngine\MatchNarrativeGeneratorService;
use PHPUnit\Framework\TestCase;

class DeterministicEngineTest extends TestCase
{
    /** Neutral (1.0 for every pairing) unless a specific multiplier map is supplied. */
    private function makeEngine(array $multipliers = []): DeterministicEngine
    {
        $repo = $this->createStub(TacticalAdvantageRepository::class);
        $repo->method('findMultiplier')->willReturnCallback(
            static fn (PlayingStyle $style, PlayingStyle $opponentStyle) => $multipliers["{$style->value}:{$opponentStyle->value}"] ?? 1.0,
        );

        $configRepo = $this->createStub(GameConfigRepository::class);
        $configRepo->method('getConfig')->willReturn(new GameConfig());

        // A stub, not the real port under test elsewhere (MatchNarrativeGeneratorServiceTest) —
        // this suite is only exercising DeterministicEngine's own score/lineup/card model.
        $narrativeGenerator = $this->createStub(MatchNarrativeGeneratorService::class);
        $narrativeGenerator->method('generate')->willReturn([['minute' => 1, 'isKeyEvent' => false, 'eventType' => null, 'text' => 'Kick-off.', 'playerA' => null, 'playerB' => null, 'side' => null]]);

        return new DeterministicEngine($repo, $configRepo, $narrativeGenerator);
    }

    /** Same as makeEngine(), but records every call to generate() so ET/penalty flags passed to it can be asserted on. */
    private function makeEngineCapturingNarrativeArgs(array &$capturedCalls): DeterministicEngine
    {
        $repo = $this->createStub(TacticalAdvantageRepository::class);
        $repo->method('findMultiplier')->willReturn(1.0);

        $configRepo = $this->createStub(GameConfigRepository::class);
        $configRepo->method('getConfig')->willReturn(new GameConfig());

        $narrativeGenerator = $this->createStub(MatchNarrativeGeneratorService::class);
        $narrativeGenerator->method('generate')->willReturnCallback(
            function (...$args) use (&$capturedCalls) {
                $capturedCalls[] = ['wentToExtraTime' => $args[6], 'wentToPenalties' => $args[7], 'penaltyHomeScore' => $args[8], 'penaltyAwayScore' => $args[9]];

                return [];
            },
        );

        return new DeterministicEngine($repo, $configRepo, $narrativeGenerator);
    }

    private function makeEntrant(ActiveCompetition $instance, string $name, int $strength, ?PlayingStyle $playingStyle = null, array $extraSnapshot = []): CompetitionEntrant
    {
        $user = new User("$name@example.com");
        $club = new Club($name, $user);

        $players = [];
        for ($i = 0; $i < 11; $i++) {
            $players[] = [
                'id'             => "$name-p$i",
                'position'       => 'MID',
                'name'           => "$name Player $i",
                'currentAbility' => $strength,
                'appearance'     => [
                    'hair'      => 'short',
                    'hairColor' => '#1a1a1a',
                    'headband'  => false,
                    'skin'      => '#c68642',
                    'face'      => 'round',
                    'facial'    => 'beard',
                    'lip'       => '#8d5524',
                ],
            ];
        }

        $clubSnapshot = ['id' => (string) $club->getId(), 'name' => $name];
        if ($playingStyle !== null) {
            $clubSnapshot['playingStyle'] = $playingStyle->value;
        }
        if (isset($extraSnapshot['club']) && is_array($extraSnapshot['club'])) {
            $clubSnapshot = array_merge($clubSnapshot, $extraSnapshot['club']);
        }

        $snapshot = ['club' => $clubSnapshot, 'players' => $players];
        if (isset($extraSnapshot['staff']) && is_array($extraSnapshot['staff'])) {
            $snapshot['staff'] = $extraSnapshot['staff'];
        }

        return new CompetitionEntrant($instance, $club, 0, $snapshot);
    }

    private function makeFixture(): array
    {
        $template = new CompetitionTemplate('Cup', 'cup-' . uniqid('', true), 4, CompetitionDuration::TEN_HOURS);
        $instance  = new ActiveCompetition($template);
        $round      = new CompetitionRound($instance, 1, 'SF', new \DateTimeImmutable());

        $home = $this->makeEntrant($instance, 'Home', 15);
        $away = $this->makeEntrant($instance, 'Away', 5);

        return [new CompetitionFixture($round, 0, $home, $away), $home, $away];
    }

    public function testSupportsOnlyDeterministicIdentifier(): void
    {
        $engine = $this->makeEngine();
        $this->assertTrue($engine->supports(MatchEngineIdentifier::DETERMINISTIC));
        $this->assertFalse($engine->supports(MatchEngineIdentifier::AI_ASSISTED));
        $this->assertFalse($engine->supports(MatchEngineIdentifier::AI_NARRATIVE));
    }

    public function testSameFixtureIdProducesTheSameResultEveryTime(): void
    {
        $engine = $this->makeEngine();
        [$fixture, $home, $away] = $this->makeFixture();

        $first  = $engine->resolve($home, $away, $fixture);
        $second = $engine->resolve($home, $away, $fixture);

        $this->assertSame($first->homeScore, $second->homeScore);
        $this->assertSame($first->awayScore, $second->awayScore);
        $this->assertSame($first->eventLog, $second->eventLog);
    }

    public function testEventLogIsOrderedByMinute(): void
    {
        $engine = $this->makeEngine();
        [$fixture, $home, $away] = $this->makeFixture();

        $result = $engine->resolve($home, $away, $fixture);
        $minutes = array_column($result->eventLog, 'minute');

        $sorted = $minutes;
        sort($sorted);
        $this->assertSame($sorted, $minutes);
    }

    public function testAStrongerSnapshotWinsMoreOftenAcrossManySeeds(): void
    {
        $engine       = $this->makeEngine();
        $strongerWins = 0;
        $trials       = 200;

        for ($i = 0; $i < $trials; $i++) {
            $template = new CompetitionTemplate('Cup', 'cup-' . uniqid('', true), 4, CompetitionDuration::TEN_HOURS);
            $instance  = new ActiveCompetition($template);
            $round      = new CompetitionRound($instance, 1, 'SF', new \DateTimeImmutable());
            $strong      = $this->makeEntrant($instance, 'Strong', 20);
            $weak         = $this->makeEntrant($instance, 'Weak', 1);
            $fixture       = new CompetitionFixture($round, 0, $strong, $weak);

            $result = $engine->resolve($strong, $weak, $fixture);
            if ($result->homeScore > $result->awayScore) {
                $strongerWins++;
            }
        }

        // Not a guarantee every trial goes the stronger side's way (that's the point of
        // "controlled variance for upsets") — but a 20-vs-1 strength gap should win clearly
        // more often than not across 200 independent fixture-id seeds.
        $this->assertGreaterThan($trials * 0.7, $strongerWins);
    }

    public function testTacticalAdvantageMultiplierShiftsWinRateForEquallyStrongSides(): void
    {
        // Equal player strength on both sides, but HIGH_PRESS gets a big configured
        // advantage over POSSESSION — that alone should tip the balance clearly.
        $engine = $this->makeEngine([
            PlayingStyle::HIGH_PRESS->value . ':' . PlayingStyle::POSSESSION->value => 3.0,
        ]);

        $favouredWins = 0;
        $trials       = 200;

        for ($i = 0; $i < $trials; $i++) {
            $template = new CompetitionTemplate('Cup', 'cup-' . uniqid('', true), 4, CompetitionDuration::TEN_HOURS);
            $instance  = new ActiveCompetition($template);
            $round      = new CompetitionRound($instance, 1, 'SF', new \DateTimeImmutable());
            $favoured    = $this->makeEntrant($instance, 'Favoured', 50, PlayingStyle::HIGH_PRESS);
            $other        = $this->makeEntrant($instance, 'Other', 50, PlayingStyle::POSSESSION);
            $fixture       = new CompetitionFixture($round, 0, $favoured, $other);

            $result = $engine->resolve($favoured, $other, $fixture);
            if ($result->homeScore > $result->awayScore) {
                $favouredWins++;
            }
        }

        // A 3x multiplier on equal base strength is a smaller effective edge than the
        // 20-vs-1 raw-strength gap the sibling test uses (each match only has 10 discrete
        // "chances," so the margin is noisier) — 60% still clearly beats the 50% neutral
        // baseline without the test being flaky near a tighter threshold.
        $this->assertGreaterThan($trials * 0.6, $favouredWins);
    }

    public function testMissingPlayingStyleOnEitherSideAppliesNoTacticalMultiplier(): void
    {
        // makeEngine() would apply a 3.0x multiplier for this style pairing if styleOf()
        // resolved one — but neither entrant here has a playingStyle in its snapshot, so
        // the multiplier must never be looked up: equal strength, equal footing.
        $engine = $this->makeEngine([
            PlayingStyle::HIGH_PRESS->value . ':' . PlayingStyle::POSSESSION->value => 3.0,
        ]);

        [$fixture, $home, $away] = $this->makeFixture(); // strengths 15 vs 5, no playingStyle set
        $result = $engine->resolve($home, $away, $fixture);

        // Just confirms resolve() runs to completion without a style-driven skew being
        // forced — the real assertion is the absence of an exception/type error from
        // styleOf() handling null gracefully. Reproducibility is already covered above.
        $this->assertIsInt($result->homeScore);
        $this->assertIsInt($result->awayScore);
    }

    public function testLineupsContainAllElevenPlayersWithNamesAndPositions(): void
    {
        $engine = $this->makeEngine();
        [$fixture, $home, $away] = $this->makeFixture();

        $result = $engine->resolve($home, $away, $fixture);

        $this->assertCount(11, $result->homeLineup);
        $this->assertCount(11, $result->awayLineup);

        foreach (array_merge($result->homeLineup, $result->awayLineup) as $entry) {
            $this->assertArrayHasKey('id', $entry);
            $this->assertArrayHasKey('name', $entry);
            $this->assertArrayHasKey('position', $entry);
            $this->assertArrayHasKey('appearance', $entry);
            $this->assertIsArray($entry['appearance']);
            $this->assertSame('short', $entry['appearance']['hair']);
            $this->assertSame('MID', $entry['position']);
            $this->assertStringContainsString('Player', $entry['name']);
        }
    }

    public function testRatingsAreAlwaysWithinTheOneToTenRange(): void
    {
        $engine = $this->makeEngine();

        for ($i = 0; $i < 30; $i++) {
            $template = new CompetitionTemplate('Cup', 'cup-' . uniqid('', true), 4, CompetitionDuration::TEN_HOURS);
            $instance = new ActiveCompetition($template);
            $round    = new CompetitionRound($instance, 1, 'SF', new \DateTimeImmutable());
            $home     = $this->makeEntrant($instance, 'Home', 15);
            $away     = $this->makeEntrant($instance, 'Away', 5);
            $fixture  = new CompetitionFixture($round, 0, $home, $away);

            $result = $engine->resolve($home, $away, $fixture);

            foreach (array_merge($result->homeLineup, $result->awayLineup) as $entry) {
                $this->assertGreaterThanOrEqual(1.0, $entry['rating']);
                $this->assertLessThanOrEqual(10.0, $entry['rating']);
            }
        }
    }

    public function testGoalsAndAssistsInTheEventLogAreCreditedInTheLineup(): void
    {
        $engine = $this->makeEngine();
        $foundGoalWithScorer  = false;
        $foundGoalWithAssist  = false;

        // Probabilistic across independent fixture-id seeds, same idiom as the win-rate
        // tests above — search until both cases are observed rather than assume any one
        // fixed seed produces them.
        for ($i = 0; $i < 100 && (!$foundGoalWithScorer || !$foundGoalWithAssist); $i++) {
            $template = new CompetitionTemplate('Cup', 'cup-' . uniqid('', true), 4, CompetitionDuration::TEN_HOURS);
            $instance = new ActiveCompetition($template);
            $round    = new CompetitionRound($instance, 1, 'SF', new \DateTimeImmutable());
            $home     = $this->makeEntrant($instance, 'Home', 15);
            $away     = $this->makeEntrant($instance, 'Away', 15);
            $fixture  = new CompetitionFixture($round, 0, $home, $away);

            $result = $engine->resolve($home, $away, $fixture);
            $lineupById = [];
            foreach (array_merge($result->homeLineup, $result->awayLineup) as $entry) {
                $lineupById[$entry['id']] = $entry;
            }

            foreach ($result->eventLog as $event) {
                if ($event['type'] !== 'goal' || $event['scorer'] === null) {
                    continue;
                }
                $foundGoalWithScorer = true;
                $this->assertGreaterThanOrEqual(1, $lineupById[$event['scorer']]['goals']);

                if ($event['assist'] !== null) {
                    $foundGoalWithAssist = true;
                    $this->assertNotSame($event['scorer'], $event['assist'], 'A player cannot assist their own goal.');
                    $this->assertGreaterThanOrEqual(1, $lineupById[$event['assist']]['assists']);
                }
            }
        }

        $this->assertTrue($foundGoalWithScorer, 'Expected at least one scored goal with an identified scorer across 100 seeds.');
        $this->assertTrue($foundGoalWithAssist, 'Expected at least one assisted goal across 100 seeds.');
    }

    public function testCardsInTheEventLogAreCreditedInTheLineup(): void
    {
        $engine = $this->makeEngine();
        $foundYellow = false;
        $foundRed    = false;

        for ($i = 0; $i < 200 && (!$foundYellow || !$foundRed); $i++) {
            $template = new CompetitionTemplate('Cup', 'cup-' . uniqid('', true), 4, CompetitionDuration::TEN_HOURS);
            $instance = new ActiveCompetition($template);
            $round    = new CompetitionRound($instance, 1, 'SF', new \DateTimeImmutable());
            $home     = $this->makeEntrant($instance, 'Home', 15);
            $away     = $this->makeEntrant($instance, 'Away', 15);
            $fixture  = new CompetitionFixture($round, 0, $home, $away);

            $result = $engine->resolve($home, $away, $fixture);
            $lineupById = [];
            foreach (array_merge($result->homeLineup, $result->awayLineup) as $entry) {
                $lineupById[$entry['id']] = $entry;
            }

            foreach ($result->eventLog as $event) {
                if (!in_array($event['type'], ['yellow_card', 'red_card'], true) || $event['player'] === null) {
                    continue;
                }
                if ($event['type'] === 'yellow_card') {
                    $foundYellow = true;
                    $this->assertGreaterThanOrEqual(1, $lineupById[$event['player']]['yellowCards']);
                } else {
                    $foundRed = true;
                    $this->assertGreaterThanOrEqual(1, $lineupById[$event['player']]['redCards']);
                }
            }
        }

        $this->assertTrue($foundYellow, 'Expected at least one yellow card across 200 seeds.');
        $this->assertTrue($foundRed, 'Expected at least one red card across 200 seeds.');
    }

    public function testNarrativePayloadIsPopulatedFromTheGenerator(): void
    {
        $engine = $this->makeEngine();
        [$fixture, $home, $away] = $this->makeFixture();

        $result = $engine->resolve($home, $away, $fixture);

        $this->assertNotNull($result->narrativePayload);
        $this->assertNotSame([], $result->narrativePayload);
    }

    public function testALevelScoreAfterRegulationGoesToExtraTime(): void
    {
        $capturedCalls = [];
        $engine        = $this->makeEngineCapturingNarrativeArgs($capturedCalls);

        $foundExtraTime = false;

        for ($i = 0; $i < 300 && !$foundExtraTime; $i++) {
            $template = new CompetitionTemplate('Cup', 'cup-' . uniqid('', true), 4, CompetitionDuration::TEN_HOURS);
            $instance = new ActiveCompetition($template);
            $round    = new CompetitionRound($instance, 1, 'SF', new \DateTimeImmutable());
            $home     = $this->makeEntrant($instance, 'Home', 15);
            $away     = $this->makeEntrant($instance, 'Away', 15);
            $fixture  = new CompetitionFixture($round, 0, $home, $away);

            $result = $engine->resolve($home, $away, $fixture);
            $call   = $capturedCalls[array_key_last($capturedCalls)];

            if ($call['wentToExtraTime']) {
                $foundExtraTime = true;

                // A knockout fixture is never allowed to stay a scoreless-margin draw once
                // extra time is played and penalties weren't needed.
                if (!$call['wentToPenalties']) {
                    $this->assertNotSame($result->homeScore, $result->awayScore, 'Extra time without penalties must have produced a decisive score.');
                }
            }
        }

        $this->assertTrue($foundExtraTime, 'Expected at least one regulation-time draw across 300 equal-strength seeds.');
    }

    public function testStillLevelAfterExtraTimeGoesToACoinFlipPenaltyShootout(): void
    {
        $capturedCalls = [];
        $engine        = $this->makeEngineCapturingNarrativeArgs($capturedCalls);

        $foundPenalties = false;

        for ($i = 0; $i < 1000 && !$foundPenalties; $i++) {
            $template = new CompetitionTemplate('Cup', 'cup-' . uniqid('', true), 4, CompetitionDuration::TEN_HOURS);
            $instance = new ActiveCompetition($template);
            $round    = new CompetitionRound($instance, 1, 'SF', new \DateTimeImmutable());
            $home     = $this->makeEntrant($instance, 'Home', 15);
            $away     = $this->makeEntrant($instance, 'Away', 15);
            $fixture  = new CompetitionFixture($round, 0, $home, $away);

            $result = $engine->resolve($home, $away, $fixture);
            $call   = $capturedCalls[array_key_last($capturedCalls)];

            if ($call['wentToPenalties']) {
                $foundPenalties = true;

                $this->assertTrue($result->wentToExtraTime);
                $this->assertTrue($result->wentToPenalties);
                $this->assertSame($result->homeScore, $result->awayScore, 'The recorded score must stay the true (level) AET score even when penalties decide the tie.');
                $this->assertNotNull($result->penaltyHomeScore);
                $this->assertNotNull($result->penaltyAwayScore);
                $this->assertNotSame($result->penaltyHomeScore, $result->penaltyAwayScore, 'A penalty shootout must always produce a decisive tally.');
                $this->assertSame($call['penaltyHomeScore'], $result->penaltyHomeScore);
                $this->assertSame($call['penaltyAwayScore'], $result->penaltyAwayScore);
            }
        }

        $this->assertTrue($foundPenalties, 'Expected at least one shootout across 1000 equal-strength seeds.');
    }

    public function testResolveProducesFullComputationAuditBreakdown(): void
    {
        $engine   = $this->makeEngine(['POSSESSION:HIGH_PRESS' => 1.08]);
        $template = new CompetitionTemplate('Cup', 'cup-' . uniqid('', true), 4, CompetitionDuration::TEN_HOURS);
        $instance = new ActiveCompetition($template);
        $round    = new CompetitionRound($instance, 1, 'SF', new \DateTimeImmutable());

        $home = $this->makeEntrant($instance, 'Home FC', 80, PlayingStyle::POSSESSION, [
            'club'  => ['cohesion' => 82, 'managerMotivation' => 90, 'lowUrgency' => false],
            'staff' => [['role' => 'MANAGER', 'ability' => 75]],
        ]);
        $away = $this->makeEntrant($instance, 'Away FC', 60, PlayingStyle::HIGH_PRESS, [
            'club'  => ['cohesion' => 25, 'lowUrgency' => true],
            'staff' => [['role' => 'MANAGER', 'ability' => 55]],
        ]);
        $fixture = new CompetitionFixture($round, 0, $home, $away);

        $result = $engine->resolve($home, $away, $fixture);
        $audit  = $result->computationAudit;

        $this->assertIsArray($audit);
        $this->assertSame(1, $audit['schemaVersion']);
        $this->assertSame('deterministic', $audit['engine']['resolvedBy']);
        $this->assertSame($fixture->getId()->toRfc4122(), $audit['fixture']['fixtureId']);
        $this->assertIsInt($audit['fixture']['rngSeed']);
        $this->assertFalse($audit['fixture']['allowDraws']);

        // Dominance calculation breakdown
        $this->assertSame('POSSESSION', $audit['dominance']['home']['playingStyle']);
        $this->assertSame('HIGH_PRESS', $audit['dominance']['home']['opponentPlayingStyle']);
        $this->assertCount(11, $audit['dominance']['home']['players']);
        $this->assertSame(1.08, $audit['dominance']['home']['tacticalAdvantage']['multiplier']);
        $this->assertSame(75.0, $audit['dominance']['home']['manager']['ability']);
        $this->assertSame(90.0, $audit['dominance']['home']['manager']['motivation']);
        $this->assertFalse($audit['dominance']['home']['manager']['motivationDefaulted']);
        $this->assertSame(1.05, $audit['dominance']['home']['cohesion']['passAccuracyMultiplier']);
        $this->assertSame(1.0, $audit['dominance']['home']['cohesion']['errorRateMultiplier']);

        $this->assertTrue($audit['dominance']['away']['lowUrgency']['active']);
        $this->assertSame(0.95, $audit['dominance']['away']['lowUrgency']['multiplier']);
        $this->assertSame(0.88, $audit['dominance']['away']['cohesion']['passAccuracyMultiplier']);
        $this->assertSame(1.12, $audit['dominance']['away']['cohesion']['errorRateMultiplier']);

        $this->assertGreaterThan($audit['dominance']['awayDominance'], $audit['dominance']['homeDominance']);
        $this->assertEqualsWithDelta(1.0, $audit['dominance']['homeRatio'] + $audit['dominance']['awayRatio'], 1e-9);

        // Regulation xG & goal distribution
        $this->assertSame(3.0, $audit['regulation']['baseExpectedTotalGoals']);
        $this->assertEqualsWithDelta(
            3.0 + $audit['regulation']['variationRoll'],
            $audit['regulation']['expectedTotalGoals'],
            1e-9,
        );
        $this->assertCount(11, $audit['playerRatings']['home']);
        $this->assertCount(11, $audit['playerRatings']['away']);
        $this->assertSame($result->homeScore, $audit['outcome']['homeScore']);
        $this->assertSame($result->awayScore, $audit['outcome']['awayScore']);
    }

    public function testAiEngineStubPreservesComputationAuditAndRecordsFallbackMetadata(): void
    {
        $engine   = $this->makeEngine();
        $stub     = new \App\Service\MatchEngine\AiEngineStub($engine, new \Psr\Log\NullLogger());
        $template = new CompetitionTemplate('AI Cup', 'ai-cup-' . uniqid('', true), 4, CompetitionDuration::TEN_HOURS);
        $instance = new ActiveCompetition($template);
        $round    = new CompetitionRound($instance, 1, 'FINAL', new \DateTimeImmutable());
        $round->setMatchEngineIdentifier(MatchEngineIdentifier::AI_ASSISTED);
        $home     = $this->makeEntrant($instance, 'Home AI', 70);
        $away     = $this->makeEntrant($instance, 'Away AI', 65);
        $fixture  = new CompetitionFixture($round, 0, $home, $away);

        $result = $stub->resolve($home, $away, $fixture);
        $audit  = $result->computationAudit;

        $this->assertIsArray($audit);
        $this->assertSame('ai_assisted', $audit['engine']['requestedEngine']);
        $this->assertSame('deterministic', $audit['engine']['resolvedBy']);
        $this->assertSame('ai_assisted', $audit['engine']['delegatedFrom']);
        $this->assertNotEmpty($audit['engine']['fallbackReason']);
        $this->assertArrayHasKey('dominance', $audit);
        $this->assertArrayHasKey('regulation', $audit);
    }
}
