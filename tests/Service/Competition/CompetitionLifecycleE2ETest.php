<?php

declare(strict_types=1);

namespace App\Tests\Service\Competition;

use App\Entity\Club;
use App\Entity\Competition\ActiveCompetition;
use App\Entity\Competition\CompetitionEntrant;
use App\Entity\Competition\CompetitionTemplate;
use App\Entity\User;
use App\Enum\Competition\ActiveCompetitionStatus;
use App\Enum\Competition\CompetitionDuration;
use App\Enum\Competition\CompetitionEntrantStatus;
use App\Enum\Competition\CompetitionRoundStatus;
use App\Repository\Competition\CompetitionFixtureRepository;
use App\Repository\Competition\CompetitionRoundRepository;
use App\Service\Competition\CompetitionDrawService;
use App\Service\Competition\CompetitionLockService;
use App\Service\Competition\CompetitionResultsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Drives a capacity-4 competition (the shortest bracket — 2 rounds, fastest path to
 * COMPLETED) through every phase of the decoupled lifecycle end to end, asserting every
 * status/timestamp transition in order — this is the test that most directly validates
 * the state-transition table the whole refactor is built around: lock -> draw round 1 ->
 * publish round 1's results -> intermission -> draw the FINAL -> publish its results ->
 * tournament COMPLETED.
 */
class CompetitionLifecycleE2ETest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CompetitionLockService $lockService;
    private CompetitionDrawService $drawService;
    private CompetitionResultsService $resultsService;
    private CompetitionRoundRepository $roundRepository;
    private CompetitionFixtureRepository $fixtureRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em                = self::getContainer()->get(EntityManagerInterface::class);
        $this->lockService       = self::getContainer()->get(CompetitionLockService::class);
        $this->drawService       = self::getContainer()->get(CompetitionDrawService::class);
        $this->resultsService    = self::getContainer()->get(CompetitionResultsService::class);
        $this->roundRepository   = self::getContainer()->get(CompetitionRoundRepository::class);
        $this->fixtureRepository = self::getContainer()->get(CompetitionFixtureRepository::class);

        $this->em->getConnection()->executeStatement(
            'TRUNCATE competition_entrant, competition_fixture, competition_result, competition_round,
                      active_competition, competition_template, entrant_reward_claim, inbox_message CASCADE',
        );
    }

    private function createClub(string $name): Club
    {
        $user = new User('e2e-' . uniqid('', true) . '@example.com');
        $user->setPassword('x');
        $user->setRoles([User::ROLE_CLUB]);
        $club = new Club($name, $user);

        $this->em->persist($user);
        $this->em->persist($club);

        return $club;
    }

    public function testFullLifecycleFromLockToCompleted(): void
    {
        $template = new CompetitionTemplate('E2E Cup', 'e2e-cup-' . uniqid('', true), 4, CompetitionDuration::TEN_HOURS);
        $template->setVictorPrize(500_000);
        $this->em->persist($template);

        $instance = new ActiveCompetition($template);
        $this->em->persist($instance);

        for ($i = 1; $i <= 4; $i++) {
            $club    = $this->createClub("Club {$i}");
            $entrant = new CompetitionEntrant($instance, $club, 0, [
                'club'    => ['id' => (string) $club->getId(), 'name' => "Club {$i}"],
                'players' => [],
            ]);
            $this->em->persist($entrant);
        }
        $this->em->flush();

        // --- Registration -> Locked -------------------------------------------------
        $this->lockService->lock($instance);
        $this->em->flush();

        $this->assertSame(ActiveCompetitionStatus::SCHEDULED, $instance->getStatus());
        [$round1, $round2] = $this->roundRepository->findByCompetitionOrderedByIndex($instance);
        $this->assertSame(CompetitionRoundStatus::DRAW_PENDING, $round1->getStatus());
        $this->assertSame(CompetitionRoundStatus::DRAW_PENDING, $round2->getStatus());
        $this->assertCount(0, $this->fixtureRepository->findByRoundOrderedBySlot($round1));

        // --- Draw round 1 -------------------------------------------------------------
        $round1->setScheduledAt(new \DateTimeImmutable('-1 second'));
        $this->em->flush();
        $this->assertSame(0, $this->drawService->drawDueRounds(new \DateTimeImmutable('-1 minute')), 'Not due yet at an earlier instant.');
        $this->assertSame(1, $this->drawService->drawDueRounds(new \DateTimeImmutable()));

        $this->em->refresh($round1);
        $this->assertSame(CompetitionRoundStatus::DRAWN, $round1->getStatus());
        $this->assertNotNull($round1->getStartedAt());
        $this->assertNotNull($round1->getMatchesResolveAt());
        $this->assertGreaterThan($round1->getStartedAt(), $round1->getMatchesResolveAt());
        $this->assertCount(2, $this->fixtureRepository->findByRoundOrderedBySlot($round1));

        $this->em->refresh($instance);
        $this->assertSame(ActiveCompetitionStatus::RUNNING, $instance->getStatus());

        $entrantRepository = self::getContainer()->get(\App\Repository\Competition\CompetitionEntrantRepository::class);
        foreach ($entrantRepository->findByCompetitionOrderedByRegistration($instance) as $entrant) {
            $this->assertSame(CompetitionEntrantStatus::ACTIVE, $entrant->getStatus());
            $this->assertNotNull($entrant->getSnapshotLockedAt());
        }

        // --- Publish round 1's results -> intermission --------------------------------
        $this->assertSame(0, $this->resultsService->publishDueResults(new \DateTimeImmutable()), 'matchesResolveAt has not arrived yet.');

        $round1->setMatchesResolveAt(new \DateTimeImmutable('-1 second'));
        $this->em->flush();
        $this->assertSame(1, $this->resultsService->publishDueResults(new \DateTimeImmutable()));

        $this->em->refresh($round1);
        $this->em->refresh($round2);
        $this->assertSame(CompetitionRoundStatus::RESULTS_PUBLISHED, $round1->getStatus());
        $this->assertNotNull($round1->getCompletedAt());
        $this->assertSame(CompetitionRoundStatus::DRAW_PENDING, $round2->getStatus(), 'Drawing the FINAL is a later, separate tick.');
        $this->assertGreaterThan($round1->getCompletedAt(), $round2->getScheduledAt(), 'The FINAL\'s draw is scheduled into the intermission after round 1\'s results.');
        $this->assertCount(0, $this->fixtureRepository->findByRoundOrderedBySlot($round2));

        foreach ($this->fixtureRepository->findByRoundOrderedBySlot($round1) as $fixture) {
            $this->assertNotNull($fixture->getWinnerEntrant());
        }

        // --- Intermission: the FINAL is not due to draw yet ----------------------------
        $this->assertSame(0, $this->drawService->drawDueRounds(new \DateTimeImmutable()), 'Still in the intermission window.');

        // --- Draw the FINAL -------------------------------------------------------------
        $round2->setScheduledAt(new \DateTimeImmutable('-1 second'));
        $this->em->flush();
        $this->assertSame(1, $this->drawService->drawDueRounds(new \DateTimeImmutable()));

        $this->em->refresh($round2);
        $this->assertSame(CompetitionRoundStatus::DRAWN, $round2->getStatus());
        $finalFixtures = $this->fixtureRepository->findByRoundOrderedBySlot($round2);
        $this->assertCount(1, $finalFixtures, 'Round 1\'s 2 winners pair into 1 FINAL fixture.');
        $this->assertNotNull($finalFixtures[0]->getHomeEntrant());
        $this->assertNotNull($finalFixtures[0]->getAwayEntrant());

        // --- Publish the FINAL's results -> tournament COMPLETED ------------------------
        $round2->setMatchesResolveAt(new \DateTimeImmutable('-1 second'));
        $this->em->flush();
        $this->assertSame(1, $this->resultsService->publishDueResults(new \DateTimeImmutable()));

        $this->em->refresh($round2);
        $this->em->refresh($instance);
        $this->assertSame(CompetitionRoundStatus::RESULTS_PUBLISHED, $round2->getStatus());
        $this->assertNotNull($round2->getCompletedAt());
        $this->assertSame(ActiveCompetitionStatus::COMPLETED, $instance->getStatus());
        $this->assertNotNull($instance->getCompletedAt());

        $champion = $this->fixtureRepository->findByRoundOrderedBySlot($round2)[0]->getWinnerEntrant();
        $this->assertSame(CompetitionEntrantStatus::WINNER, $champion->getStatus());

        $messages = $this->em->getRepository(\App\Entity\InboxMessage::class)->findBy(['club' => $champion->getClub()]);
        $this->assertCount(1, $messages);
        $this->assertSame(500_000, $messages[0]->getOfferData()['effects'][0]['amountPence']);
    }
}
