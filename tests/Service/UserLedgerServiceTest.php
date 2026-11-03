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
    // ───────────────── backend-originated entries (append()) ─────────────────

    /**
     * THE TEST THIS WHOLE STEP EXISTS FOR.
     *
     * UserLedgerRepository::sumDividendsByClub() feeds LeaderboardCategory::CAREER_EARNINGS,
     * which is PUBLIC, and it was an unfiltered SUM(amountPence) back when dividend draws were
     * the only rows in the table. Purchases are stored NEGATIVE, so without a type filter the
     * first land purchase would have silently deflated a club's public career-earnings score —
     * and a club that spent more than it drew would have gone negative on a board whose score
     * column is unsigned.
     */
    public function testAPurchaseDoesNotCorruptTheCareerEarningsLeaderboard(): void
    {
        [$user, $club] = $this->persistUserAndClub();
        $service       = self::getContainer()->get(UserLedgerService::class);

        $draw = $service->append($user, $club, UserLedgerEntryType::DIVIDEND_DRAW, 50_000, 'test', 'draw-1');
        $this->cleanup[] = $draw;

        $earningsBefore = $this->dividendsFor($club);
        self::assertSame(50_000, $earningsBefore);
        self::assertSame(50_000, $this->userLedgerRepository->getTotalDividendsByClub($club));

        // Now spend more than was drawn, against the same club.
        $purchase = $service->append($user, $club, UserLedgerEntryType::LAND_PURCHASE, -120_000, 'plot_claim', 'claim-1');
        $this->cleanup[] = $purchase;

        self::assertSame(
            $earningsBefore,
            $this->dividendsFor($club),
            'a land purchase must not change the public career-earnings figure',
        );
        self::assertSame(
            50_000,
            $this->userLedgerRepository->getTotalDividendsByClub($club),
            'the admin dividends figure must not be reduced by spending either',
        );

        // The BALANCE, by contrast, must absolutely reflect the spend.
        self::assertSame(-70_000, $this->userLedgerRepository->getCurrentBalance($user));
    }

    /** The before/after chain stays continuous across mixed entry types. */
    public function testBalanceChainsAcrossMixedEntryTypes(): void
    {
        [$user, $club] = $this->persistUserAndClub();
        $service       = self::getContainer()->get(UserLedgerService::class);

        $grant    = $service->append($user, null, UserLedgerEntryType::STARTING_GRANT, 100_000, 'grant', 'welcome');
        $purchase = $service->append($user, null, UserLedgerEntryType::LAND_PURCHASE, -30_000, 'plot_claim', 'c1');
        $stadium  = $service->append($user, $club, UserLedgerEntryType::STADIUM_PURCHASE, -25_000, 'plot_claim', 'c1');
        $sellBack = $service->append($user, $club, UserLedgerEntryType::SELL_BACK_CREDIT, 40_000, 'plot_sell_back', 'c1');

        foreach ([$grant, $purchase, $stadium, $sellBack] as $entry) {
            $this->cleanup[] = $entry;
        }

        self::assertSame(0, $grant->getBalanceBeforePence());
        self::assertSame(100_000, $grant->getBalanceAfterPence());
        self::assertSame(100_000, $purchase->getBalanceBeforePence());
        self::assertSame(70_000, $purchase->getBalanceAfterPence());
        self::assertSame(70_000, $stadium->getBalanceBeforePence());
        self::assertSame(45_000, $stadium->getBalanceAfterPence());
        self::assertSame(45_000, $sellBack->getBalanceBeforePence());
        self::assertSame(85_000, $sellBack->getBalanceAfterPence());

        self::assertSame(85_000, $this->userLedgerRepository->getCurrentBalance($user));
    }

    /**
     * A land purchase can precede a club existing at all.
     *
     * The reason club_id became nullable: a grant, a quiz bonus and a pre-club purchase are all
     * facts about the ACCOUNT, and there is no club to attribute them to.
     */
    public function testAnAccountLevelEntryNeedsNoClub(): void
    {
        [$user] = $this->persistUserAndClub();
        $service = self::getContainer()->get(UserLedgerService::class);

        $entry = $service->append($user, null, UserLedgerEntryType::STARTING_GRANT, 250_000, 'grant', 'welcome');
        $this->cleanup[] = $entry;

        self::assertNull($entry->getClub());
        self::assertSame(250_000, $this->userLedgerRepository->getCurrentBalance($user));
    }

    /**
     * A repeat call returns the original entry and charges nothing twice.
     *
     * A double-tapped "buy" is the ordinary case, not the exotic one.
     */
    public function testRepeatedAppendIsIdempotent(): void
    {
        [$user, $club] = $this->persistUserAndClub();
        $service       = self::getContainer()->get(UserLedgerService::class);

        $first = $service->append($user, $club, UserLedgerEntryType::LAND_PURCHASE, -45_000, 'plot_claim', 'claim-xyz');
        $this->cleanup[] = $first;

        $second = $service->append($user, $club, UserLedgerEntryType::LAND_PURCHASE, -45_000, 'plot_claim', 'claim-xyz');

        self::assertSame((string) $first->getId(), (string) $second->getId(), 'the original entry should come back');
        self::assertCount(1, $this->userLedgerRepository->findByUser($user));
        self::assertSame(-45_000, $this->userLedgerRepository->getCurrentBalance($user));
    }

    /** One claim writes both a land and a stadium charge, so `type` is part of the key. */
    public function testLandAndStadiumChargesShareASourceRef(): void
    {
        [$user, $club] = $this->persistUserAndClub();
        $service       = self::getContainer()->get(UserLedgerService::class);

        $land    = $service->append($user, $club, UserLedgerEntryType::LAND_PURCHASE, -40_000, 'plot_claim', 'claim-1');
        $stadium = $service->append($user, $club, UserLedgerEntryType::STADIUM_PURCHASE, -60_000, 'plot_claim', 'claim-1');

        $this->cleanup[] = $land;
        $this->cleanup[] = $stadium;

        self::assertNotSame((string) $land->getId(), (string) $stadium->getId());
        self::assertSame(-100_000, $this->userLedgerRepository->getCurrentBalance($user));
    }

    /** A positive debit is a programming error, and fails loudly rather than crediting. */
    public function testAPositiveDebitIsRefused(): void
    {
        [$user, $club] = $this->persistUserAndClub();
        $service       = self::getContainer()->get(UserLedgerService::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/negative/');

        $service->append($user, $club, UserLedgerEntryType::LAND_PURCHASE, 40_000, 'plot_claim', 'claim-bad');
    }

    /** No source means no idempotency guard, so it is refused up front. */
    public function testAnEntryWithoutASourceIsRefused(): void
    {
        [$user, $club] = $this->persistUserAndClub();
        $service       = self::getContainer()->get(UserLedgerService::class);

        $this->expectException(\InvalidArgumentException::class);

        $service->append($user, $club, UserLedgerEntryType::QUIZ_BONUS, 1_000, '', '');
    }

    /** A reversal never edits its target; it writes an opposing entry. */
    public function testAReversalIsANewOpposingEntry(): void
    {
        [$user, $club] = $this->persistUserAndClub();
        $service       = self::getContainer()->get(UserLedgerService::class);

        $credit = $service->append($user, $club, UserLedgerEntryType::SELL_BACK_CREDIT, 80_000, 'plot_sell_back', 'claim-9');
        $this->cleanup[] = $credit;

        $reversal = $service->append(
            $user,
            $club,
            UserLedgerEntryType::REVERSAL,
            -80_000,
            'reversal',
            (string) $credit->getId(),
        );
        $this->cleanup[] = $reversal;

        self::assertSame(80_000, $credit->getAmountPence(), 'the original entry must be untouched');
        self::assertSame(-80_000, $reversal->getAmountPence());
        self::assertSame(0, $this->userLedgerRepository->getCurrentBalance($user));
    }

    /**
     * Deleting a club must not change its owner's balance.
     *
     * The bug this step's migration fixes. While user_ledger.club_id was NOT NULL with a plain
     * FK, both delete paths had to DELETE a club's ledger rows to delete the club at all — and
     * because the balance is a chain of before/after snapshots read from the LATEST surviving
     * row, removing a club that owned the most recent entries stepped its owner's balance back
     * to an earlier figure. Real money vanished from an account whose owner was not being
     * deleted. The FK is now ON DELETE SET NULL, so the database detaches the rows and the chain
     * stays whole.
     */
    public function testDeletingAClubKeepsTheLedgerRowsAndTheBalance(): void
    {
        [$user, $club] = $this->persistUserAndClub();
        $service       = self::getContainer()->get(UserLedgerService::class);

        // Two entries, the SECOND against the club that is about to go — which is the case that
        // used to corrupt the balance.
        $first  = $service->append($user, null, UserLedgerEntryType::STARTING_GRANT, 100_000, 'grant', 'welcome');
        $second = $service->append($user, $club, UserLedgerEntryType::DIVIDEND_DRAW, 60_000, 'test', 'draw-1');

        $this->cleanup[] = $first;
        $this->cleanup[] = $second;

        self::assertSame(160_000, $this->userLedgerRepository->getCurrentBalance($user));

        $clubId = (string) $club->getId();

        // Drop the club exactly as the admin club-only delete does.
        $this->em->remove($this->em->find(Club::class, $clubId));
        $this->em->flush();
        $this->em->clear();

        // Already gone from the cleanup list's perspective.
        $this->cleanup = array_values(array_filter(
            $this->cleanup,
            static fn (object $e): bool => !($e instanceof Club),
        ));

        $rows = $this->userLedgerRepository->findByUser($this->em->find(User::class, (string) $user->getId()));

        self::assertCount(2, $rows, 'both ledger rows must survive the club delete');
        self::assertSame(
            160_000,
            $this->userLedgerRepository->getCurrentBalance($this->em->find(User::class, (string) $user->getId())),
            "the owner's balance must be unchanged by deleting one of their clubs",
        );

        $detached = array_values(array_filter(
            $rows,
            static fn (UserLedger $r): bool => $r->getClub() === null,
        ));

        self::assertCount(2, $detached, 'the club reference should be nulled, not the row deleted');
    }

    /**
     * The Rich List only lists accounts that have actually drawn a dividend.
     *
     * findTopOwnersByBalance() backs the landing page's owner board, and its docblock has always
     * promised "deliberately NOT the same population as every user who owns a club". Once
     * STARTING_GRANT exists, an unfiltered version would put EVERY account on a public board at
     * the grant value — turning a board about achievement into a roster of signups.
     */
    public function testRichListExcludesAccountsThatOnlyHoldAGrant(): void
    {
        [$grantOnlyUser, $grantOnlyClub] = $this->persistUserAndClub();
        [$earnerUser, $earnerClub]       = $this->persistUserAndClub();

        $service = self::getContainer()->get(UserLedgerService::class);

        $this->cleanup[] = $service->append(
            $grantOnlyUser,
            $grantOnlyClub,
            UserLedgerEntryType::STARTING_GRANT,
            900_000_000,
            'grant',
            'welcome',
        );

        $this->cleanup[] = $service->append(
            $earnerUser,
            $earnerClub,
            UserLedgerEntryType::DIVIDEND_DRAW,
            10_000,
            'test',
            'draw-1',
        );

        $listed = array_column($this->userLedgerRepository->findTopOwnersByBalance(50), 'userId');

        self::assertContains(
            (string) $earnerUser->getId(),
            $listed,
            'an account that drew a dividend should be listed',
        );
        self::assertNotContains(
            (string) $grantOnlyUser->getId(),
            $listed,
            'a grant alone must not put an account on the public Rich List — even a huge one',
        );
    }

    /** @return int the club's career-earnings figure as the public leaderboard computes it */
    private function dividendsFor(Club $club): int
    {
        foreach ($this->userLedgerRepository->sumDividendsByClub() as $row) {
            if ($row['clubId'] === (string) $club->getId()) {
                return $row['score'];
            }
        }

        return 0;
    }

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
