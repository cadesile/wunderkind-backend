<?php

declare(strict_types=1);

namespace App\Tests\Service\Competition;

use App\Service\Competition\CupResultsBoardService;
use App\Tests\Support\EntrantFixtureTrait;
use App\Tests\Support\PlayedCupFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The landing page's #cup-results board, built with the winner on the AWAY side.
 *
 * Away is the case worth a test: `CompetitionResult` stores everything by home/away —
 * scores, penalty scores, the two frozen club blocks and the two lineups — while the board
 * presents everything by winner/runner-up. So one `isHome()` check decides six separate
 * swaps, and with the winner at home every one of them is right by accident.
 *
 * The competition tables are truncated in setUp(), the way RewardApplierServiceTest and the
 * other classes in this directory do it: the board reads "the most recently completed cups"
 * globally, with no scope to narrow it to one fixture, so leftovers from another class would
 * decide which cup is `latest` here.
 */
class CupResultsBoardServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CupResultsBoardService $service;

    use EntrantFixtureTrait;
    use PlayedCupFixtureTrait;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em      = self::getContainer()->get(EntityManagerInterface::class);
        $this->service = self::getContainer()->get(CupResultsBoardService::class);

        $this->em->getConnection()->executeStatement(
            'TRUNCATE competition_result, competition_result_audit, competition_fixture,
                      competition_round, competition_entrant_squad, competition_entrant,
                      active_competition, competition_template CASCADE',
        );
    }

    public function testTheBoardReportsAnAwaySideWinnerAsTheChampion(): void
    {
        $name = $this->playedCup($this->em)->templateName;

        $latest = $this->service->getBoard()['latest'];
        self::assertSame($name, $latest['name'], 'the future completedAt must make this the latest cup');

        // The frozen away block is the winner, the frozen home block the runner-up — the
        // whole point of the side swap.
        self::assertSame('Away Winners FC', $latest['winner']['name']);
        self::assertSame('Home Losers FC', $latest['runnerUp']['name']);
        self::assertSame('ES', $latest['winner']['countryCode']);

        // Both scored 1, so a swapped score would read identically here. The PENALTY scores
        // are 5-3, which is why they are the assertion that can actually fail.
        self::assertSame(1, $latest['winner']['score']);
        self::assertSame(1, $latest['runnerUp']['score']);
        self::assertTrue($latest['wentToExtraTime']);
        self::assertTrue($latest['wentToPenalties']);
        self::assertSame(5, $latest['penaltyWinner'], 'the away side won the shootout 5-3');
        self::assertSame(3, $latest['penaltyLoser']);

        self::assertSame(4, $latest['capacity']);
        self::assertSame(500000, $latest['prizePence']);
        self::assertSame('cup', $latest['trophy']['style']);

        self::assertSame([], $this->service->getBoard()['earlier'], 'one cup played, so nothing is earlier');
    }

    /**
     * The road reads the winner's own semi-final, in which the winner was ALSO away — so a
     * for/against pair taken straight off the result would come back reversed.
     */
    public function testTheRoadToTheFinalReportsScoresFromTheWinnersPointOfView(): void
    {
        $this->playedCup($this->em);

        $road = $this->service->getBoard()['latest']['road'];

        self::assertCount(1, $road, 'the final itself is shown separately and must not appear in the road');
        self::assertSame('SF', $road[0]['round']);
        self::assertSame('Semi Victims FC', $road[0]['opponent']['name']);
        self::assertSame(3, $road[0]['for'], 'the winner scored 3 away, not 0');
        self::assertSame(0, $road[0]['against']);
        self::assertFalse($road[0]['pens']);
    }

    /**
     * Player of the final is read off the WINNER's lineup, which is the away one here — and
     * dressed in the winner's home kit, so the sprite is not drawn in the loser's colours.
     */
    public function testPlayerOfTheFinalComesFromTheWinningSideWearingItsKit(): void
    {
        $this->playedCup($this->em);

        $figures = $this->service->getBoard()['latest']['figures'];
        self::assertSame(['owner', 'manager', 'trophy', 'player'], array_column($figures, 'kind'));

        $player = $figures[3];
        // The home lineup holds a 9.9 — higher than anyone away — so reading the wrong side
        // names Home Star instead.
        self::assertSame('Away Match Winner', $player['name'], 'the best player on the WINNING side, not the best on the pitch');
        self::assertSame('2 GOALS · 8.4', $player['stat']);
        self::assertSame('#112233', $player['sprite']['primary'], "the winner's home kit is merged into the appearance");
        self::assertSame('stripes', $player['sprite']['kit']);
        self::assertSame('bald', $player['sprite']['hair'], 'the stored appearance survives the kit merge');

        // Owner and manager are resolveOwner()/resolveManager() stubs for now; the board must
        // render the slots empty rather than attributing a default face to a named person.
        self::assertSame('—', $figures[0]['name']);
        self::assertNull($figures[0]['sprite']);
        self::assertSame('—', $figures[1]['name']);
        self::assertSame('4-4-2', $figures[1]['stat'], "the manager slot carries the winner's frozen formation");
    }

    /**
     * An instance marked COMPLETED with no completedAt is one admin form save away
     * (ActiveCompetitionCrudController offers `status` and `completedAt` independently), and
     * Postgres orders `completed_at DESC` NULLS FIRST — so the looser query would hand that
     * row back first and the page would bill it as the latest cup final.
     */
    public function testAnUndatedCompletedCupCannotPoseAsTheLatestFinal(): void
    {
        $name = $this->playedCup($this->em)->templateName;
        $this->playedCup($this->em, completedAt: null);

        $board = $this->service->getBoard();
        self::assertSame($name, $board['latest']['name']);
        self::assertSame([], $board['earlier'], 'the undated cup is excluded outright, not demoted');
    }
}
