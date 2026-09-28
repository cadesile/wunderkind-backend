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
use App\Repository\Competition\CompetitionEntrantRepository;
use App\Service\Competition\CompetitionAutoFillService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class CompetitionAutoFillServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CompetitionAutoFillService $autoFillService;
    private CompetitionEntrantRepository $entrantRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em                = self::getContainer()->get(EntityManagerInterface::class);
        $this->autoFillService   = self::getContainer()->get(CompetitionAutoFillService::class);
        $this->entrantRepository = self::getContainer()->get(CompetitionEntrantRepository::class);

        $this->em->getConnection()->executeStatement(
            'TRUNCATE competition_entrant, competition_fixture, competition_result,
                      competition_round, active_competition, competition_template,
                      entrant_reward_claim, inbox_message CASCADE',
        );
    }

    /** Builds a REGISTERING 4-capacity instance with one real entrant already registered. */
    private function seedInstanceWithOneRealEntrant(bool $autoFillEnabled, int $delayMinutes = 5): ActiveCompetition
    {
        $template = new CompetitionTemplate('Auto-fill Cup', 'auto-fill-cup-' . uniqid('', true), 4, CompetitionDuration::ONE_DAY);
        $template->setAutoFillSpoofEntrants($autoFillEnabled);
        $template->setAutoFillDelayMinutes($delayMinutes);
        $this->em->persist($template);

        $instance = new ActiveCompetition($template);
        $this->em->persist($instance);

        $user = new User('auto-fill-real-' . uniqid('', true) . '@example.com');
        $user->setPassword('x');
        $user->setRoles([User::ROLE_CLUB]);
        $club = new Club('Real FC', $user);
        $this->em->persist($user);
        $this->em->persist($club);

        $players = [];
        for ($i = 0; $i < 11; $i++) {
            $players[] = ['id' => "p$i", 'position' => 'MID', 'name' => "Real Player $i", 'currentAbility' => 65];
        }

        $entrant = new CompetitionEntrant($instance, $club, 1, [
            'club'    => ['id' => (string) $club->getId(), 'name' => 'Real FC', 'country' => 'EN', 'reputation' => 50],
            'players' => $players,
            'staff'   => [['id' => 's1', 'role' => 'MANAGER', 'name' => 'Real Manager']],
        ]);
        $this->em->persist($entrant);
        $this->em->flush();

        return $instance;
    }

    public function testDoesNotFillBeforeTheDelayHasElapsed(): void
    {
        $instance = $this->seedInstanceWithOneRealEntrant(autoFillEnabled: true, delayMinutes: 5);

        $filled = $this->autoFillService->autoFillDueInstances(new \DateTimeImmutable('+1 minute'));

        self::assertSame(0, $filled);
        self::assertSame(1, $this->entrantRepository->countForCompetition($instance));
    }

    public function testFillsToCapacityOnceTheDelayHasElapsed(): void
    {
        $instance = $this->seedInstanceWithOneRealEntrant(autoFillEnabled: true, delayMinutes: 5);

        $filled = $this->autoFillService->autoFillDueInstances(new \DateTimeImmutable('+10 minutes'));

        self::assertSame(1, $filled);
        self::assertSame(4, $this->entrantRepository->countForCompetition($instance));

        // Filling to capacity must trigger the same auto-lock a genuinely full house would.
        $this->em->refresh($instance);
        self::assertSame(ActiveCompetitionStatus::SCHEDULED, $instance->getStatus());
    }

    public function testDoesNothingWhenTheTemplateHasNotOptedIn(): void
    {
        $instance = $this->seedInstanceWithOneRealEntrant(autoFillEnabled: false, delayMinutes: 5);

        $filled = $this->autoFillService->autoFillDueInstances(new \DateTimeImmutable('+1 hour'));

        self::assertSame(0, $filled);
        self::assertSame(1, $this->entrantRepository->countForCompetition($instance));
    }
}
