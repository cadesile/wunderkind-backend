<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\LedgerEntrySyncDto;
use App\Dto\SyncRequest;
use App\Entity\Club;
use App\Entity\LeaderboardEntry;
use App\Entity\SyncRecord;
use App\Entity\User;
use App\Entity\UserLedger;
use App\Enum\UserLedgerEntryType;
use App\Repository\UserLedgerRepository;
use App\Service\SyncService;
use App\Service\UserLedgerService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Covers dividend_draw detection in the sync ledger (SyncService -> UserLedgerService),
 * cross-sync balance chaining, and idempotent re-processing. See UserLedger's class docblock
 * for why before/after balances are snapshotted per row.
 */
class UserLedgerServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private UserLedgerRepository $userLedgerRepository;

    /** @var object[] */
    private array $cleanup = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em                  = self::getContainer()->get(EntityManagerInterface::class);
        $this->userLedgerRepository = self::getContainer()->get(UserLedgerRepository::class);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->cleanup) as $entity) {
            $managed = $this->em->find($entity::class, $entity->getId());
            if ($managed !== null) {
                $this->em->remove($managed);
            }
        }
        $this->cleanup = [];
        $this->em->flush();
        parent::tearDown();
    }

    public function testDividendDrawInSyncLedgerCreatesAUserLedgerRowAndIgnoresOtherCategories(): void
    {
        [$user, $club] = $this->persistUserAndClub();

        $request                  = new SyncRequest();
        $request->clubId          = (string) $club->getId();
        $request->weekNumber      = 1;
        $request->clientTimestamp = '2026-10-01T00:00:00+00:00';
        $request->ledger          = [
            $this->ledgerEntry('wages', -5000, 'weekly wages'),
            // dividend_draw is real pence, not the 100x-inflated value other categories carry
            // (see UserLedgerService's docblock) — 25000 here is a real-world £250.00 draw.
            $this->ledgerEntry('dividend_draw', 25000, 'manager dividend draw'),
        ];

        self::getContainer()->get(SyncService::class)->process($user, $request);

        $syncRecord      = $this->em->getRepository(SyncRecord::class)->findOneBy(['club' => $club]);
        $this->cleanup[] = $syncRecord;
        $this->cleanupLeaderboardEntriesFor($club);

        $rows = $this->userLedgerRepository->findByUser($user);
        $this->cleanup = array_merge($this->cleanup, $rows);

        $this->assertCount(1, $rows);
        $entry = $rows[0];
        $this->assertSame(UserLedgerEntryType::DIVIDEND_DRAW, $entry->getType());
        $this->assertSame($club->getId()->toRfc4122(), $entry->getClub()->getId()->toRfc4122());
        $this->assertSame(25000, $entry->getAmountPence());
        $this->assertSame(0, $entry->getBalanceBeforePence());
        $this->assertSame(25000, $entry->getBalanceAfterPence());
        $this->assertSame('manager dividend draw', $entry->getDescription());
        $this->assertSame(1, $entry->getSourceLedgerIndex());
        $this->assertSame($syncRecord->getId()->toRfc4122(), $entry->getSourceSyncRecord()?->getId()->toRfc4122());
        $this->assertSame(25000, $this->userLedgerRepository->getCurrentBalance($user));
    }

    public function testBalanceChainsAcrossSubsequentSyncs(): void
    {
        [$user, $club] = $this->persistUserAndClub();

        $first                  = new SyncRequest();
        $first->clubId          = (string) $club->getId();
        $first->weekNumber      = 1;
        $first->clientTimestamp = '2026-10-01T00:00:00+00:00';
        $first->ledger          = [$this->ledgerEntry('dividend_draw', 10000, 'first draw')];
        self::getContainer()->get(SyncService::class)->process($user, $first);

        $second                  = new SyncRequest();
        $second->clubId          = (string) $club->getId();
        $second->weekNumber      = 2;
        $second->clientTimestamp = '2026-10-08T00:00:00+00:00';
        $second->ledger          = [$this->ledgerEntry('dividend_draw', 5000, 'second draw')];
        self::getContainer()->get(SyncService::class)->process($user, $second);

        $this->cleanup = array_merge($this->cleanup, $this->em->getRepository(SyncRecord::class)->findBy(['club' => $club]));
        $this->cleanupLeaderboardEntriesFor($club);

        $rows = $this->userLedgerRepository->findByUser($user);
        $this->cleanup = array_merge($this->cleanup, $rows);

        $this->assertCount(2, $rows);
        $this->assertSame(15000, $this->userLedgerRepository->getCurrentBalance($user));

        $byAmount = [];
        foreach ($rows as $row) {
            $byAmount[$row->getAmountPence()] = $row;
        }
        $this->assertSame(0, $byAmount[10000]->getBalanceBeforePence());
        $this->assertSame(10000, $byAmount[10000]->getBalanceAfterPence());
        $this->assertSame(10000, $byAmount[5000]->getBalanceBeforePence());
        $this->assertSame(15000, $byAmount[5000]->getBalanceAfterPence());
    }

    public function testReprocessingTheSameSourceEntryIsIdempotent(): void
    {
        [$user, $club] = $this->persistUserAndClub();

        $syncRecord      = new SyncRecord($club, 1, new \DateTimeImmutable('2026-10-01'), [
            'ledger' => [['category' => 'dividend_draw', 'amount' => 7000, 'description' => 'draw']],
        ]);
        $this->em->persist($syncRecord);
        $this->em->flush();
        $this->cleanup[] = $syncRecord;

        $service = self::getContainer()->get(UserLedgerService::class);

        $ledgerEntries = $syncRecord->getPayload()['ledger'];
        $created1 = $service->recordDividendDraws($user, $club, $syncRecord, $ledgerEntries, new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-01'));
        $this->em->flush();
        $created2 = $service->recordDividendDraws($user, $club, $syncRecord, $ledgerEntries, new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-01'));
        $this->em->flush();

        $this->assertCount(1, $created1);
        $this->assertCount(0, $created2, 'Re-processing the same (syncRecord, index) must not create a duplicate row');

        $rows = $this->em->getRepository(UserLedger::class)->findBy(['user' => $user]);
        $this->cleanup = array_merge($this->cleanup, $rows);
        $this->assertCount(1, $rows);
    }

    private function ledgerEntry(string $category, int $amount, string $description): LedgerEntrySyncDto
    {
        $dto              = new LedgerEntrySyncDto();
        $dto->category    = $category;
        $dto->amount      = $amount;
        $dto->description = $description;

        return $dto;
    }

    private function cleanupLeaderboardEntriesFor(Club $club): void
    {
        foreach ($this->em->getRepository(LeaderboardEntry::class)->findBy(['club' => $club]) as $entry) {
            $this->cleanup[] = $entry;
        }
    }

    /** @return array{0: User, 1: Club} */
    private function persistUserAndClub(): array
    {
        $user = new User(bin2hex(random_bytes(8)) . '@userledger.test');
        $user->setPassword('x');
        $this->em->persist($user);
        $this->cleanup[] = $user;

        $club = new Club('User Ledger FC', $user);
        $this->em->persist($club);
        $this->cleanup[] = $club;

        $this->em->flush();

        return [$user, $club];
    }
}
