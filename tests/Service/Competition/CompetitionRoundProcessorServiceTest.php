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
use App\Repository\Competition\CompetitionRoundRepository;
use App\Service\Competition\CompetitionLockService;
use App\Service\Competition\CompetitionRoundProcessorService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class CompetitionRoundProcessorServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CompetitionRoundProcessorService $processor;
    private CompetitionRoundRepository $roundRepository;
    private CompetitionLockService $lockService;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em              = self::getContainer()->get(EntityManagerInterface::class);
        $this->processor        = self::getContainer()->get(CompetitionRoundProcessorService::class);
        $this->roundRepository    = self::getContainer()->get(CompetitionRoundRepository::class);
        $this->lockService         = self::getContainer()->get(CompetitionLockService::class);

        $this->em->getConnection()->executeStatement(
            'TRUNCATE competition_entrant, competition_fixture, competition_result,
                      competition_round, active_competition, competition_template CASCADE',
        );
    }

    private function createClub(string $name): Club
    {
        $user = new User('proc-' . uniqid('', true) . '@example.com');
        $user->setPassword('x');
        $user->setRoles([User::ROLE_CLUB]);
        $club = new Club($name, $user);

        $this->em->persist($user);
        $this->em->persist($club);

        return $club;
    }

    /** Builds a locked, 4-entrant ActiveCompetition (round 1 = SF, round 2 = FINAL). */
    private function buildLockedCompetition(): ActiveCompetition
    {
        $template = new CompetitionTemplate('Proc Cup', 'proc-cup-' . uniqid('', true), 4, CompetitionDuration::TEN_HOURS);
        $this->em->persist($template);

        $instance = new ActiveCompetition($template);
        $this->em->persist($instance);

        foreach (['Alpha', 'Bravo', 'Charlie', 'Delta'] as $name) {
            $club    = $this->createClub($name);
            $entrant = new CompetitionEntrant($instance, $club, 0, [
                'club'    => ['id' => (string) $club->getId(), 'name' => $name],
                'players' => [['id' => 'p1', 'position' => 'MID', 'currentAbility' => 10]],
            ]);
            $this->em->persist($entrant);
        }
        $this->em->flush();

        $this->lockService->lock($instance);
        $this->em->flush();

        return $instance;
    }

    private function backdateRound(ActiveCompetition $instance, int $roundIndex): void
    {
        $round = $this->roundRepository->findByCompetitionOrderedByIndex($instance)[$roundIndex - 1];
        $round->setScheduledAt(new \DateTimeImmutable('-1 minute'));
        $this->em->flush();
    }

    public function testDueRoundIsProcessedAndAdvancesWinners(): void
    {
        $instance = $this->buildLockedCompetition();
        $this->backdateRound($instance, 1);

        $processed = $this->processor->processDueRounds(new \DateTimeImmutable());
        $this->assertSame(1, $processed);

        $rounds = $this->roundRepository->findByCompetitionOrderedByIndex($instance);
        $this->assertSame(CompetitionRoundStatus::COMPLETED, $rounds[0]->getStatus());
        $this->assertNotNull($rounds[0]->getCompletedAt());

        // Round 1 (SF) had 2 fixtures -> both resolved, winners advanced into round 2 (FINAL).
        $this->em->refresh($instance);
        $entrants = $this->em->getRepository(CompetitionEntrant::class)->findBy(['activeCompetition' => $instance]);
        $active     = array_filter($entrants, fn ($e) => $e->getStatus() === CompetitionEntrantStatus::ACTIVE);
        $eliminated  = array_filter($entrants, fn ($e) => $e->getStatus() === CompetitionEntrantStatus::ELIMINATED);
        $this->assertCount(2, $active, 'Two winners should remain ACTIVE.');
        $this->assertCount(2, $eliminated, 'Two losers should be ELIMINATED.');
        foreach ($eliminated as $loser) {
            $this->assertSame($rounds[0]->getId(), $loser->getEliminatedInRound()?->getId());
        }

        $finalFixtures = $this->em->getRepository(\App\Entity\Competition\CompetitionFixture::class)
            ->findBy(['round' => $rounds[1]]);
        $this->assertCount(1, $finalFixtures, 'The FINAL round should have exactly one fixture, seeded from the two SF winners.');
        $this->assertNotNull($finalFixtures[0]->getHomeEntrant());
        $this->assertNotNull($finalFixtures[0]->getAwayEntrant());

        $results = $this->em->getRepository(\App\Entity\Competition\CompetitionResult::class)->findAll();
        $this->assertCount(2, $results, 'One CompetitionResult per SF fixture.');
    }

    public function testFinalRoundCompletesTheInstanceAndCrownsAWinner(): void
    {
        $instance = $this->buildLockedCompetition();
        $this->backdateRound($instance, 1);
        $this->processor->processDueRounds(new \DateTimeImmutable());

        $this->backdateRound($instance, 2);
        $processed = $this->processor->processDueRounds(new \DateTimeImmutable());
        $this->assertSame(1, $processed);

        $this->em->refresh($instance);
        $this->assertSame(ActiveCompetitionStatus::COMPLETED, $instance->getStatus());
        $this->assertNotNull($instance->getCompletedAt());

        $entrants = $this->em->getRepository(CompetitionEntrant::class)->findBy(['activeCompetition' => $instance]);
        $winners    = array_filter($entrants, fn ($e) => $e->getStatus() === CompetitionEntrantStatus::WINNER);
        $this->assertCount(1, $winners, 'Exactly one entrant should be crowned WINNER.');
    }

    public function testAlreadyClaimedRoundIsNotReprocessed(): void
    {
        $instance = $this->buildLockedCompetition();
        $this->backdateRound($instance, 1);

        // Simulate a concurrent tick already having claimed this round: status stays due
        // (SCHEDULED) is not how the claim marks it — the claim itself sets status=RUNNING and
        // locked_for_processing_at, so simulate that directly to prove the guard, not just the
        // natural status transition.
        $round = $this->roundRepository->findByCompetitionOrderedByIndex($instance)[0];
        $this->em->getConnection()->executeStatement(
            "UPDATE competition_round SET locked_for_processing_at = NOW() WHERE id = :id",
            ['id' => $round->getId()->toRfc4122()],
        );

        // findDueRounds only selects PENDING/SCHEDULED rounds, and the claim's own SQL guard
        // additionally requires locked_for_processing_at IS NULL — belt and braces.
        $processed = $this->processor->processDueRounds(new \DateTimeImmutable());
        $this->assertSame(0, $processed, 'A round with locked_for_processing_at already set must not be reprocessed.');
    }
}
