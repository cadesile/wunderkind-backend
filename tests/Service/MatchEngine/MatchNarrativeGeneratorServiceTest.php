<?php

declare(strict_types=1);

namespace App\Tests\Service\MatchEngine;

use App\Command\SeedMatchNarrativeTemplatesCommand;
use App\Entity\Club;
use App\Entity\Competition\ActiveCompetition;
use App\Entity\Competition\CompetitionEntrant;
use App\Entity\Competition\CompetitionFixture;
use App\Entity\Competition\CompetitionRound;
use App\Entity\Competition\CompetitionTemplate;
use App\Entity\GameEventTemplate;
use App\Entity\User;
use App\Enum\Competition\CompetitionDuration;
use App\Enum\EventCategory;
use App\Repository\GameEventTemplateRepository;
use App\Service\MatchEngine\MatchNarrativeGeneratorService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class MatchNarrativeGeneratorServiceTest extends TestCase
{
    /** @return GameEventTemplate[] */
    private function seededRows(): array
    {
        $command = new SeedMatchNarrativeTemplatesCommand(
            $this->createMock(GameEventTemplateRepository::class),
            $this->createMock(EntityManagerInterface::class),
        );

        $reflection = new \ReflectionMethod($command, 'buildTemplates');
        $rows       = [];
        foreach ($reflection->invoke($command) as $data) {
            $template = new GameEventTemplate($data['slug'], $data['category'], $data['title'], $data['bodyTemplate'], $data['impacts'], $data['weight']);
            $template->setChainedEventsArray($data['chainedEvents']);
            $rows[] = $template;
        }

        return $rows;
    }

    private function makeService(): MatchNarrativeGeneratorService
    {
        $repo = $this->createMock(GameEventTemplateRepository::class);
        $repo->method('findByCategory')->willReturnCallback(
            fn (EventCategory $category) => $category === EventCategory::MATCH_NARRATIVE ? $this->seededRows() : [],
        );

        return new MatchNarrativeGeneratorService($repo);
    }

    private function makeEntrant(ActiveCompetition $instance, string $name): CompetitionEntrant
    {
        $user  = new User("$name@example.com");
        $club  = new Club($name, $user);
        $players = [];
        $positions = ['GK', 'DEF', 'DEF', 'DEF', 'DEF', 'MID', 'MID', 'MID', 'ATT', 'ATT', 'ATT'];
        foreach ($positions as $i => $position) {
            $players[] = ['id' => "$name-p$i", 'position' => $position, 'name' => "$name Player $i", 'currentAbility' => 60];
        }

        return new CompetitionEntrant($instance, $club, 0, ['club' => ['id' => (string) $club->getId(), 'name' => $name], 'players' => $players]);
    }

    /** @return array{CompetitionFixture, CompetitionEntrant, CompetitionEntrant} */
    private function makeFixture(): array
    {
        $template = new CompetitionTemplate('Cup', 'cup-' . uniqid('', true), 4, CompetitionDuration::TEN_HOURS);
        $instance = new ActiveCompetition($template);
        $round    = new CompetitionRound($instance, 1, 'SF', new \DateTimeImmutable());
        $home     = $this->makeEntrant($instance, 'Home');
        $away     = $this->makeEntrant($instance, 'Away');

        return [new CompetitionFixture($round, 0, $home, $away), $home, $away];
    }

    private function lineupFor(CompetitionEntrant $entrant, array $goalStats = [], array $cardStats = []): array
    {
        $lineup = [];
        foreach ($entrant->getSnapshotJson()['players'] as $p) {
            $lineup[] = [
                'id' => $p['id'], 'name' => $p['name'], 'position' => $p['position'],
                'goals' => $goalStats[$p['id']]['goals'] ?? 0, 'assists' => $goalStats[$p['id']]['assists'] ?? 0,
                'yellowCards' => $cardStats[$p['id']]['yellowCards'] ?? 0, 'redCards' => $cardStats[$p['id']]['redCards'] ?? 0,
                'rating' => 6.0,
            ];
        }

        return $lineup;
    }

    public function testSameFixtureIdProducesTheSameNarrativeEveryTime(): void
    {
        $service = $this->makeService();
        [$fixture, $home, $away] = $this->makeFixture();
        $eventLog = [['minute' => 30, 'type' => 'goal', 'team' => 'home', 'scorer' => 'Home-p8', 'assist' => 'Home-p6']];
        $homeLineup = $this->lineupFor($home, ['Home-p8' => ['goals' => 1]]);
        $awayLineup = $this->lineupFor($away);

        $first  = $service->generate($fixture, $home, $away, $eventLog, $homeLineup, $awayLineup);
        $second = $service->generate($fixture, $home, $away, $eventLog, $homeLineup, $awayLineup);

        $this->assertSame($first, $second);
    }

    public function testGoalChainAlwaysEndsOnAKeyGoalEvent(): void
    {
        $service = $this->makeService();

        for ($i = 0; $i < 25; $i++) {
            [$fixture, $home, $away] = $this->makeFixture();
            $eventLog   = [['minute' => 40, 'type' => 'goal', 'team' => 'home', 'scorer' => 'Home-p8', 'assist' => 'Home-p6']];
            $homeLineup = $this->lineupFor($home, ['Home-p8' => ['goals' => 1]]);
            $awayLineup = $this->lineupFor($away);

            $items = $service->generate($fixture, $home, $away, $eventLog, $homeLineup, $awayLineup);

            $goalKeyEvents = array_values(array_filter($items, static fn (array $item) => $item['eventType'] === 'GOAL'));
            $this->assertCount(1, $goalKeyEvents, "Expected exactly one GOAL key event on trial $i.");
            $this->assertTrue($goalKeyEvents[0]['isKeyEvent']);
        }
    }

    public function testFillerItemsNeverCarryAScoringEventType(): void
    {
        $service = $this->makeService();
        [$fixture, $home, $away] = $this->makeFixture();
        $homeLineup = $this->lineupFor($home);
        $awayLineup = $this->lineupFor($away);

        $items = $service->generate($fixture, $home, $away, [], $homeLineup, $awayLineup);

        foreach ($items as $item) {
            if ($item['eventType'] === 'FILLER') {
                $this->assertFalse($item['isKeyEvent']);
            }
        }
    }

    public function testCardEventProducesAFoulFillerFollowedByTheCardKeyEvent(): void
    {
        $service = $this->makeService();
        [$fixture, $home, $away] = $this->makeFixture();
        $eventLog   = [['minute' => 55, 'type' => 'yellow_card', 'team' => 'away', 'player' => 'Away-p3']];
        $homeLineup = $this->lineupFor($home);
        $awayLineup = $this->lineupFor($away, [], ['Away-p3' => ['yellowCards' => 1]]);

        $items = $service->generate($fixture, $home, $away, $eventLog, $homeLineup, $awayLineup);

        $cardEvents = array_values(array_filter($items, static fn (array $i) => $i['eventType'] === 'YELLOW_CARD'));
        $this->assertCount(1, $cardEvents);
        $this->assertSame(55, $cardEvents[0]['minute']);
        $this->assertTrue($cardEvents[0]['isKeyEvent']);

        $foulEvents = array_values(array_filter($items, static fn (array $i) => str_contains($i['text'], 'foul') || $i['eventType'] === 'FILLER'));
        $this->assertNotEmpty($foulEvents);
    }

    public function testTimelineContainsHalfTimeAndFullTimeMarkersAndIsMinuteOrdered(): void
    {
        $service = $this->makeService();
        [$fixture, $home, $away] = $this->makeFixture();
        $homeLineup = $this->lineupFor($home);
        $awayLineup = $this->lineupFor($away);

        $items = $service->generate($fixture, $home, $away, [], $homeLineup, $awayLineup);

        $texts = array_column($items, 'text');
        $this->assertContains('Half-time.', $texts);
        $this->assertContains('Full-time.', $texts);

        $minutes = array_column($items, 'minute');
        $sorted  = $minutes;
        sort($sorted);
        $this->assertSame($sorted, $minutes);
    }

    public function testEmptyTemplateSetReturnsEmptyPayload(): void
    {
        $repo = $this->createMock(GameEventTemplateRepository::class);
        $repo->method('findByCategory')->willReturn([]);
        $service = new MatchNarrativeGeneratorService($repo);

        [$fixture, $home, $away] = $this->makeFixture();
        $items = $service->generate($fixture, $home, $away, [], $this->lineupFor($home), $this->lineupFor($away));

        $this->assertSame([], $items);
    }

    public function testExtraTimeAddsAGoingToExtraTimeMarkerAndExtendsTheFillerWindow(): void
    {
        $service = $this->makeService();
        [$fixture, $home, $away] = $this->makeFixture();
        $eventLog   = [['minute' => 105, 'type' => 'goal', 'team' => 'home', 'scorer' => 'Home-p8', 'assist' => null]];
        $homeLineup = $this->lineupFor($home, ['Home-p8' => ['goals' => 1]]);
        $awayLineup = $this->lineupFor($away);

        $items = $service->generate($fixture, $home, $away, $eventLog, $homeLineup, $awayLineup, wentToExtraTime: true);

        $texts = array_column($items, 'text');
        $this->assertContains('Half-time.', $texts);
        $this->assertContains('Full-time in normal time — this one\'s going to extra time!', $texts);
        $this->assertContains('Full-time.', $texts);

        $minutes = array_column($items, 'minute');
        $this->assertGreaterThan(105, max($minutes), 'The final Full-time marker must land after the extra-time goal.');

        $sorted = $minutes;
        sort($sorted);
        $this->assertSame($sorted, $minutes);
    }

    public function testRegulationOnlyMatchHasNoExtraTimeOrPenaltyMarkers(): void
    {
        $service = $this->makeService();
        [$fixture, $home, $away] = $this->makeFixture();

        $items = $service->generate($fixture, $home, $away, [], $this->lineupFor($home), $this->lineupFor($away));

        $texts = array_column($items, 'text');
        $this->assertNotContains('Full-time in normal time — this one\'s going to extra time!', $texts);
        $this->assertSame([], array_values(array_filter($items, static fn (array $i) => $i['eventType'] === 'PENALTY_SHOOTOUT')));
    }

    public function testPenaltyShootoutAddsAFinalKeySummaryLineNamingTheWinnerAndScore(): void
    {
        $service = $this->makeService();
        [$fixture, $home, $away] = $this->makeFixture();
        $homeLineup = $this->lineupFor($home);
        $awayLineup = $this->lineupFor($away);

        $items = $service->generate($fixture, $home, $away, [], $homeLineup, $awayLineup, wentToExtraTime: true, wentToPenalties: true, penaltyHomeScore: 5, penaltyAwayScore: 4);

        $shootoutEvents = array_values(array_filter($items, static fn (array $i) => $i['eventType'] === 'PENALTY_SHOOTOUT'));
        $this->assertCount(1, $shootoutEvents);
        $this->assertTrue($shootoutEvents[0]['isKeyEvent']);
        $this->assertSame('Home win 5-4 on penalties!', $shootoutEvents[0]['text']);

        // The shootout summary must be the very last item chronologically.
        $minutes = array_column($items, 'minute');
        $this->assertSame(max($minutes), $shootoutEvents[0]['minute']);
    }
}
