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
use App\Message\SendPushNotificationMessage;
use App\Repository\Competition\CompetitionFixtureRepository;
use App\Repository\Competition\CompetitionRoundRepository;
use App\Service\Competition\CompetitionDrawService;
use App\Service\Competition\CompetitionLockService;
use App\Service\Competition\CompetitionResultsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;

class CompetitionDrawServiceTest extends KernelTestCase
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
                      active_competition, competition_template, entrant_reward_claim CASCADE',
        );
    }

    private function transport(): mixed
    {
        return self::getContainer()->get('messenger.transport.async');
    }

    private function createClub(string $name): Club
    {
        $user = new User('draw-' . uniqid('', true) . '@example.com');
        $user->setPassword('x');
        $user->setRoles([User::ROLE_CLUB]);
        $club = new Club($name, $user);

        $this->em->persist($user);
        $this->em->persist($club);

        return $club;
    }

    private function buildLockedCompetition(int $capacity = 4): ActiveCompetition
    {
        $template = new CompetitionTemplate('Draw Cup', 'draw-cup-' . uniqid('', true), $capacity, CompetitionDuration::TEN_HOURS);
        $this->em->persist($template);

        $instance = new ActiveCompetition($template);
        $this->em->persist($instance);

        for ($i = 1; $i <= $capacity; $i++) {
            $club    = $this->createClub("Club {$i}");
            $entrant = new CompetitionEntrant($instance, $club, 0, [
                'club'    => ['id' => (string) $club->getId(), 'name' => "Club {$i}"],
                'players' => [],
            ]);
            $this->em->persist($entrant);
        }
        $this->em->flush();

        self::getContainer()->get(CompetitionLockService::class)->lock($instance);
        $this->em->flush();

        return $instance;
    }

    public function testRoundOneDrawPairsEntrantsBySeed(): void
    {
        $instance = $this->buildLockedCompetition(capacity: 4);
        $round1   = $this->roundRepository->findByCompetitionOrderedByIndex($instance)[0];

        $this->drawService->forceDrawRound($round1);
        $this->em->refresh($round1);

        $this->assertSame(CompetitionRoundStatus::DRAWN, $round1->getStatus());
        $this->assertNotNull($round1->getStartedAt());
        $this->assertNotNull($round1->getMatchesResolveAt());
        $this->assertGreaterThan($round1->getStartedAt(), $round1->getMatchesResolveAt());

        $fixtures = $this->fixtureRepository->findByRoundOrderedBySlot($round1);
        $this->assertCount(2, $fixtures, '4 entrants -> 2 fixtures.');
        $this->assertSame(1, $fixtures[0]->getHomeEntrant()->getSeed());
        $this->assertSame(2, $fixtures[0]->getAwayEntrant()->getSeed());
        $this->assertSame(3, $fixtures[1]->getHomeEntrant()->getSeed());
        $this->assertSame(4, $fixtures[1]->getAwayEntrant()->getSeed());
    }

    public function testRoundOneDrawFlipsCompetitionToRunning(): void
    {
        $instance = $this->buildLockedCompetition();
        $this->assertSame(ActiveCompetitionStatus::SCHEDULED, $instance->getStatus());

        $round1 = $this->roundRepository->findByCompetitionOrderedByIndex($instance)[0];
        $this->drawService->forceDrawRound($round1);

        $this->em->refresh($instance);
        $this->assertSame(ActiveCompetitionStatus::RUNNING, $instance->getStatus());
    }

    public function testRoundOneDrawClosesTheSnapshotResubmissionWindow(): void
    {
        $instance = $this->buildLockedCompetition();
        $round1   = $this->roundRepository->findByCompetitionOrderedByIndex($instance)[0];

        $this->drawService->forceDrawRound($round1);

        $entrantRepository = self::getContainer()->get(\App\Repository\Competition\CompetitionEntrantRepository::class);
        foreach ($entrantRepository->findByCompetitionOrderedByRegistration($instance) as $entrant) {
            $this->assertSame(CompetitionEntrantStatus::ACTIVE, $entrant->getStatus());
            $this->assertNotNull($entrant->getSnapshotLockedAt());
        }
    }

    public function testRoundOneDrawSendsRoundDrawnPush(): void
    {
        $instance = $this->buildLockedCompetition();
        $round1   = $this->roundRepository->findByCompetitionOrderedByIndex($instance)[0];
        $this->transport()->reset();

        $this->drawService->forceDrawRound($round1);

        $messages = array_values(array_filter(array_map(
            static fn (Envelope $e) => $e->getMessage(),
            $this->transport()->getSent(),
        ), static fn ($m) => $m instanceof SendPushNotificationMessage && ($m->data['type'] ?? null) === 'ROUND_DRAWN'));

        $this->assertCount(1, $messages);
        $this->assertCount(4, $messages[0]->userIds);
    }

    public function testRoundTwoDrawPairsPreviousRoundWinners(): void
    {
        $instance = $this->buildLockedCompetition(capacity: 4);
        $rounds   = $this->roundRepository->findByCompetitionOrderedByIndex($instance);
        [$round1, $round2] = $rounds;

        $this->drawService->forceDrawRound($round1);
        $this->em->refresh($round1);
        foreach ($this->fixtureRepository->findByRoundOrderedBySlot($round1) as $fixture) {
            $this->resultsService->forceResolveFixture($fixture);
        }
        $this->em->refresh($round2);
        // scheduledAt was just written by CompetitionResultsService::finalizeRound() — force it due.
        $this->drawService->forceDrawRound($round2);
        $this->em->refresh($round2);

        $this->assertSame(CompetitionRoundStatus::DRAWN, $round2->getStatus());
        $fixtures = $this->fixtureRepository->findByRoundOrderedBySlot($round2);
        $this->assertCount(1, $fixtures, 'The FINAL pairs round 1\'s 2 winners into 1 fixture.');
        $this->assertNotNull($fixtures[0]->getHomeEntrant());
        $this->assertNotNull($fixtures[0]->getAwayEntrant());
    }

    public function testDrawLockedAtClaimGuardsAnOverlappingTick(): void
    {
        $instance = $this->buildLockedCompetition();
        $round1   = $this->roundRepository->findByCompetitionOrderedByIndex($instance)[0];
        $round1->setScheduledAt(new \DateTimeImmutable('-1 second'));
        // Simulate an overlapping tick already claiming this round.
        $round1->setDrawLockedAt(new \DateTimeImmutable());
        $this->em->flush();

        $drawn = $this->drawService->drawDueRounds(new \DateTimeImmutable());
        $this->assertSame(0, $drawn);

        $this->em->refresh($round1);
        $this->assertSame(CompetitionRoundStatus::DRAW_PENDING, $round1->getStatus(), 'Still draw-pending — the claim was rejected, not the draw itself skipped.');
    }

    public function testForceDrawRoundRejectsAnAlreadyDrawnRound(): void
    {
        $instance = $this->buildLockedCompetition();
        $round1   = $this->roundRepository->findByCompetitionOrderedByIndex($instance)[0];
        $this->drawService->forceDrawRound($round1);

        $this->expectException(\RuntimeException::class);
        $this->drawService->forceDrawRound($round1);
    }
}
