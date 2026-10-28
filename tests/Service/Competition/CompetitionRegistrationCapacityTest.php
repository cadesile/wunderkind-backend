<?php

declare(strict_types=1);

namespace App\Tests\Service\Competition;

use App\Entity\Club;
use App\Entity\Competition\ActiveCompetition;
use App\Entity\Competition\CompetitionTemplate;
use App\Entity\User;
use App\Enum\Competition\ActiveCompetitionStatus;
use App\Enum\Competition\CompetitionDuration;
use App\Enum\Competition\CompetitionEntrantStatus;
use App\Repository\Competition\CompetitionEntrantRepository;
use App\Repository\Competition\CompetitionRoundRepository;
use App\Service\Competition\CompetitionRegistrationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The capacity-fill → lock transition, and the guard that makes it safe under concurrency.
 *
 * `register()` takes `SELECT ... FOR UPDATE` on the ActiveCompetition row as its first
 * statement and calls CompetitionLockService::lock() inside that same transaction. Two
 * properties follow, and both are asserted here:
 *
 *  - **Serialization.** A second registration for the last slot blocks on the row lock, then
 *    reads the now-SCHEDULED status and is rejected. The rejection is checked *in the
 *    transaction*, against the FOR UPDATE-read row — not against the status the controller
 *    read earlier, which can be stale by the time the transaction opens. That in-transaction
 *    check is the thing standing between two clubs and the same last slot, and it had no test
 *    at all.
 *  - **Atomicity.** lock() only persists (InboxService::createMessage() deliberately does not
 *    flush, and nothing pushes), so seeds, rounds and the per-entrant inbox messages all land
 *    on register()'s single flush inside the transaction, alongside the entrant insert and the
 *    fee deduction.
 *
 * True parallelism is not simulatable in PHPUnit, so these drive the *outcome* of the
 * serialization — the state a loser of the race observes — rather than trying to race two
 * connections.
 */
class CompetitionRegistrationCapacityTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CompetitionRegistrationService $registrationService;
    private CompetitionEntrantRepository $entrantRepository;
    private CompetitionRoundRepository $roundRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em                  = self::getContainer()->get(EntityManagerInterface::class);
        $this->registrationService = self::getContainer()->get(CompetitionRegistrationService::class);
        $this->entrantRepository   = self::getContainer()->get(CompetitionEntrantRepository::class);
        $this->roundRepository     = self::getContainer()->get(CompetitionRoundRepository::class);

        $this->em->getConnection()->executeStatement(
            'TRUNCATE competition_entrant, competition_entrant_squad, competition_fixture,
                      competition_result, competition_result_audit, competition_round,
                      competition_response_cache, active_competition, competition_template,
                      entrant_reward_claim CASCADE',
        );
    }

    private function club(string $name, int $balance = 1_000_000): Club
    {
        $user = new User(sprintf('capacity-%s@example.com', uniqid('', true)));
        $user->setPassword('x');
        $club = new Club($name, $user);
        $club->setBalance($balance);
        $this->em->persist($user);
        $this->em->persist($club);
        $this->em->flush();

        return $club;
    }

    private function competition(int $capacity = 4, int $entryFeePence = 0): ActiveCompetition
    {
        $template = new CompetitionTemplate('Capacity Cup', 'capacity-cup-' . uniqid('', true), $capacity, CompetitionDuration::TEN_HOURS);
        $template->setEntryFeePence($entryFeePence);
        $this->em->persist($template);

        $instance = new ActiveCompetition($template);
        $this->em->persist($instance);
        $this->em->flush();

        return $instance;
    }

    /** @return array<string, mixed> */
    private function envelope(Club $club, string $name): array
    {
        $players = [];
        for ($i = 0; $i < 11; $i++) {
            $players[] = ['id' => "$name-p$i", 'name' => "$name Player $i", 'position' => 'MID', 'currentAbility' => 60];
        }

        return [
            'club'    => ['id' => (string) $club->getId(), 'name' => $name],
            'players' => $players,
            'staff'   => [['id' => 's1', 'role' => 'MANAGER', 'name' => "$name Boss"]],
        ];
    }

    private function fill(ActiveCompetition $instance, int $count, int $from = 1): void
    {
        for ($i = $from; $i < $from + $count; $i++) {
            $club = $this->club("Capacity Club {$i}");
            $this->registrationService->register($instance, $club, $this->envelope($club, "Capacity Club {$i}"));
        }
    }

    public function testFillingTheLastSlotLocksTheCompetitionInTheSameTransaction(): void
    {
        $instance = $this->competition(capacity: 4);

        $this->fill($instance, 3);
        $this->em->refresh($instance);
        self::assertSame(ActiveCompetitionStatus::REGISTERING, $instance->getStatus(), 'three of four must not lock it');
        self::assertCount(0, $this->roundRepository->findByCompetitionOrderedByIndex($instance));

        $this->fill($instance, 1, from: 4);
        $this->em->refresh($instance);

        self::assertSame(ActiveCompetitionStatus::SCHEDULED, $instance->getStatus());
        self::assertNotNull($instance->getLockedAt());

        // Everything lock() does committed with the final registration, not after it.
        $rounds = $this->roundRepository->findByCompetitionOrderedByIndex($instance);
        self::assertCount(2, $rounds, 'a 4-entrant bracket must have both its rounds');

        $seeds = [];
        foreach ($this->entrantRepository->findByCompetitionOrderedByRegistration($instance) as $entrant) {
            self::assertSame(CompetitionEntrantStatus::ACTIVE, $entrant->getStatus());
            $seeds[] = $entrant->getSeed();
        }
        self::assertSame([1, 2, 3, 4], $seeds, 'seeds are assigned at lock time, in registration order');
    }

    /**
     * The guard that actually handles the race, and the one that had no coverage.
     *
     * The controller checks the status before calling the service, but that read can be stale
     * by the time the transaction opens — which is exactly the position the loser of a
     * last-slot race is in. The service must reject on the status it read under FOR UPDATE.
     */
    public function testRegisteringIntoALockedCompetitionIsRejectedByTheService(): void
    {
        $instance = $this->competition(capacity: 4);
        $this->fill($instance, 4);
        $this->em->refresh($instance);
        self::assertSame(ActiveCompetitionStatus::SCHEDULED, $instance->getStatus());

        $latecomer = $this->club('Latecomer FC');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('registration_closed');

        $this->registrationService->register($instance, $latecomer, $this->envelope($latecomer, 'Latecomer FC'));
    }

    /**
     * A rejected registration must not charge the entry fee. The fee deduction happens inside
     * the same transaction as the entrant insert, so the rejection has to leave the balance
     * untouched rather than refund it afterwards.
     */
    public function testARejectedRegistrationDoesNotChargeTheEntryFee(): void
    {
        $instance = $this->competition(capacity: 4, entryFeePence: 25_000);
        $this->fill($instance, 4);

        $latecomer = $this->club('Thrifty FC', balance: 500_000);
        $before    = $latecomer->getBalance();

        try {
            $this->registrationService->register($instance, $latecomer, $this->envelope($latecomer, 'Thrifty FC'));
            self::fail('registration into a locked competition must be rejected');
        } catch (\DomainException) {
            // expected
        }

        $this->em->refresh($latecomer);
        self::assertSame($before, $latecomer->getBalance(), 'a closed registration must not take the fee');
        self::assertNull($this->entrantRepository->findByCompetitionAndClub($instance, $latecomer));
    }

    /** Locking is a one-time transition: a later rejected attempt must not re-seed or re-round it. */
    public function testALateAttemptDoesNotRelockTheCompetition(): void
    {
        $instance = $this->competition(capacity: 4);
        $this->fill($instance, 4);
        $this->em->refresh($instance);

        $lockedAt   = $instance->getLockedAt();
        $roundCount = count($this->roundRepository->findByCompetitionOrderedByIndex($instance));

        $latecomer = $this->club('Second Thoughts FC');
        try {
            $this->registrationService->register($instance, $latecomer, $this->envelope($latecomer, 'Second Thoughts FC'));
        } catch (\DomainException) {
            // expected
        }

        $this->em->refresh($instance);
        self::assertEquals($lockedAt, $instance->getLockedAt(), 'lockedAt must not move');
        self::assertCount($roundCount, $this->roundRepository->findByCompetitionOrderedByIndex($instance), 'no duplicate rounds');
    }
}
