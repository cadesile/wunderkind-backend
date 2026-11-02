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
use App\Service\AccountDeletionService;
use App\Service\Competition\CompetitionResponseBuilder;
use App\Tests\Support\EntrantFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A finished bracket must keep naming both clubs after one of them is deleted.
 *
 * The bug: `competition_entrant.club_id` cascaded on club deletion and
 * `competition_fixture`'s three entrant columns are ON DELETE SET NULL, so deleting a club
 * blanked it out of every bracket it had ever played in. Completed ones included — a cup the
 * player had won came back showing their own club alone against "To be decided", with the
 * scorelines gone too, because the client only renders a score on a side it can resolve.
 *
 * And it was routine rather than rare: competitions are filled with spoof clubs by
 * CompetitionAutoFillService, and `app:clubs:purge-spoof` ran on EVERY deploy in both
 * workflows until 2026-11-02, so each deploy emptied out the finished cups. That deploy step
 * is gone, but a club can still be deleted at any time — hence both fixes below.
 *
 * Two independent fixes, one test each:
 *
 * 1. The entry now outlives its club (ON DELETE SET NULL), so nothing is lost in the first
 *    place — the frozen `clubDisplayJson` goes on rendering the side.
 * 2. For brackets already damaged, whose entrant rows are genuinely gone,
 *    CompetitionResponseBuilder falls back to `competition_result`'s frozen club copy —
 *    which survived, because that table cascades from the FIXTURE, not the club.
 */
class BracketSurvivesClubDeletionTest extends KernelTestCase
{
    use EntrantFixtureTrait;

    private EntityManagerInterface $em;
    private CompetitionResponseBuilder $builder;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em      = self::getContainer()->get(EntityManagerInterface::class);
        $this->builder = self::getContainer()->get(CompetitionResponseBuilder::class);
    }

    public function testAnEntryOutlivesItsClubAndKeepsNamingItInTheBracket(): void
    {
        [$competition, $fixture, $winner, $loser] = $this->playedFinal();

        $loserUser = $loser->getClub()?->getUser();
        self::assertNotNull($loserUser);

        self::getContainer()->get(AccountDeletionService::class)->deleteAccount($loserUser);
        $this->em->clear();

        $fixtureDoc = $this->onlyFixture($competition);

        self::assertSame('Winner FC', $fixtureDoc['home']['name']);
        self::assertSame('Loser FC', $fixtureDoc['away']['name'], 'the deleted club must still be named from the frozen display block');
        self::assertNotNull($fixtureDoc['awayEntrantId'], 'the entrant row itself must survive the club');
        self::assertSame(2, $fixtureDoc['score']['home']);
        self::assertSame(1, $fixtureDoc['score']['away']);
        self::assertSame('home', $fixtureDoc['winnerSide']);
    }

    /**
     * The repair path for brackets damaged before the fix above: the entrant rows are gone
     * for good, and only `competition_result` still knows who played.
     */
    public function testAFixtureWhoseEntrantRowsAreGoneIsRebuiltFromTheStoredResult(): void
    {
        [$competition, $fixture] = $this->playedFinal();

        // Exactly what the old cascade left behind: both entrant rows deleted, the fixture
        // still there with its three entrant columns nulled.
        $fixtureId = (string) $fixture->getId();
        $this->em->getConnection()->executeStatement(
            'DELETE FROM competition_entrant WHERE active_competition_id = :id',
            ['id' => $competition->getId()->toRfc4122()],
        );
        $this->em->clear();

        $fixtureDoc = $this->onlyFixture($competition);

        self::assertNull($fixtureDoc['homeEntrantId'], 'precondition: the entrant rows really are gone');
        self::assertNull($fixtureDoc['awayEntrantId']);
        self::assertSame($fixtureId, $fixtureDoc['fixtureId']);

        self::assertSame('Winner FC', $fixtureDoc['home']['name']);
        self::assertSame('Loser FC', $fixtureDoc['away']['name']);
        self::assertNull($fixtureDoc['home']['entrantId'], 'there is no entrant id left to report, and inventing one would be worse');
        self::assertSame(2, $fixtureDoc['score']['home']);

        // winnerEntrantId is unrecoverable; winnerSide is read off the score instead, which
        // is the whole reason it exists.
        self::assertNull($fixtureDoc['winnerEntrantId']);
        self::assertSame('home', $fixtureDoc['winnerSide']);
    }

    /** @return array<string, mixed> */
    private function onlyFixture(ActiveCompetition $competition): array
    {
        $competition = $this->em->getRepository(ActiveCompetition::class)->find($competition->getId());
        self::assertNotNull($competition);

        $document = $this->builder->sharedDocument($competition);
        $fixtures = $document['rounds'][0]['fixtures'];
        self::assertCount(1, $fixtures);

        return $fixtures[0];
    }

    /**
     * A one-round, two-club competition with a played, won final behind it.
     *
     * @return array{ActiveCompetition, CompetitionFixture, CompetitionEntrant, CompetitionEntrant}
     */
    private function playedFinal(): array
    {
        $template = new CompetitionTemplate('Deletion Cup', 'deletion-cup-' . uniqid('', true), 4, CompetitionDuration::TEN_HOURS);
        $this->em->persist($template);

        $competition = new ActiveCompetition($template);
        $competition->setStatus(ActiveCompetitionStatus::COMPLETED);
        $this->em->persist($competition);

        $winner = $this->entrant($competition, 'Winner FC', 1);
        $loser  = $this->entrant($competition, 'Loser FC', 2);

        // Capacity 4 labels its rounds ['SF', 'FINAL'] and the builder walks them in that
        // order, so round index 1 is the SF and lands at `rounds[0]` in the document. The
        // FINAL follows as a `future` placeholder, which is fine — the assertions only look
        // at the one round that was actually played.
        $round = new CompetitionRound($competition, 1, 'SF', new \DateTimeImmutable('-2 hours'));
        $round->setStatus(CompetitionRoundStatus::RESULTS_PUBLISHED);
        $this->em->persist($round);

        $fixture = new CompetitionFixture($round, 0, $winner, $loser);
        $fixture->setStatus(CompetitionFixtureStatus::COMPLETE);
        $fixture->setWinnerEntrant($winner);
        $this->em->persist($fixture);

        // The frozen club copies are written from the entrant's own display block — see
        // CompetitionResultsService::clubJsonOf() — which is what makes the fallback a true
        // like-for-like and not a second, thinner shape.
        $this->em->persist(new CompetitionResult(
            $fixture,
            2,
            1,
            MatchEngineIdentifier::DETERMINISTIC,
            $winner->getClubDisplayJson(),
            $loser->getClubDisplayJson(),
            [],
            [],
        ));

        $this->em->flush();

        return [$competition, $fixture, $winner, $loser];
    }

    private function entrant(ActiveCompetition $competition, string $name, int $seed): CompetitionEntrant
    {
        $user = new User(sprintf('bracket-del-%s@example.com', uniqid('', true)));
        $user->setPassword('x');
        $club = new Club($name, $user);
        $this->em->persist($user);
        $this->em->persist($club);

        $players = [];
        for ($i = 0; $i < 11; $i++) {
            $players[] = ['id' => "$name-p$i", 'name' => "$name Player $i", 'position' => 'MID', 'currentAbility' => 60];
        }

        return $this->makeEntrantFromEnvelope($this->em, $competition, $club, $seed, [
            'club'    => ['id' => (string) $club->getId(), 'name' => $name, 'formation' => '4-4-2'],
            'players' => $players,
            'staff'   => [['id' => 's1', 'role' => 'MANAGER', 'name' => "$name Boss"]],
        ]);
    }
}
