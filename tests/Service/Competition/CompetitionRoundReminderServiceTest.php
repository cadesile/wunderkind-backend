<?php

declare(strict_types=1);

namespace App\Tests\Service\Competition;

use App\Entity\Club;
use App\Entity\Competition\ActiveCompetition;
use App\Entity\Competition\CompetitionEntrant;
use App\Entity\Competition\CompetitionTemplate;
use App\Entity\User;
use App\Enum\Competition\CompetitionDuration;
use App\Message\SendPushNotificationMessage;
use App\Repository\Competition\CompetitionRoundRepository;
use App\Service\Competition\CompetitionLockService;
use App\Service\Competition\CompetitionRoundReminderService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;

class CompetitionRoundReminderServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CompetitionRoundReminderService $reminderService;
    private CompetitionRoundRepository $roundRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em              = self::getContainer()->get(EntityManagerInterface::class);
        $this->reminderService = self::getContainer()->get(CompetitionRoundReminderService::class);
        $this->roundRepository = self::getContainer()->get(CompetitionRoundRepository::class);

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
        $user = new User('reminder-' . uniqid('', true) . '@example.com');
        $user->setPassword('x');
        $user->setRoles([User::ROLE_CLUB]);
        $club = new Club($name, $user);

        $this->em->persist($user);
        $this->em->persist($club);

        return $club;
    }

    /** Builds a locked, 4-entrant ActiveCompetition — round 1 starts ~10 minutes from now. */
    private function buildLockedCompetitionStartingSoon(): ActiveCompetition
    {
        $template = new CompetitionTemplate('Reminder Cup', 'reminder-cup-' . uniqid('', true), 4, CompetitionDuration::TEN_HOURS);
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

        $lockService = self::getContainer()->get(CompetitionLockService::class);
        $lockService->lock($instance);
        $this->em->flush();

        $round1 = $this->roundRepository->findByCompetitionOrderedByIndex($instance)[0];
        $round1->setScheduledAt(new \DateTimeImmutable('+10 minutes'));
        $this->em->flush();

        return $instance;
    }

    public function testSendsAReminderForARoundStartingWithinTheLeadTime(): void
    {
        $instance = $this->buildLockedCompetitionStartingSoon();
        $this->transport()->reset();

        $sent = $this->reminderService->sendDueReminders(new \DateTimeImmutable());
        $this->assertSame(1, $sent);

        $round1  = $this->roundRepository->findByCompetitionOrderedByIndex($instance)[0];
        $this->em->refresh($round1);
        $this->assertNotNull($round1->getReminderSentAt());

        $messages = array_values(array_filter(array_map(
            static fn (Envelope $e) => $e->getMessage(),
            $this->transport()->getSent(),
        ), static fn ($m) => $m instanceof SendPushNotificationMessage && ($m->data['type'] ?? null) === 'ROUND_STARTING_SOON'));

        $this->assertCount(1, $messages);
        $this->assertCount(4, $messages[0]->userIds, 'All 4 round-1 participants should be reminded.');
        $this->assertSame((string) $instance->getId(), $messages[0]->data['competitionId']);
    }

    public function testARoundAlreadyRemindedIsNotRemindedAgain(): void
    {
        $this->buildLockedCompetitionStartingSoon();
        $this->transport()->reset();

        $first = $this->reminderService->sendDueReminders(new \DateTimeImmutable());
        $this->assertSame(1, $first);

        $this->transport()->reset();
        $second = $this->reminderService->sendDueReminders(new \DateTimeImmutable());
        $this->assertSame(0, $second, 'A round already reminded must not be reminded twice.');
    }

    public function testARoundOutsideTheLeadTimeWindowIsNotReminded(): void
    {
        $instance = $this->buildLockedCompetitionStartingSoon();
        $round1   = $this->roundRepository->findByCompetitionOrderedByIndex($instance)[0];
        $round1->setScheduledAt(new \DateTimeImmutable('+2 hours'));
        $this->em->flush();
        $this->transport()->reset();

        $sent = $this->reminderService->sendDueReminders(new \DateTimeImmutable());
        $this->assertSame(0, $sent);
    }
}
