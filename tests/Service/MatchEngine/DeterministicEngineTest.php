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
use App\Service\MatchEngine\DeterministicEngine;
use PHPUnit\Framework\TestCase;

class DeterministicEngineTest extends TestCase
{
    private function makeEntrant(ActiveCompetition $instance, string $name, int $strength): CompetitionEntrant
    {
        $user = new User("$name@example.com");
        $club = new Club($name, $user);

        $players = [];
        for ($i = 0; $i < 11; $i++) {
            $players[] = ['id' => "$name-p$i", 'position' => 'MID', 'currentAbility' => $strength];
        }

        return new CompetitionEntrant($instance, $club, 0, ['club' => ['id' => (string) $club->getId(), 'name' => $name], 'players' => $players]);
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
        $engine = new DeterministicEngine();
        $this->assertTrue($engine->supports(MatchEngineIdentifier::DETERMINISTIC));
        $this->assertFalse($engine->supports(MatchEngineIdentifier::AI_ASSISTED));
        $this->assertFalse($engine->supports(MatchEngineIdentifier::AI_NARRATIVE));
    }

    public function testSameFixtureIdProducesTheSameResultEveryTime(): void
    {
        $engine = new DeterministicEngine();
        [$fixture, $home, $away] = $this->makeFixture();

        $first  = $engine->resolve($home, $away, $fixture);
        $second = $engine->resolve($home, $away, $fixture);

        $this->assertSame($first->homeScore, $second->homeScore);
        $this->assertSame($first->awayScore, $second->awayScore);
        $this->assertSame($first->eventLog, $second->eventLog);
    }

    public function testEventLogIsOrderedByMinute(): void
    {
        $engine = new DeterministicEngine();
        [$fixture, $home, $away] = $this->makeFixture();

        $result = $engine->resolve($home, $away, $fixture);
        $minutes = array_column($result->eventLog, 'minute');

        $sorted = $minutes;
        sort($sorted);
        $this->assertSame($sorted, $minutes);
    }

    public function testAStrongerSnapshotWinsMoreOftenAcrossManySeeds(): void
    {
        $engine       = new DeterministicEngine();
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
}
