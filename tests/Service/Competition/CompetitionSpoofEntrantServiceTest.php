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
use App\Service\Competition\CompetitionSpoofEntrantService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class CompetitionSpoofEntrantServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CompetitionSpoofEntrantService $spoofService;
    private CompetitionEntrantRepository $entrantRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em                = self::getContainer()->get(EntityManagerInterface::class);
        $this->spoofService       = self::getContainer()->get(CompetitionSpoofEntrantService::class);
        $this->entrantRepository = self::getContainer()->get(CompetitionEntrantRepository::class);

        $this->em->getConnection()->executeStatement(
            'TRUNCATE competition_entrant, competition_fixture, competition_result,
                      competition_round, active_competition, competition_template,
                      entrant_reward_claim, inbox_message CASCADE',
        );
    }

    private function seedRealEntrant(int $capacity, ?string $country = 'EN'): CompetitionEntrant
    {
        $template = new CompetitionTemplate('Spoof Test Cup', 'spoof-test-cup-' . uniqid('', true), $capacity, CompetitionDuration::ONE_DAY);
        $this->em->persist($template);

        $instance = new ActiveCompetition($template);
        $this->em->persist($instance);

        $user = new User('spoof-source-' . uniqid('', true) . '@example.com');
        $user->setPassword('x');
        $user->setRoles([User::ROLE_CLUB]);
        $club = new Club('Real FC', $user);
        $club->setCountry($country);
        $this->em->persist($user);
        $this->em->persist($club);

        $players = [];
        for ($i = 0; $i < 11; $i++) {
            $players[] = [
                'id'             => "p$i",
                'position'       => 'MID',
                'name'           => "Real Player $i",
                'nationality'    => 'English',
                'currentAbility' => 65,
                'potential'      => 80,
                'pace'           => 60,
            ];
        }

        $entrant = new CompetitionEntrant($instance, $club, 1, [
            'club'    => ['id' => (string) $club->getId(), 'name' => 'Real FC', 'country' => $country, 'reputation' => 50, 'formation' => '4-3-3'],
            'players' => $players,
            'staff'   => [['id' => 's1', 'role' => 'MANAGER', 'name' => 'Real Manager', 'nationality' => 'English']],
        ]);
        $this->em->persist($entrant);
        $this->em->flush();

        return $entrant;
    }

    public function testGeneratesRenamedAndTaggedSpoofEntrants(): void
    {
        $source = $this->seedRealEntrant(capacity: 8);

        $result = $this->spoofService->generateSpoofEntrants($source, 3);

        self::assertCount(3, $result['created']);
        self::assertSame(3, $result['requested']);

        foreach ($result['created'] as $entrant) {
            self::assertTrue($entrant->getClub()->isSpoof());
            self::assertNotSame('Real FC', $entrant->getClub()->getName());
            self::assertTrue(User::isSpoofEmail($entrant->getClub()->getUser()->getEmail()));

            $snapshot = $entrant->getSnapshotJson();
            self::assertSame((string) $entrant->getClub()->getId(), $snapshot['club']['id']);
            self::assertCount(11, $snapshot['players']);

            foreach ($snapshot['players'] as $i => $player) {
                self::assertNotSame("Real Player $i", $player['name']);
                self::assertNotSame("p$i", $player['id']);
                self::assertLessThanOrEqual((int) $player['potential'], (int) $player['currentAbility']);
            }

            self::assertNotSame('Real Manager', $snapshot['staff'][0]['name']);
        }
    }

    public function testStopsEarlyOnceCompetitionAutoLocks(): void
    {
        // Capacity 4: 1 real entrant already registered, so only 3 slots remain.
        $source = $this->seedRealEntrant(capacity: 4);

        $result = $this->spoofService->generateSpoofEntrants($source, 10);

        self::assertSame(3, $result['requested'], 'requested count should clamp to remaining capacity');
        self::assertCount(3, $result['created']);

        $activeCompetition = $source->getActiveCompetition();
        $this->em->refresh($activeCompetition);
        self::assertSame(ActiveCompetitionStatus::SCHEDULED, $activeCompetition->getStatus(), 'filling the last slot should auto-lock the competition');

        self::assertSame(4, $this->entrantRepository->countForCompetition($activeCompetition));
    }

    public function testThrowsWhenCompetitionIsNotRegistering(): void
    {
        $source = $this->seedRealEntrant(capacity: 4);
        $activeCompetition = $source->getActiveCompetition();
        $activeCompetition->setStatus(ActiveCompetitionStatus::SCHEDULED);
        $this->em->flush();

        $this->expectException(\RuntimeException::class);
        $this->spoofService->generateSpoofEntrants($source, 1);
    }
}
