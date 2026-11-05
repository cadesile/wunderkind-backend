<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\User;
use App\Enum\UserLedgerEntryType;
use App\Repository\UserLedgerRepository;
use App\Service\UserLedgerService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `findBalancesByUser()` must agree with `getCurrentBalance()`, row for row.
 *
 * WHY THIS IS WORTH A TEST OF ITS OWN. The admin's adjustment form renders every user's balance
 * into the page from the bulk query so its before/after preview needs no round trip, while the
 * figure actually written comes from `getCurrentBalance()` inside `append()`. If the two ever
 * disagreed, an operator would be shown one opening balance and commit against another — and
 * nothing would error, because both answers are individually well-formed.
 *
 * The tie-break is the specific hazard: both order by `recorded_at DESC, id DESC`, and several
 * rows written in the same second is the normal case, not an edge case.
 */
class UserLedgerBalancesByUserTest extends KernelTestCase
{
    private const EMAIL_PREFIX = 'ledger-balances-';

    private EntityManagerInterface $em;
    private Connection $connection;
    private UserLedgerRepository $repository;
    private UserLedgerService $ledgerService;

    protected function setUp(): void
    {
        self::bootKernel();

        $c = self::getContainer();

        $this->em            = $c->get(EntityManagerInterface::class);
        $this->connection    = $c->get(Connection::class);
        $this->repository    = $c->get(UserLedgerRepository::class);
        $this->ledgerService = $c->get(UserLedgerService::class);

        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->purge();

        parent::tearDown();
    }

    /**
     * Several users, several chained rows each, written the way the app writes them.
     *
     * Asserted against `getCurrentBalance()` rather than against hardcoded totals, because the
     * agreement between the two methods IS the property — a shared bug in the arithmetic would
     * be invisible to a hardcoded expectation but is not what this guards.
     */
    public function testItAgreesWithGetCurrentBalanceForEveryUser(): void
    {
        $alice = $this->user();
        $bob   = $this->user();

        $this->credit($alice, 50_000);
        $this->credit($alice, 2_500);
        $this->credit($alice, -1_000);
        $this->credit($bob, 7_000);

        $balances = $this->repository->findBalancesByUser();

        self::assertSame(
            $this->repository->getCurrentBalance($alice),
            $balances[(string) $alice->getId()],
            'the bulk query must match the per-user read the service actually uses',
        );
        self::assertSame(
            $this->repository->getCurrentBalance($bob),
            $balances[(string) $bob->getId()],
        );

        // And the arithmetic is what it should be, so a shared bug is not silently blessed.
        self::assertSame(51_500, $balances[(string) $alice->getId()]);
        self::assertSame(7_000, $balances[(string) $bob->getId()]);
    }

    /**
     * Rows recorded within the same second still resolve to the newest.
     *
     * `recorded_at` is second-resolution here, so a run of writes ties on it constantly and the
     * `id DESC` tie-break is what decides. Both methods must break it the same way or the
     * preview and the write disagree exactly when an operator is working quickly.
     */
    public function testTheTieBreakMatchesWhenRowsShareATimestamp(): void
    {
        $user = $this->user();

        for ($i = 1; $i <= 5; $i++) {
            $this->credit($user, 1_000);
        }

        self::assertSame(
            $this->repository->getCurrentBalance($user),
            $this->repository->findBalancesByUser()[(string) $user->getId()],
        );
        self::assertSame(5_000, $this->repository->getCurrentBalance($user));
    }

    /**
     * A user with no ledger rows is absent, not present as zero.
     *
     * The caller defaults a missing key to 0, which is the same answer `getCurrentBalance()`
     * gives for such a user — so this documents the shape the template relies on.
     */
    public function testAUserWithNoEntriesIsAbsentRatherThanZero(): void
    {
        $user = $this->user();

        self::assertArrayNotHasKey((string) $user->getId(), $this->repository->findBalancesByUser());
        self::assertSame(0, $this->repository->getCurrentBalance($user));
    }

    private function credit(User $user, int $amountPence): void
    {
        $this->ledgerService->append(
            $user,
            null,
            // A positive type, so either sign is accepted and the chain is what is under test.
            UserLedgerEntryType::CUSTOM_INCOME,
            $amountPence,
            'test_balances',
            bin2hex(random_bytes(12)),
            'balance fixture',
        );
    }

    private function user(): User
    {
        $user = new User(self::EMAIL_PREFIX . bin2hex(random_bytes(5)) . '@test.local');
        $user->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function purge(): void
    {
        $like = self::EMAIL_PREFIX . '%@test.local';

        $this->connection->executeStatement(
            'DELETE FROM user_ledger WHERE user_id IN (SELECT id FROM "user" WHERE email LIKE ?)',
            [$like],
        );
        $this->connection->executeStatement('DELETE FROM "user" WHERE email LIKE ?', [$like]);
    }
}
