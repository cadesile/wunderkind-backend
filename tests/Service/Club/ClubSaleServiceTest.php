<?php

declare(strict_types=1);

namespace App\Tests\Service\Club;

use App\Entity\Club;
use App\Entity\Club\ClubSale;
use App\Entity\User;
use App\Entity\UserLedger;
use App\Enum\Club\ClubSaleStatus;
use App\Enum\UserLedgerEntryType;
use App\Repository\UserLedgerRepository;
use App\Service\Club\ClubSaleService;
use App\Service\Club\ClubSaleValuationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The buyout end to end: credit, scrub, receipt — and above all, exactly once.
 *
 * IDEMPOTENCY IS THE POINT OF THIS FILE. The ordering ClubSaleService uses (credit first, scrub
 * second, not in one transaction) is chosen so that the failure it CAN have is the recoverable
 * one: a player paid whose club still stands. That choice is only safe if re-running the sale
 * pays nothing further, so that is asserted directly rather than reasoned about.
 */
class ClubSaleServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ClubSaleService $sales;

    /** @var object[] */
    private array $cleanup = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em    = self::getContainer()->get(EntityManagerInterface::class);
        $this->sales = self::getContainer()->get(ClubSaleService::class);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->cleanup) as $entity) {
            $ids = $this->em->getClassMetadata($entity::class)->getIdentifierValues($entity);

            if ($ids === []) {
                continue;
            }

            $managed = $this->em->find($entity::class, count($ids) === 1 ? reset($ids) : $ids);

            if ($managed !== null) {
                $this->em->remove($managed);
            }
        }

        $this->cleanup = [];
        $this->em->flush();
        parent::tearDown();
    }

    private function persist(object $entity): object
    {
        $this->em->persist($entity);
        $this->cleanup[] = $entity;

        return $entity;
    }

    /** @return array{0: ClubSale, 1: Club, 2: User} */
    private function authorisedSale(int $agreedPence): array
    {
        $user = new User('sale-' . bin2hex(random_bytes(6)) . '@example.test');
        $user->setPassword('x');
        $this->persist($user);

        $club = new Club('Sold Town FC', $user);
        $club->setBalance(400_000);
        $this->persist($club);
        $this->em->flush();

        $breakdown = self::getContainer()->get(ClubSaleValuationService::class)->breakdown($club);

        $sale = new ClubSale(
            $user,
            (string) $club->getId(),
            $club->getName(),
            (int) $breakdown['estimatePence'],
            $agreedPence,
            $breakdown,
            'Thanks for building Sold Town FC.',
            null,
            'test@admin.local',
        );
        $this->persist($sale);
        $this->em->flush();

        return [$sale, $club, $user];
    }

    public function testItCreditsTheOwnerDeletesTheClubAndRecordsTheReceipt(): void
    {
        [$sale, $club, $user] = $this->authorisedSale(1_234_500);

        $clubId = (string) $club->getId();

        $this->sales->execute($sale);

        self::assertSame(ClubSaleStatus::COMPLETED, $sale->getStatus());
        self::assertNotNull($sale->getCompletedAt());
        self::assertNotNull($sale->getScrubCountsJson());

        $entry = $sale->getLedgerEntry();
        self::assertNotNull($entry, 'the receipt must name the entry it paid');
        self::assertSame(UserLedgerEntryType::CLUB_SALE, $entry->getType());
        self::assertSame(1_234_500, $entry->getAmountPence());

        // ATTRIBUTED TO NO CLUB, deliberately: the credit is an account-level fact that has to
        // outlive the club, and user_ledger.club_id would have been nulled moments later anyway.
        self::assertNull($entry->getClub());

        // The operator's note is what the player reads on their own ledger.
        self::assertSame('Thanks for building Sold Town FC.', $entry->getDescription());

        $this->em->clear();
        self::assertNull($this->em->find(Club::class, $clubId));

        // The money actually landed on the account.
        self::assertSame(
            1_234_500,
            self::getContainer()->get(UserLedgerRepository::class)
                ->getCurrentBalance($this->em->find(User::class, $user->getId())),
        );
    }

    public function testRunningItTwicePaysOnlyOnce(): void
    {
        // The recovery path. A crash between the credit and the scrub leaves a PENDING sale whose
        // money has already moved; the retry has to finish the job without paying again.
        [$sale, , $user] = $this->authorisedSale(900_000);

        $this->sales->execute($sale);
        $firstEntryId = $sale->getLedgerEntry()?->getId();

        // Second delivery of the same message. execute() returns early on a COMPLETED sale, so
        // force the harder case too: a PENDING row whose credit already exists.
        $this->sales->execute($sale);

        self::assertSame($firstEntryId, $sale->getLedgerEntry()?->getId());

        $rows = $this->em->getRepository(UserLedger::class)->findBy([
            'user' => $user,
            'type' => UserLedgerEntryType::CLUB_SALE,
        ]);

        self::assertCount(1, $rows, 'a second run must not write a second credit');
        self::assertSame(
            900_000,
            self::getContainer()->get(UserLedgerRepository::class)->getCurrentBalance($user),
        );
    }

    public function testAPendingSaleWhoseCreditAlreadyLandedIsNotPaidTwice(): void
    {
        // The genuine crash-between-steps shape: the credit committed, the scrub did not, and the
        // row is still PENDING. Reconstructed by crediting through the service's own idempotency
        // key before running it, which is exactly the state a killed worker leaves behind.
        [$sale, $club, $user] = $this->authorisedSale(750_000);

        $pre = self::getContainer()->get(\App\Service\UserLedgerService::class)->append(
            $user,
            null,
            UserLedgerEntryType::CLUB_SALE,
            750_000,
            ClubSaleService::SOURCE_KIND,
            (string) $sale->getId(),
            'Thanks for building Sold Town FC.',
        );

        $this->sales->execute($sale);

        self::assertSame(ClubSaleStatus::COMPLETED, $sale->getStatus());
        self::assertSame($pre->getId(), $sale->getLedgerEntry()?->getId(), 'it must adopt the existing credit');
        self::assertSame(
            750_000,
            self::getContainer()->get(UserLedgerRepository::class)->getCurrentBalance($user),
            'and not pay a second time',
        );

        $this->em->clear();
        self::assertNull($this->em->find(Club::class, (string) $club->getId()));
    }

    public function testASaleWhoseClubIsAlreadyGoneStillFinishesTheBookkeeping(): void
    {
        // The other half of the recovery: the scrub ran, the process died before step 3. The sale
        // must be completable rather than stuck PENDING forever.
        [$sale, $club] = $this->authorisedSale(100_000);

        self::getContainer()->get(\App\Service\Club\ClubScrubService::class)->scrub($club);

        $this->sales->execute($sale);

        self::assertSame(ClubSaleStatus::COMPLETED, $sale->getStatus());
        self::assertArrayHasKey('note_already_absent', (array) $sale->getScrubCountsJson());
    }

    public function testAZeroFigureScrubsWithoutWritingAVisibleCredit(): void
    {
        // An operator clearing a worthless or test club should not be forced to put a £0 row into
        // somebody's visible payment history.
        [$sale, $club, $user] = $this->authorisedSale(0);

        $this->sales->execute($sale);

        self::assertSame(ClubSaleStatus::COMPLETED, $sale->getStatus());
        self::assertNull($sale->getLedgerEntry());
        self::assertCount(0, $this->em->getRepository(UserLedger::class)->findBy([
            'user' => $user,
            'type' => UserLedgerEntryType::CLUB_SALE,
        ]));

        $this->em->clear();
        self::assertNull($this->em->find(Club::class, (string) $club->getId()));
    }

    public function testANegativeFigureIsRefusedBeforeAnythingIsTouched(): void
    {
        // CLUB_SALE is absent from isDebit(), so UserLedgerService::append() would happily write
        // a negative buyout — a debit dressed as a reward. Refused here instead.
        [$sale, $club] = $this->authorisedSale(-1);

        $this->expectException(\InvalidArgumentException::class);

        try {
            $this->sales->execute($sale);
        } finally {
            self::assertNotNull(
                $this->em->find(Club::class, (string) $club->getId()),
                'the club must still be standing after a refusal',
            );
        }
    }
}
