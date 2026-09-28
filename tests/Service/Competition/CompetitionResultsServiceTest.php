<?php

declare(strict_types=1);

namespace App\Tests\Service\Competition;

use App\Entity\Club;
use App\Entity\Competition\ActiveCompetition;
use App\Entity\Competition\CompetitionEntrant;
use App\Entity\Competition\CompetitionTemplate;
use App\Entity\InboxMessage;
use App\Entity\User;
use App\Enum\Competition\ActiveCompetitionStatus;
use App\Enum\Competition\CompetitionDuration;
use App\Enum\Competition\CompetitionEntrantStatus;
use App\Enum\Competition\CompetitionFixtureStatus;
use App\Enum\Competition\CompetitionRoundStatus;
use App\Message\SendPushNotificationMessage;
use App\Repository\Competition\CompetitionFixtureRepository;
use App\Repository\Competition\CompetitionRoundRepository;
use App\Service\Competition\CompetitionDrawService;
use App\Service\Competition\CompetitionLockService;
use App\Service\Competition\CompetitionResultsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;

class CompetitionResultsServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CompetitionDrawService $drawService;
    private CompetitionResultsService $resultsService;
    private CompetitionRoundRepository $roundRepository;
    private CompetitionFixtureRepository $fixtureRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em                = self::getContainer()->get(EntityManagerInterface::class);
        $this->drawService       = self::getContainer()->get(CompetitionDrawService::class);
        $this->resultsService    = self::getContainer()->get(CompetitionResultsService::class);
        $this->roundRepository   = self::getContainer()->get(CompetitionRoundRepository::class);
        $this->fixtureRepository = self::getContainer()->get(CompetitionFixtureRepository::class);

        $this->em->getConnection()->executeStatement(
            'TRUNCATE competition_entrant, competition_fixture, competition_result, competition_round,
                      active_competition, competition_template, entrant_reward_claim, inbox_message CASCADE',
        );
    }

    private function transport(): mixed
    {
        return self::getContainer()->get('messenger.transport.async');
    }

    private function createClub(string $name): Club
    {
        $user = new User('results-' . uniqid('', true) . '@example.com');
        $user->setPassword('x');
        $user->setRoles([User::ROLE_CLUB]);
        $club = new Club($name, $user);

        $this->em->persist($user);
        $this->em->persist($club);

        return $club;
    }

    private function buildDrawnCompetition(int $capacity = 4, int $victorPrize = 100_000): ActiveCompetition
    {
        $template = new CompetitionTemplate('Results Cup', 'results-cup-' . uniqid('', true), $capacity, CompetitionDuration::TEN_HOURS);
        $template->setVictorPrize($victorPrize);
        $this->em->persist($template);

        $instance = new ActiveCompetition($template);
        $this->em->persist($instance);

        for ($i = 1; $i <= $capacity; $i++) {
            $club    = $this->createClub("Club {$i}");
            $entrant = new CompetitionEntrant($instance, $club, 0, [
                'club'    => ['id' => (string) $club->getId(), 'name' => "Club {$i}"],
                'players' => [['id' => 'p1', 'position' => 'MID', 'currentAbility' => 10]],
            ]);
            $this->em->persist($entrant);
        }
        $this->em->flush();

        self::getContainer()->get(CompetitionLockService::class)->lock($instance);
        $this->em->flush();

        $round1 = $this->roundRepository->findByCompetitionOrderedByIndex($instance)[0];
        $this->drawService->forceDrawRound($round1);

        return $instance;
    }

    public function testPublishDueResultsIsNotDueBeforeMatchesResolveAt(): void
    {
        $instance = $this->buildDrawnCompetition(capacity: 4);
        $round1   = $this->roundRepository->findByCompetitionOrderedByIndex($instance)[0];

        // The reveal window hasn't elapsed yet — matchesResolveAt is hours in the future.
        $published = $this->resultsService->publishDueResults(new \DateTimeImmutable());
        $this->assertSame(0, $published);

        $this->em->refresh($round1);
        $this->assertSame(CompetitionRoundStatus::DRAWN, $round1->getStatus());
    }

    public function testPublishingResultsSendsMatchResultPushToBothSides(): void
    {
        $instance = $this->buildDrawnCompetition(capacity: 4);
        $round1   = $this->roundRepository->findByCompetitionOrderedByIndex($instance)[0];
        $round1->setMatchesResolveAt(new \DateTimeImmutable('-1 second'));
        $this->em->flush();
        $this->transport()->reset();

        $published = $this->resultsService->publishDueResults(new \DateTimeImmutable());
        $this->assertSame(1, $published);

        $matchResultMessages = array_filter(array_map(
            static fn (Envelope $e) => $e->getMessage(),
            $this->transport()->getSent(),
        ), static fn ($m) => $m instanceof SendPushNotificationMessage && ($m->data['type'] ?? null) === 'MATCH_RESULT');

        // 2 fixtures x 2 sides = 4 pushes.
        $this->assertCount(4, $matchResultMessages);
    }

    public function testNonFinalRoundPublishSchedulesNextRoundDrawWithoutCreatingItsFixtures(): void
    {
        $instance = $this->buildDrawnCompetition(capacity: 4);
        $rounds   = $this->roundRepository->findByCompetitionOrderedByIndex($instance);
        [$round1, $round2] = $rounds;

        foreach ($this->fixtureRepository->findByRoundOrderedBySlot($round1) as $fixture) {
            $this->resultsService->forceResolveFixture($fixture);
        }

        $this->em->refresh($round1);
        $this->em->refresh($round2);

        $this->assertSame(CompetitionRoundStatus::RESULTS_PUBLISHED, $round1->getStatus());
        $this->assertNotNull($round1->getCompletedAt());

        $this->assertSame(CompetitionRoundStatus::DRAW_PENDING, $round2->getStatus(), 'Drawing round 2 is CompetitionDrawService\'s job, on a later tick.');
        $this->assertGreaterThan($round1->getCompletedAt(), $round2->getScheduledAt());
        $this->assertCount(0, $this->fixtureRepository->findByRoundOrderedBySlot($round2));
    }

    public function testFinalRoundPublishCompletesTheCompetitionAndDeliversThePrize(): void
    {
        $instance = $this->buildDrawnCompetition(capacity: 4, victorPrize: 250_000);
        $round1   = $this->roundRepository->findByCompetitionOrderedByIndex($instance)[0];

        $winners = [];
        foreach ($this->fixtureRepository->findByRoundOrderedBySlot($round1) as $fixture) {
            $result    = $this->resultsService->forceResolveFixture($fixture);
            $fixture   = $this->fixtureRepository->findByRoundOrderedBySlot($round1)[$fixture->getSlotIndex()];
            $winners[] = $fixture->getWinnerEntrant();
        }

        $round2 = $this->roundRepository->findByCompetitionOrderedByIndex($instance)[1];
        $this->em->refresh($round2);
        $this->drawService->forceDrawRound($round2);

        $this->transport()->reset();
        foreach ($this->fixtureRepository->findByRoundOrderedBySlot($round2) as $fixture) {
            $this->resultsService->forceResolveFixture($fixture);
        }

        $this->em->refresh($instance);
        $this->em->refresh($round2);

        $this->assertSame(CompetitionRoundStatus::RESULTS_PUBLISHED, $round2->getStatus());
        $this->assertSame(ActiveCompetitionStatus::COMPLETED, $instance->getStatus());
        $this->assertNotNull($instance->getCompletedAt());

        $championMessages = array_filter(array_map(
            static fn (Envelope $e) => $e->getMessage(),
            $this->transport()->getSent(),
        ), static fn ($m) => $m instanceof SendPushNotificationMessage && ($m->data['type'] ?? null) === 'COMPETITION_COMPLETED' && ($m->data['result'] ?? null) === 'WON');
        $this->assertCount(1, $championMessages);

        $winnerEntrant = $this->fixtureRepository->findByRoundOrderedBySlot($round2)[0]->getWinnerEntrant();
        $messages = $this->em->getRepository(InboxMessage::class)->findBy(['club' => $winnerEntrant->getClub()]);
        $this->assertCount(1, $messages, 'The victor prize should be delivered as an inbox offer.');
        $this->assertSame(250_000, $messages[0]->getOfferData()['effects'][0]['amountPence']);
    }

    public function testResolveLockedAtClaimGuardsAnOverlappingTick(): void
    {
        $instance = $this->buildDrawnCompetition();
        $round1   = $this->roundRepository->findByCompetitionOrderedByIndex($instance)[0];
        $round1->setMatchesResolveAt(new \DateTimeImmutable('-1 second'));
        $round1->setResolveLockedAt(new \DateTimeImmutable());
        $this->em->flush();

        $published = $this->resultsService->publishDueResults(new \DateTimeImmutable());
        $this->assertSame(0, $published);

        $this->em->refresh($round1);
        $this->assertSame(CompetitionRoundStatus::DRAWN, $round1->getStatus(), 'Still drawn — the claim was rejected, not the publish itself skipped.');
    }

    public function testForceResolveFixtureRejectsAnAlreadyResolvedFixture(): void
    {
        $instance = $this->buildDrawnCompetition();
        $round1   = $this->roundRepository->findByCompetitionOrderedByIndex($instance)[0];
        $fixture  = $this->fixtureRepository->findByRoundOrderedBySlot($round1)[0];

        $this->resultsService->forceResolveFixture($fixture);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('This fixture has already been resolved.');
        $this->resultsService->forceResolveFixture($fixture);
    }

    public function testForceResolveFixtureRejectsAFixtureWithNoOpposingEntrant(): void
    {
        $instance = $this->buildDrawnCompetition();
        $round1   = $this->roundRepository->findByCompetitionOrderedByIndex($instance)[0];
        $fixture  = $this->fixtureRepository->findByRoundOrderedBySlot($round1)[0];
        $fixture->setAwayEntrant(null);
        $this->em->flush();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('nothing to simulate');
        $this->resultsService->forceResolveFixture($fixture);
    }

    public function testForceResolveFixtureRejectsADrawPendingRound(): void
    {
        $template = new CompetitionTemplate('Results Cup', 'results-cup-' . uniqid('', true), 4, CompetitionDuration::TEN_HOURS);
        $this->em->persist($template);
        $instance = new ActiveCompetition($template);
        $this->em->persist($instance);
        for ($i = 1; $i <= 4; $i++) {
            $club    = $this->createClub("Club {$i}");
            $entrant = new CompetitionEntrant($instance, $club, 0, ['club' => ['id' => (string) $club->getId(), 'name' => "Club {$i}"], 'players' => []]);
            $this->em->persist($entrant);
        }
        $this->em->flush();
        self::getContainer()->get(CompetitionLockService::class)->lock($instance);
        $this->em->flush();

        $round1  = $this->roundRepository->findByCompetitionOrderedByIndex($instance)[0];
        $fixture = new \App\Entity\Competition\CompetitionFixture($round1, 0, null, null);
        // A DRAW_PENDING round has no fixtures in practice — construct one directly to
        // exercise the guard rather than relying on an unreachable natural state.
        $this->em->persist($fixture);
        $entrants = self::getContainer()->get(\App\Repository\Competition\CompetitionEntrantRepository::class)
            ->findByCompetitionOrderedByRegistration($instance);
        $fixture->setHomeEntrant($entrants[0]);
        $fixture->setAwayEntrant($entrants[1]);
        $this->em->flush();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('This round is no longer processable.');
        $this->resultsService->forceResolveFixture($fixture);
    }

    /**
     * A knockout fixture must never advance a bracket while looking like a draw — a tied
     * fixture must be settled by extra time and/or a penalty shootout, never silently.
     * Equal ability (10 vs 10 for every entrant) makes level scores common, so looping over
     * independent competitions reliably surfaces both outcomes without mocking the engine —
     * same idiom as the pre-split CompetitionRoundProcessorServiceTest this replaces.
     */
    public function testATiedFixtureIsResolvedByExtraTimeAndOrPenaltiesNotLeftAsADraw(): void
    {
        $foundExtraTime = false;
        $foundPenalties = false;

        for ($i = 0; $i < 60 && !($foundExtraTime && $foundPenalties); $i++) {
            $instance = $this->buildDrawnCompetition(capacity: 4);
            $round1   = $this->roundRepository->findByCompetitionOrderedByIndex($instance)[0];
            $fixtures = $this->fixtureRepository->findByRoundOrderedBySlot($round1);

            foreach ($fixtures as $fixture) {
                $result = $this->resultsService->forceResolveFixture($fixture);
                $this->em->refresh($fixture);

                $this->assertSame(CompetitionFixtureStatus::COMPLETE, $fixture->getStatus());
                $this->assertNotNull($fixture->getWinnerEntrant(), 'Every fixture must produce a decisive winner, even when the scoreline stays level.');

                if ($result->isWentToExtraTime()) {
                    $foundExtraTime = true;
                }

                if ($result->isWentToPenalties()) {
                    $foundPenalties = true;
                    $this->assertTrue($result->isWentToExtraTime(), 'Penalties can only follow extra time.');
                    $this->assertSame($result->getHomeScore(), $result->getAwayScore(), 'The recorded score must stay the true (level) AET score even when penalties decide the tie.');
                    $this->assertNotNull($result->getPenaltyHomeScore());
                    $this->assertNotNull($result->getPenaltyAwayScore());
                    $this->assertNotSame($result->getPenaltyHomeScore(), $result->getPenaltyAwayScore(), 'A penalty shootout must always produce a decisive tally.');
                }
            }
        }

        $this->assertTrue($foundExtraTime, 'Expected at least one fixture to go to extra time across 60 competitions.');
        $this->assertTrue($foundPenalties, 'Expected at least one fixture to go to penalties across 60 competitions.');
    }
}
