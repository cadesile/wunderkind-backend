<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\SyncRequest;
use App\Entity\Club;
use App\Entity\LeaderboardEntry;
use App\Entity\SyncRecord;
use App\Entity\User;
use App\Entity\UserLedger;
use App\Service\SyncService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A resent sync payload is replayed, never applied twice (Courtney, 2026-11-28: two
 * identical VALID week-1 records for one club after the app retried a sync it had not
 * seen confirmed). The money that matters is the owner's: a dividend draw in a resent
 * payload must reach the user ledger once.
 */
class SyncServiceIdempotencyTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    /** @var Club[] */
    private array $clubs = [];

    /** @var User[] */
    private array $users = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        foreach ($this->clubs as $club) {
            $managed = $this->em->find(Club::class, $club->getId());
            if ($managed === null) {
                continue;
            }
            foreach ($this->em->getRepository(UserLedger::class)->findBy(['club' => $managed]) as $row) {
                $this->em->remove($row);
            }
            foreach ($this->em->getRepository(LeaderboardEntry::class)->findBy(['club' => $managed]) as $row) {
                $this->em->remove($row);
            }
            foreach ($this->em->getRepository(SyncRecord::class)->findBy(['club' => $managed]) as $row) {
                $this->em->remove($row);
            }
            $this->em->flush();
            $this->em->remove($managed);
        }
        foreach ($this->users as $user) {
            $managed = $this->em->find(User::class, $user->getId());
            if ($managed !== null) {
                $this->em->remove($managed);
            }
        }
        $this->em->flush();
        $this->clubs = $this->users = [];
        parent::tearDown();
    }

    public function testTheSameKeySentTwiceIsAppliedOnceAndReplayed(): void
    {
        [$user, $club] = $this->persistUserAndClub();
        $service = self::getContainer()->get(SyncService::class);

        $first  = $service->process($user, $this->payload(1, '2026-11-28T00:10:00.123Z', 'key-wk1', 250_00));
        $second = $service->process($user, $this->payload(1, '2026-11-28T00:10:00.123Z', 'key-wk1', 250_00));

        $this->assertTrue($first['accepted']);
        $this->assertArrayNotHasKey('replayed', $first);
        $this->assertTrue($second['accepted']);
        $this->assertTrue($second['replayed']);
        $this->assertSame($first['club']['balance'], $second['club']['balance']);

        $this->assertCount(1, $this->syncRecordsFor($club));
        $this->assertCount(1, $this->dividendRowsFor($club), 'a resent dividend draw must not credit the owner twice');
    }

    public function testAPayloadWithoutAKeyIsDedupedOnWeekAndClientTimestamp(): void
    {
        // Every build released before 2026-11-28 sends no key. Its queue resends the same
        // JSON, so the same clientTimestamp, and that is enough to recognise it.
        [$user, $club] = $this->persistUserAndClub();
        $service = self::getContainer()->get(SyncService::class);

        $service->process($user, $this->payload(1, '2026-11-28T00:10:00.123Z', null, 100_00));
        $again = $service->process($user, $this->payload(1, '2026-11-28T00:10:00.123Z', null, 100_00));

        $this->assertTrue($again['replayed']);
        $this->assertCount(1, $this->syncRecordsFor($club));
        $this->assertCount(1, $this->dividendRowsFor($club));
    }

    public function testDifferentPayloadsAreAllApplied(): void
    {
        [$user, $club] = $this->persistUserAndClub();
        $service = self::getContainer()->get(SyncService::class);

        $service->process($user, $this->payload(1, '2026-11-28T00:10:00.123Z', 'key-wk1', 100_00));
        $service->process($user, $this->payload(2, '2026-11-28T00:11:00.456Z', 'key-wk2', 100_00));
        // Same week, new payload (a second build of week 2 after a reload): a new key, so applied.
        $service->process($user, $this->payload(2, '2026-11-28T00:12:00.789Z', 'key-wk2-b', 0));

        $this->assertCount(3, $this->syncRecordsFor($club));
        $this->assertCount(2, $this->dividendRowsFor($club));
    }

    public function testTheKeyIsScopedToTheClub(): void
    {
        // Two saves of one account may well mint colliding keys in theory; they must not
        // swallow each other's weeks.
        [$user, $clubA] = $this->persistUserAndClub();
        $clubB = new Club('Second Save FC', $user);
        $this->em->persist($clubB);
        $this->em->flush();
        $this->clubs[] = $clubB;
        $service = self::getContainer()->get(SyncService::class);

        $a = $this->payload(1, '2026-11-28T00:10:00.123Z', 'shared-key', 0);
        $a->clubId = $clubA->getId()->toRfc4122();
        $b = $this->payload(1, '2026-11-28T00:10:00.123Z', 'shared-key', 0);
        $b->clubId = $clubB->getId()->toRfc4122();

        $service->process($user, $a);
        $result = $service->process($user, $b);

        $this->assertArrayNotHasKey('replayed', $result);
        $this->assertCount(1, $this->syncRecordsFor($clubA));
        $this->assertCount(1, $this->syncRecordsFor($clubB));
    }

    private function payload(int $week, string $clientTimestamp, ?string $key, int $dividendPence): SyncRequest
    {
        $request                  = new SyncRequest();
        $request->idempotencyKey  = $key;
        $request->weekNumber      = $week;
        $request->clientTimestamp = $clientTimestamp;
        $request->balance         = 1_000_000;
        if ($dividendPence > 0) {
            $request->setLedger([
                ['category' => 'dividend_draw', 'amount' => $dividendPence, 'description' => 'Owner dividend'],
            ]);
        }

        return $request;
    }

    /** @return SyncRecord[] */
    private function syncRecordsFor(Club $club): array
    {
        return $this->em->getRepository(SyncRecord::class)->findBy(['club' => $club]);
    }

    /** @return UserLedger[] */
    private function dividendRowsFor(Club $club): array
    {
        return $this->em->getRepository(UserLedger::class)->findBy(['club' => $club]);
    }

    /** @return array{0: User, 1: Club} */
    private function persistUserAndClub(): array
    {
        $user = new User(bin2hex(random_bytes(8)) . '@sync-idem.test');
        $user->setPassword('x');
        $this->em->persist($user);
        $this->users[] = $user;

        $club = new Club('Idempotent FC', $user);
        $this->em->persist($club);
        $this->clubs[] = $club;

        $this->em->flush();

        return [$user, $club];
    }
}
