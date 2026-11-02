<?php

declare(strict_types=1);

namespace App\Tests\Service\Competition;

use App\Entity\Club;
use App\Entity\Competition\ActiveCompetition;
use App\Entity\Competition\CompetitionEntrant;
use App\Entity\Competition\CompetitionFixture;
use App\Entity\Competition\CompetitionResult;
use App\Entity\Competition\CompetitionRound;
use App\Entity\Competition\CompetitionTemplate;
use App\Entity\User;
use App\Enum\Competition\ActiveCompetitionStatus;
use App\Enum\Competition\CompetitionDuration;
use App\Enum\Competition\CompetitionFixtureStatus;
use App\Enum\Competition\CompetitionRoundStatus;
use App\Enum\Competition\MatchEngineIdentifier;
use App\Service\Competition\CupTelemetryService;
use App\Tests\Support\EntrantFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The CUPS band in the Live Telemetry terminal, and the rows it hands the feed.
 *
 * Truncates the competition tables in setUp() like the other classes here: the band reads
 * "what is live / open / recently won" globally, with no scope to narrow it to one fixture,
 * so another class's leftovers would decide what it reports.
 */
class CupTelemetryServiceTest extends KernelTestCase
{
    use EntrantFixtureTrait;

    private EntityManagerInterface $em;
    private CupTelemetryService $service;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em      = self::getContainer()->get(EntityManagerInterface::class);
        $this->service = self::getContainer()->get(CupTelemetryService::class);

        $this->em->getConnection()->executeStatement(
            'TRUNCATE competition_result, competition_result_audit, competition_fixture,
                      competition_round, competition_entrant_squad, competition_entrant,
                      active_competition, competition_template CASCADE',
        );
    }

    /**
     * The state the terminal spent its whole life in before cups existed: no band at all,
     * so the section renders exactly as it did.
     */
    public function testNothingLiveOpenOrEverWonMeansNoBand(): void
    {
        self::assertNull($this->service->getBand());
    }

    /** A drawn round reports its fixtures and counts down to the results. */
    public function testADrawnRoundReportsItsFixturesAndCountsDownToTheResults(): void
    {
        // Truncated to the second: the column has no microsecond precision, so a value
        // carrying them never comes back equal to what went in.
        $resolveAt = new \DateTimeImmutable((new \DateTimeImmutable('+3 hours'))->format('Y-m-d H:i:s'));
        $this->liveCup('Spring Cup', 'SF', CompetitionRoundStatus::DRAWN, $resolveAt);

        $band = $this->service->getBand();
        self::assertNotNull($band);
        self::assertCount(1, $band['live']);

        $cup = $band['live'][0];
        self::assertSame('SEMI-FINALS', $cup['roundName']);
        self::assertSame('SF', $cup['roundKey']);
        self::assertSame('RESULTS IN', $cup['timerLabel']);
        self::assertEquals($resolveAt, $cup['timerAt']);
        self::assertCount(1, $cup['fixtures']);
        self::assertSame('Home Losers FC', $cup['fixtures'][0]['home']['name']);
        self::assertEquals($resolveAt, $band['nextResultAt']);
    }

    /**
     * A round still awaiting its draw has no fixtures to show, so the tile counts down to
     * the DRAW instead — and contributes nothing to the header's "NEXT RESULT IN".
     */
    public function testARoundAwaitingItsDrawCountsDownToTheDrawAndShowsNoFixtures(): void
    {
        $this->liveCup('Spring Cup', 'SF', CompetitionRoundStatus::DRAW_PENDING, null);

        $band = $this->service->getBand();
        $cup  = $band['live'][0];

        self::assertSame('DRAW IN', $cup['timerLabel']);
        self::assertSame([], $cup['fixtures']);
        self::assertNull($band['nextResultAt'], 'a pending draw is not a pending result');
    }

    /**
     * matchesResolveAt is nullable, and the band's sort compares it. PHP orders null BELOW
     * any DateTimeImmutable, so an untimed cup would otherwise sort as the most imminent of
     * all — and `min()` over the result times would return the null and hide the header
     * countdown that a real, timed cup had earned.
     */
    public function testACupWithNoResolveTimeSortsLastAndDoesNotHideTheHeaderCountdown(): void
    {
        $soon = new \DateTimeImmutable((new \DateTimeImmutable('+2 hours'))->format('Y-m-d H:i:s'));
        $this->liveCup('Untimed Cup', 'SF', CompetitionRoundStatus::DRAWN, null);
        $this->liveCup('Timed Cup', 'SF', CompetitionRoundStatus::DRAWN, $soon);

        $band = $this->service->getBand();

        self::assertSame(['Timed Cup', 'Untimed Cup'], array_column($band['live'], 'name'));
        self::assertEquals($soon, $band['nextResultAt'], 'the untimed cup must not win min()');
    }

    /** The final is the tile the band is built around, so it leads however it was fetched. */
    public function testTheFinalSortsAheadOfAnEarlierRound(): void
    {
        $this->liveCup('Early Cup', 'SF', CompetitionRoundStatus::DRAWN, new \DateTimeImmutable('+1 hour'));
        $this->liveCup('Final Cup', 'FINAL', CompetitionRoundStatus::DRAWN, new \DateTimeImmutable('+9 hours'));

        $band = $this->service->getBand();

        self::assertSame(['Final Cup', 'Early Cup'], array_column($band['live'], 'name'));
        self::assertTrue($band['live'][0]['isFinal']);
        self::assertFalse($band['live'][1]['isFinal']);
    }

    /**
     * Winners-only: nothing live and nothing open, but a cup has been won. The band still
     * renders for the Recent Winners column — the template's "CUPS // NONE LIVE" state.
     */
    public function testAFinishedCupKeepsTheBandAliveWithNoLiveCups(): void
    {
        $this->completedCup('Spring Cup', new \DateTimeImmutable('-2 days'));

        $band = $this->service->getBand();
        self::assertNotNull($band);
        self::assertSame([], $band['live']);
        self::assertSame([], $band['open']);
        self::assertCount(1, $band['winners']);

        $winner = $band['winners'][0];
        self::assertSame('Away Winners FC', $winner['clubName'], 'the AWAY side won, so the frozen away block is the champion');
        self::assertSame('Spring Cup', $winner['cupName']);
        self::assertSame('2d ago', $winner['ago']);
    }

    /** An open cup reports its entry progress. */
    public function testAnOpenCupReportsItsEntryProgress(): void
    {
        $template = $this->template('Open Cup');
        $competition = new ActiveCompetition($template);
        $competition->setStatus(ActiveCompetitionStatus::REGISTERING);
        $this->em->persist($competition);
        $this->entrant($competition, 'Early Bird FC', 1);
        $this->em->flush();
        $this->em->clear();

        $open = $this->service->getBand()['open'];

        self::assertCount(1, $open);
        self::assertSame('Open Cup', $open[0]['name']);
        self::assertSame(1, $open[0]['entered']);
        self::assertSame(4, $open[0]['capacity']);
        self::assertSame(500000, $open[0]['prizePence']);
    }

    /**
     * The feed's rows are window-bounded, unlike the band's winners — "a cup was won in the
     * last 7 days", not "the last few cups ever".
     */
    public function testTheFeedRowsAreBoundedByTheWindowAndReportTheWinnersScoreFirst(): void
    {
        $this->completedCup('Recent Cup', new \DateTimeImmutable('-2 days'));
        $this->completedCup('Ancient Cup', new \DateTimeImmutable('-40 days'));

        $rows = $this->service->recentWinnerRows(new \DateTimeImmutable('-7 days'), 5);

        self::assertCount(1, $rows, 'the 40-day-old cup is outside the window');
        self::assertSame('Recent Cup', $rows[0]['cupName']);
        self::assertSame('Away Winners FC', $rows[0]['clubName']);
        // Stored home 1 / away 3 with the away side winning — so a row that reported
        // home/away rather than winner/loser would read 1 – 3 for the champion.
        self::assertSame(3, $rows[0]['winnerScore']);
        self::assertSame(0, $rows[0]['loserScore']);
        self::assertSame(500000, $rows[0]['prizePence']);
        self::assertSame('cup', $rows[0]['trophy']['style']);
    }

    /** An undated cup cannot be placed in the window at all, so it is never "recent". */
    public function testAnUndatedCompletedCupIsNeitherARecentWinnerNorAFeedRow(): void
    {
        $this->completedCup('Undated Cup', null);

        self::assertNull($this->service->getBand(), 'no live, no open, and no datable winner');
        self::assertSame([], $this->service->recentWinnerRows(new \DateTimeImmutable('-7 days'), 5));
    }

    private function liveCup(string $name, string $label, CompetitionRoundStatus $status, ?\DateTimeImmutable $resolveAt): void
    {
        $competition = new ActiveCompetition($this->template($name));
        $competition->setStatus(ActiveCompetitionStatus::RUNNING);
        $this->em->persist($competition);

        $home = $this->entrant($competition, 'Home Losers FC', 1);
        $away = $this->entrant($competition, 'Away Winners FC', 2);

        // Capacity 4 labels its rounds ['SF', 'FINAL']; findCurrentRound() picks the lowest
        // index not yet published, so one round is enough to pin which one that is.
        $round = new CompetitionRound($competition, $label === 'FINAL' ? 2 : 1, $label, new \DateTimeImmutable('+1 hour'));
        $round->setStatus($status);
        $round->setMatchesResolveAt($resolveAt);
        $this->em->persist($round);

        if ($status === CompetitionRoundStatus::DRAWN) {
            $fixture = new CompetitionFixture($round, 0, $home, $away);
            $this->em->persist($fixture);
        }

        $this->em->flush();
        $this->em->clear();
    }

    /** A cup settled from the AWAY side, so every winner/runner-up swap is exercised. */
    private function completedCup(string $name, ?\DateTimeImmutable $completedAt): void
    {
        $competition = new ActiveCompetition($this->template($name));
        $competition->setStatus(ActiveCompetitionStatus::COMPLETED);
        $competition->setCompletedAt($completedAt);
        $this->em->persist($competition);

        $home = $this->entrant($competition, 'Home Losers FC', 1);
        $away = $this->entrant($competition, 'Away Winners FC', 2);

        $round = new CompetitionRound($competition, 1, 'FINAL', new \DateTimeImmutable('-3 hours'));
        $round->setStatus(CompetitionRoundStatus::RESULTS_PUBLISHED);
        $this->em->persist($round);

        $fixture = new CompetitionFixture($round, 0, $home, $away);
        $fixture->setStatus(CompetitionFixtureStatus::COMPLETE);
        $fixture->setWinnerEntrant($away);
        $this->em->persist($fixture);

        $this->em->persist(new CompetitionResult(
            $fixture,
            0,
            3,
            MatchEngineIdentifier::DETERMINISTIC,
            $home->getClubDisplayJson(),
            $away->getClubDisplayJson(),
            [],
            [],
        ));

        $this->em->flush();
        $this->em->clear();
    }

    private function template(string $name): CompetitionTemplate
    {
        $template = new CompetitionTemplate($name, 'cup-telemetry-' . uniqid('', true), 4, CompetitionDuration::TEN_HOURS);
        $template->setVictorPrize(500000);
        $template->setTrophy([
            'style'           => 'cup',
            'finish'          => 'gold',
            'ribbons'         => true,
            'ribbonPrimary'   => '#112233',
            'ribbonSecondary' => '#445566',
        ]);
        $this->em->persist($template);

        return $template;
    }

    private function entrant(ActiveCompetition $competition, string $name, int $seed): CompetitionEntrant
    {
        $user = new User(sprintf('cup-telemetry-%s@example.com', uniqid('', true)));
        $user->setPassword('x');
        $club = new Club($name, $user);
        $this->em->persist($user);
        $this->em->persist($club);

        $players = [];
        for ($i = 0; $i < 11; $i++) {
            $players[] = ['id' => "$name-p$i", 'name' => "$name Player $i", 'position' => 'MID', 'currentAbility' => 60];
        }

        return $this->makeEntrantFromEnvelope($this->em, $competition, $club, $seed, [
            'club'    => ['id' => (string) $club->getId(), 'name' => $name, 'abbreviation' => strtoupper(substr($name, 0, 3))],
            'players' => $players,
            'staff'   => [['id' => 's1', 'role' => 'MANAGER', 'name' => "$name Boss"]],
        ]);
    }
}
