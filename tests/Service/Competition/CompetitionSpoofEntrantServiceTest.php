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
use App\Exception\SpoofSnapshotValidationException;
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

    public function testGenerateSpoofEntrantsForCompetitionUsesEarliestEntrantAsBasis(): void
    {
        $source            = $this->seedRealEntrant(capacity: 8);
        $activeCompetition = $source->getActiveCompetition();

        $result = $this->spoofService->generateSpoofEntrantsForCompetition($activeCompetition, 3);

        self::assertCount(3, $result['created']);
        foreach ($result['created'] as $entrant) {
            self::assertTrue($entrant->getClub()->isSpoof());
            self::assertNotSame('Real FC', $entrant->getClub()->getName());
            // country carried through from the earliest entrant's snapshot (the resolved basis).
            self::assertSame('EN', $entrant->getSnapshotJson()['club']['country']);
        }
    }

    public function testGenerateSpoofEntrantsForCompetitionThrowsWithNoEntrantsYet(): void
    {
        $template = new CompetitionTemplate('Empty Spoof Cup', 'empty-spoof-cup-' . uniqid('', true), 8, CompetitionDuration::ONE_DAY);
        $this->em->persist($template);
        $activeCompetition = new ActiveCompetition($template);
        $this->em->persist($activeCompetition);
        $this->em->flush();

        $this->expectException(\RuntimeException::class);
        $this->spoofService->generateSpoofEntrantsForCompetition($activeCompetition, 1);
    }

    private function buildOpenCompetition(int $capacity): ActiveCompetition
    {
        $template = new CompetitionTemplate('Paste Spoof Cup', 'paste-spoof-cup-' . uniqid('', true), $capacity, CompetitionDuration::ONE_DAY);
        $this->em->persist($template);

        $activeCompetition = new ActiveCompetition($template);
        $this->em->persist($activeCompetition);
        $this->em->flush();

        return $activeCompetition;
    }

    /** @return array<string, mixed> A minimal but structurally-valid pasted snapshot. */
    private function pastedSnapshot(): array
    {
        $players = [];
        for ($i = 0; $i < 11; $i++) {
            $players[] = [
                'id'             => "pasted-p$i",
                'position'       => 'MID',
                'name'           => "Pasted Player $i",
                'nationality'    => 'German',
                'currentAbility' => 50,
                'potential'      => 70,
            ];
        }

        return [
            'club' => [
                'id'         => 'client-side-id-does-not-matter',
                'name'       => 'Oldham Warriors',
                'reputation' => 100,
                'playingStyle' => 'HIGH_PRESS',
            ],
            'players' => $players,
            'staff'   => [
                ['id' => 'pasted-s1', 'role' => 'MANAGER', 'name' => 'Klaus Braun', 'nationality' => 'German'],
            ],
            'facilities' => ['training_pitch' => 9],
        ];
    }

    public function testCreateSpoofEntrantFromSnapshotRegistersVerbatimWhenNotRandomised(): void
    {
        $activeCompetition = $this->buildOpenCompetition(capacity: 8);

        $entrant = $this->spoofService->createSpoofEntrantFromSnapshot($activeCompetition, $this->pastedSnapshot(), randomise: false);

        self::assertTrue($entrant->getClub()->isSpoof());
        self::assertSame('Oldham Warriors', $entrant->getClub()->getName());

        $snapshot = $entrant->getSnapshotJson();
        // club.id is resynced to the real persisted entity, everything else passes through untouched.
        self::assertSame((string) $entrant->getClub()->getId(), $snapshot['club']['id']);
        self::assertSame('Oldham Warriors', $snapshot['club']['name']);
        self::assertSame('HIGH_PRESS', $snapshot['club']['playingStyle']);
        self::assertSame('Pasted Player 0', $snapshot['players'][0]['name']);
        self::assertSame('pasted-p0', $snapshot['players'][0]['id']);
        self::assertSame('Klaus Braun', $snapshot['staff'][0]['name']);
        self::assertSame(['training_pitch' => 9], $snapshot['facilities']);
    }

    public function testCreateSpoofEntrantFromSnapshotRandomisesWhenRequested(): void
    {
        $activeCompetition = $this->buildOpenCompetition(capacity: 8);

        $entrant = $this->spoofService->createSpoofEntrantFromSnapshot($activeCompetition, $this->pastedSnapshot(), randomise: true);

        self::assertNotSame('Oldham Warriors', $entrant->getClub()->getName());

        $snapshot = $entrant->getSnapshotJson();
        self::assertNotSame('Pasted Player 0', $snapshot['players'][0]['name']);
        self::assertNotSame('pasted-p0', $snapshot['players'][0]['id']);
        self::assertNotSame('Klaus Braun', $snapshot['staff'][0]['name']);
    }

    public function testCreateSpoofEntrantFromSnapshotThrowsOnStructuralViolations(): void
    {
        $activeCompetition = $this->buildOpenCompetition(capacity: 8);

        $snapshot = $this->pastedSnapshot();
        array_pop($snapshot['players']); // now only 10 players — violates the exactly-11 rule

        $this->expectException(SpoofSnapshotValidationException::class);
        $this->spoofService->createSpoofEntrantFromSnapshot($activeCompetition, $snapshot, randomise: false);
    }

    public function testCreateSpoofEntrantFromSnapshotThrowsWhenCompetitionIsFull(): void
    {
        // 4 entrants persisted directly (not via CompetitionRegistrationService) so the
        // competition stays REGISTERING despite being at capacity — this exercises the
        // capacity guard specifically, independent of the status guard.
        $activeCompetition = $this->buildOpenCompetition(capacity: 4);
        for ($i = 0; $i < 4; $i++) {
            $user = new User("full-slot-$i-" . uniqid('', true) . '@example.com');
            $user->setPassword('x');
            $club = new Club("Filler FC $i", $user);
            $this->em->persist($user);
            $this->em->persist($club);
            $this->em->persist(new CompetitionEntrant($activeCompetition, $club, $i, ['club' => ['id' => (string) $club->getId()], 'players' => []]));
        }
        $this->em->flush();
        self::assertSame(ActiveCompetitionStatus::REGISTERING, $activeCompetition->getStatus());

        $this->expectException(\RuntimeException::class);
        $this->spoofService->createSpoofEntrantFromSnapshot($activeCompetition, $this->pastedSnapshot(), randomise: false);
    }

    public function testSpoofAllEntrantsFillsAnEmptyCompetitionFromScratch(): void
    {
        $activeCompetition = $this->buildOpenCompetition(capacity: 4);

        $result = $this->spoofService->spoofAllEntrants($activeCompetition);

        self::assertCount(4, $result['created']);
        self::assertSame(4, $result['requested']);

        $names = [];
        foreach ($result['created'] as $entrant) {
            self::assertTrue($entrant->getClub()->isSpoof());
            self::assertTrue(User::isSpoofEmail($entrant->getClub()->getUser()->getEmail()));

            $snapshot = $entrant->getSnapshotJson();
            self::assertCount(11, $snapshot['players'], 'a full starting XI must be generated for the bootstrap entrant and every clone');
            foreach ($snapshot['players'] as $player) {
                self::assertNotEmpty($player['name']);
                self::assertContains($player['position'], ['GK', 'DEF', 'MID', 'ATT']);
            }

            // Kit/badge/stadium display data must be present on every entrant — including
            // the clones, which inherit it from the bootstrap entrant's club object — so
            // the device can render kits/badges for this competition's matches.
            foreach (['homePrimary', 'homeSecondary', 'awayPrimary', 'awaySecondary', 'badgeShape', 'homeKitStyle', 'awayKitStyle', 'stadiumName', 'playingStyle', 'tier'] as $field) {
                self::assertArrayHasKey($field, $snapshot['club'], "club.$field must be present");
                self::assertNotSame('', (string) $snapshot['club'][$field]);
            }

            $names[] = $entrant->getClub()->getName();
        }
        self::assertSame($names, array_unique($names), 'every spoofed club must have a distinct name');

        $this->em->refresh($activeCompetition);
        self::assertSame(ActiveCompetitionStatus::SCHEDULED, $activeCompetition->getStatus(), 'filling the last slot should auto-lock the competition');
        self::assertSame(4, $this->entrantRepository->countForCompetition($activeCompetition));
    }

    public function testSpoofAllEntrantsUsesExistingEntrantAsBasisWhenOneAlreadyExists(): void
    {
        $source            = $this->seedRealEntrant(capacity: 4);
        $activeCompetition = $source->getActiveCompetition();

        $result = $this->spoofService->spoofAllEntrants($activeCompetition);

        self::assertCount(3, $result['created'], 'only the 3 remaining slots should be filled — the real entrant already occupies one');
        foreach ($result['created'] as $entrant) {
            self::assertNotSame('Real FC', $entrant->getClub()->getName());
        }

        self::assertSame(4, $this->entrantRepository->countForCompetition($activeCompetition));
    }

    public function testSpoofAllEntrantsIsANoOpWhenAlreadyFull(): void
    {
        $activeCompetition = $this->buildOpenCompetition(capacity: 4);
        $this->spoofService->spoofAllEntrants($activeCompetition);

        $this->em->refresh($activeCompetition);
        $activeCompetition->setStatus(ActiveCompetitionStatus::REGISTERING); // force back open, bypassing the real lock, purely to exercise this guard
        $this->em->flush();

        $result = $this->spoofService->spoofAllEntrants($activeCompetition);
        self::assertSame(['created' => [], 'requested' => 0], $result);
    }

    public function testSpoofAllEntrantsThrowsWhenCompetitionIsNotRegistering(): void
    {
        $activeCompetition = $this->buildOpenCompetition(capacity: 4);
        $activeCompetition->setStatus(ActiveCompetitionStatus::CANCELLED);
        $this->em->flush();

        $this->expectException(\RuntimeException::class);
        $this->spoofService->spoofAllEntrants($activeCompetition);
    }
}
