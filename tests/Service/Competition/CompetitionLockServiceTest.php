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
use App\Enum\Competition\CompetitionRoundStatus;
use App\Message\SendPushNotificationMessage;
use App\Repository\Competition\CompetitionFixtureRepository;
use App\Repository\Competition\CompetitionRoundRepository;
use App\Service\Competition\CompetitionLockService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;

class CompetitionLockServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CompetitionLockService $lockService;
    private CompetitionRoundRepository $roundRepository;
    private CompetitionFixtureRepository $fixtureRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em                = self::getContainer()->get(EntityManagerInterface::class);
        $this->lockService       = self::getContainer()->get(CompetitionLockService::class);
        $this->roundRepository   = self::getContainer()->get(CompetitionRoundRepository::class);
        $this->fixtureRepository = self::getContainer()->get(CompetitionFixtureRepository::class);

        $this->em->getConnection()->executeStatement(
            'TRUNCATE competition_entrant, competition_fixture, competition_round,
                      active_competition, competition_template CASCADE',
        );
    }

    private function transport(): mixed
    {
        return self::getContainer()->get('messenger.transport.async');
    }

    private function createClub(string $name): Club
    {
        $user = new User('lock-' . uniqid('', true) . '@example.com');
        $user->setPassword('x');
        $user->setRoles([User::ROLE_CLUB]);
        $club = new Club($name, $user);

        $this->em->persist($user);
        $this->em->persist($club);

        return $club;
    }

    /** @return array{0: ActiveCompetition, 1: list<CompetitionEntrant>} */
    private function buildFullCompetition(int $capacity = 4, CompetitionDuration $duration = CompetitionDuration::TEN_HOURS): array
    {
        $template = new CompetitionTemplate('Lock Cup', 'lock-cup-' . uniqid('', true), $capacity, $duration);
        $this->em->persist($template);

        $instance = new ActiveCompetition($template);
        $this->em->persist($instance);

        $entrants = [];
        for ($i = 1; $i <= $capacity; $i++) {
            $club      = $this->createClub("Club {$i}");
            $entrant   = new CompetitionEntrant($instance, $club, 0, [
                'club'    => ['id' => (string) $club->getId(), 'name' => "Club {$i}"],
                'players' => [],
            ]);
            $this->em->persist($entrant);
            $entrants[] = $entrant;
        }
        $this->em->flush();

        return [$instance, $entrants];
    }

    public function testLockingCreatesEveryRoundDrawPendingWithNoFixtures(): void
    {
        [$instance] = $this->buildFullCompetition(capacity: 8);

        $this->lockService->lock($instance);
        $this->em->flush();

        $rounds = $this->roundRepository->findByCompetitionOrderedByIndex($instance);
        $this->assertCount(3, $rounds, 'Capacity 8 -> [QF, SF, FINAL].');

        foreach ($rounds as $round) {
            $this->assertSame(CompetitionRoundStatus::DRAW_PENDING, $round->getStatus());
            $this->assertNull($round->getStartedAt());
            $this->assertNull($round->getMatchesResolveAt());
            $this->assertNull($round->getCompletedAt());
            $this->assertCount(0, $this->fixtureRepository->findByRoundOrderedBySlot($round));
        }
    }

    public function testRoundOneScheduledAtIsNowPlusLeadTimeNotImmediate(): void
    {
        [$instance] = $this->buildFullCompetition(capacity: 4, duration: CompetitionDuration::ONE_DAY);

        $before = new \DateTimeImmutable();
        $this->lockService->lock($instance);
        $this->em->flush();
        $after = new \DateTimeImmutable();

        $round1 = $this->roundRepository->findByCompetitionOrderedByIndex($instance)[0];

        // Capacity 4 -> 2 rounds -> interval = 86400/2 = 43200s -> lead time = round(43200*0.3) = 12960s.
        $this->assertGreaterThan($before->modify('+12000 seconds'), $round1->getScheduledAt());
        $this->assertLessThan($after->modify('+13960 seconds'), $round1->getScheduledAt());
        $this->assertGreaterThan($before, $round1->getScheduledAt(), 'Round 1 must not draw immediately at lock time.');
    }

    public function testActiveCompetitionTransitionsToScheduledNotRunning(): void
    {
        [$instance] = $this->buildFullCompetition();

        $this->lockService->lock($instance);
        $this->em->flush();

        $this->assertSame(ActiveCompetitionStatus::SCHEDULED, $instance->getStatus());
        $this->assertNotNull($instance->getLockedAt());
        $this->assertNotNull($instance->getStartsAt());
        $this->assertNotNull($instance->getEndsAt());
    }

    public function testNoRoundDrawnPushIsSentAtLockTime(): void
    {
        [$instance] = $this->buildFullCompetition();
        $this->transport()->reset();

        $this->lockService->lock($instance);
        $this->em->flush();

        $roundDrawnMessages = array_filter(array_map(
            static fn (Envelope $e) => $e->getMessage(),
            $this->transport()->getSent(),
        ), static fn ($m) => $m instanceof SendPushNotificationMessage && ($m->data['type'] ?? null) === 'ROUND_DRAWN');

        $this->assertCount(0, $roundDrawnMessages, 'Drawing is exclusively CompetitionDrawService\'s job, on a later tick.');
    }
}
