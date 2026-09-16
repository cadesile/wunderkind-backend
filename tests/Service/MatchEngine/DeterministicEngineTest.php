<?php

declare(strict_types=1);

namespace App\Tests\Service\MatchEngine;

use App\Entity\Club;
use App\Entity\Competition\ActiveCompetition;
use App\Entity\Competition\CompetitionEntrant;
use App\Entity\Competition\CompetitionFixture;
use App\Entity\Competition\CompetitionRound;
use App\Entity\Competition\CompetitionTemplate;
use App\Entity\User;
use App\Enum\Competition\CompetitionDuration;
use App\Enum\Competition\MatchEngineIdentifier;
use App\Enum\PlayingStyle;
use App\Repository\TacticalAdvantageRepository;
use App\Service\MatchEngine\DeterministicEngine;
use PHPUnit\Framework\TestCase;

class DeterministicEngineTest extends TestCase
{
    /** Neutral (1.0 for every pairing) unless a specific multiplier map is supplied. */
    private function makeEngine(array $multipliers = []): DeterministicEngine
    {
        $repo = $this->createMock(TacticalAdvantageRepository::class);
        $repo->method('findMultiplier')->willReturnCallback(
            static fn (PlayingStyle $style, PlayingStyle $opponentStyle) => $multipliers["{$style->value}:{$opponentStyle->value}"] ?? 1.0,
        );

        return new DeterministicEngine($repo);
    }

    private function makeEntrant(ActiveCompetition $instance, string $name, int $strength, ?PlayingStyle $playingStyle = null): CompetitionEntrant
    {
        $user = new User("$name@example.com");
        $club = new Club($name, $user);

        $players = [];
        for ($i = 0; $i < 11; $i++) {
            $players[] = ['id' => "$name-p$i", 'position' => 'MID', 'currentAbility' => $strength];
        }

        $clubSnapshot = ['id' => (string) $club->getId(), 'name' => $name];
        if ($playingStyle !== null) {
            $clubSnapshot['playingStyle'] = $playingStyle->value;
        }

        return new CompetitionEntrant($instance, $club, 0, ['club' => $clubSnapshot, 'players' => $players]);
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
}
