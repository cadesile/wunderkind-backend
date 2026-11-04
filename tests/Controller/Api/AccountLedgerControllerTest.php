<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Club;
use App\Entity\User;
use App\Enum\UserLedgerEntryType;
use App\Service\UserLedgerService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\UuidV7;

/**
 * GET /api/account/ledger.
 *
 * The assertions that earn their keep here are the SHAPE ones. This endpoint's keys deliberately
 * differ from the inbound sync DTO's (`type`/`amountPence` versus `category`/`amount`), and the
 * two are easy to "harmonise" by accident — so the absent keys are asserted as explicitly as the
 * present ones. Without that, a well-meaning refactor toward LedgerEntrySyncDto would break
 * every client silently and no test would notice.
 */
class AccountLedgerControllerTest extends WebTestCase
{
    private ?UuidV7 $userId = null;

    /**
     * Per-test prefix for every sourceRef below.
     *
     * NOT cosmetic. UserLedgerService::append() is idempotent on (sourceKind, sourceRef,
     * type) and that guard is NOT scoped by user — a shared literal ref makes the second
     * test to use it receive the FIRST test's entry and write nothing for its own account,
     * which shows up as an inexplicably empty ledger rather than as a collision.
     */
    private string $refPrefix = '';

    /**
     * Purge every row this test file created, because the test database is SHARED and nothing
     * rolls it back (there is no DAMA transaction wrapper here).
     *
     * NOT HYGIENE — correctness for other tests. UserLedgerService::append() is idempotent on
     * (sourceKind, sourceRef, type) and that guard is NOT scoped by user, so a leaked row from
     * here silently makes a LATER test's append() a no-op: it receives this file's entry and
     * writes nothing for its own club. That surfaces as an unrelated test failing on an
     * inexplicable zero, only in a full-directory run, and it already happened once.
     */
    protected function tearDown(): void
    {
        $connection = self::getContainer()->get(\Doctrine\DBAL\Connection::class);

        $connection->executeStatement(
            'DELETE FROM user_ledger WHERE user_id IN (SELECT id FROM "user" WHERE email LIKE \'ledger-%@example.com\')',
        );
        // Leaderboard entries first. A club of this file's leaking into earlier runs was enough
        // for a leaderboard generation elsewhere in the suite to write rows against it, and the
        // FK then refuses the delete — the second-order cost of having leaked at all.
        $connection->executeStatement(
            'DELETE FROM leaderboard_entry WHERE club_id IN'
            . ' (SELECT c.id FROM club c JOIN "user" u ON u.id = c.user_id'
            . '   WHERE u.email LIKE \'ledger-%@example.com\')',
        );
        $connection->executeStatement(
            'DELETE FROM club WHERE user_id IN (SELECT id FROM "user" WHERE email LIKE \'ledger-%@example.com\')',
        );
        $connection->executeStatement('DELETE FROM "user" WHERE email LIKE \'ledger-%@example.com\'');

        parent::tearDown();
    }

    public function testUnauthenticatedIsRejected(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/account/ledger');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testReturnsEntityShapedKeysAndNotTheSyncShape(): void
    {
        [$user, $club] = $this->seedUserWithClub();

        $ledger = self::getContainer()->get(UserLedgerService::class);
        $ledger->append($user, $club, UserLedgerEntryType::DIVIDEND_DRAW, 25_000, 'test', $this->refPrefix . '-draw-1');
        $ledger->append($user, null, UserLedgerEntryType::LAND_PURCHASE, -10_000, 'test', $this->refPrefix . '-land-1');

        $data = $this->get('/api/account/ledger');

        $this->assertSame(15_000, $data['balancePence'], '25,000 credited then 10,000 charged');
        $this->assertCount(2, $data['entries']);
        $this->assertNull($data['nextCursor'], 'two entries under the default limit is one page');

        $entry = $data['entries'][0];

        // Present, and named the way docs/api/user-ledger.md's addendum promised.
        $this->assertArrayHasKey('type', $entry);
        $this->assertArrayHasKey('amountPence', $entry);
        $this->assertArrayHasKey('balanceAfterPence', $entry);
        $this->assertArrayHasKey('club', $entry);
        $this->assertArrayHasKey('occurredAt', $entry);

        // ABSENT, and must stay absent — these are the INBOUND sync DTO's names.
        $this->assertArrayNotHasKey('category', $entry, 'category is the inbound sync key, not this one');
        $this->assertArrayNotHasKey('amount', $entry, 'a bare amount means a row count elsewhere in this API');
    }

    public function testNewestFirstAndPurchasesAreNegative(): void
    {
        [$user, $club] = $this->seedUserWithClub();

        $ledger = self::getContainer()->get(UserLedgerService::class);
        $ledger->append($user, $club, UserLedgerEntryType::DIVIDEND_DRAW, 40_000, 'test', $this->refPrefix . '-draw-1');
        $ledger->append($user, null, UserLedgerEntryType::LAND_PURCHASE, -5_000, 'test', $this->refPrefix . '-land-1');

        $data = $this->get('/api/account/ledger');

        $this->assertSame('land_purchase', $data['entries'][0]['type'], 'newest first');
        $this->assertSame(-5_000, $data['entries'][0]['amountPence'], 'a debit stays signed in the response');
        $this->assertSame('Land purchase', $data['entries'][0]['label']);

        // The headline balance is the chain head in this same ordering, so it must equal the
        // top row's balanceAfterPence. If these ever disagree the list is sorted on a different
        // column from getCurrentBalance() and the screen will contradict itself.
        $this->assertSame($data['entries'][0]['balanceAfterPence'], $data['balancePence']);
    }

    public function testAccountLevelEntrySerialisesWithNullClub(): void
    {
        [$user] = $this->seedUserWithClub();

        self::getContainer()->get(UserLedgerService::class)
            ->append($user, null, UserLedgerEntryType::STARTING_GRANT, 500_000, 'test', $this->refPrefix . '-grant-1');

        $data = $this->get('/api/account/ledger');

        $this->assertNull($data['entries'][0]['club'], 'a grant belongs to the account, not a club');
    }

    public function testClubIsRenderedWhenAttached(): void
    {
        [$user, $club] = $this->seedUserWithClub();

        self::getContainer()->get(UserLedgerService::class)
            ->append($user, $club, UserLedgerEntryType::DIVIDEND_DRAW, 1_000, 'test', $this->refPrefix . '-draw-1');

        $data = $this->get('/api/account/ledger');

        $this->assertSame('Ledger FC', $data['entries'][0]['club']['name']);
    }

    public function testEmptyLedgerReturnsZeroBalanceAndNoEntries(): void
    {
        $this->seedUserWithClub();

        $data = $this->get('/api/account/ledger');

        $this->assertSame(0, $data['balancePence'], 'no entries means zero, not null');
        $this->assertSame([], $data['entries']);
        $this->assertNull($data['nextCursor']);
    }

    public function testCursorPagesWithoutRepeatingOrSkippingAnEntry(): void
    {
        [$user, $club] = $this->seedUserWithClub();

        $ledger = self::getContainer()->get(UserLedgerService::class);
        for ($i = 0; $i < 5; $i++) {
            $ledger->append($user, $club, UserLedgerEntryType::DIVIDEND_DRAW, 1_000, 'test', $this->refPrefix . '-draw-' . $i);
        }

        $first = $this->get('/api/account/ledger?limit=2');
        $this->assertCount(2, $first['entries']);
        $this->assertNotNull($first['nextCursor'], 'five entries at limit 2 has a next page');

        $second = $this->get('/api/account/ledger?limit=2&cursor=' . urlencode($first['nextCursor']));
        $this->assertCount(2, $second['entries']);

        $third = $this->get('/api/account/ledger?limit=2&cursor=' . urlencode($second['nextCursor']));
        $this->assertCount(1, $third['entries']);
        $this->assertNull($third['nextCursor'], 'the short page is the last one');

        $seen = array_merge(
            array_column($first['entries'], 'id'),
            array_column($second['entries'], 'id'),
            array_column($third['entries'], 'id'),
        );

        $this->assertCount(5, $seen);
        $this->assertCount(5, array_unique($seen), 'no entry appears on two pages');
    }

    public function testMalformedCursorIs400AndNotASilentRestart(): void
    {
        $this->seedUserWithClub();

        $client = $this->authenticatedClient();
        $client->request('GET', '/api/account/ledger?cursor=not-a-real-cursor');

        // A silently-ignored cursor would restart from the head, and a paging client would
        // loop over page 1 for ever with nothing to see.
        $this->assertResponseStatusCodeSame(400);
        $this->assertSame('invalid_cursor', json_decode($client->getResponse()->getContent(), true)['error']);
    }

    public function testLimitIsClampedToTheMaximum(): void
    {
        [$user, $club] = $this->seedUserWithClub();

        $ledger = self::getContainer()->get(UserLedgerService::class);
        for ($i = 0; $i < 3; $i++) {
            $ledger->append($user, $club, UserLedgerEntryType::DIVIDEND_DRAW, 1_000, 'test', $this->refPrefix . '-draw-' . $i);
        }

        // Well over MAX_LIMIT, and a negative — neither may reach setMaxResults().
        $this->assertCount(3, $this->get('/api/account/ledger?limit=100000')['entries']);
        $this->assertCount(1, $this->get('/api/account/ledger?limit=-5')['entries']);
    }

    public function testResponseIsNotCacheable(): void
    {
        $this->seedUserWithClub();

        $client = $this->authenticatedClient();
        $client->request('GET', '/api/account/ledger');

        $cacheControl = $client->getResponse()->headers->get('Cache-Control');

        $this->assertStringContainsString('private', (string) $cacheControl);
        $this->assertStringNotContainsString('public', (string) $cacheControl);
    }

    public function testOneUsersLedgerIsNeverVisibleToAnother(): void
    {
        [$userA, $clubA] = $this->seedUserWithClub();

        self::getContainer()->get(UserLedgerService::class)
            ->append($userA, $clubA, UserLedgerEntryType::DIVIDEND_DRAW, 99_000, 'test', $this->refPrefix . '-draw-a');

        // A second account, which becomes the authenticated one.
        [$userB] = $this->seedUserWithClub();

        $data = $this->get('/api/account/ledger');

        $this->assertSame(0, $data['balancePence']);
        $this->assertSame([], $data['entries'], "another account's entries must not leak");
        $this->assertNotSame((string) $userA->getId(), (string) $userB->getId());
    }

    /**
     * A fresh client per request, because loginUser() authenticates exactly ONE request against
     * this stateless JWT firewall — the same constraint AdminMessageControllerTest documents.
     * Rebooting swaps the EntityManager, so only ids survive a request boundary.
     */
    private function authenticatedClient(): KernelBrowser
    {
        self::ensureKernelShutdown();
        $client = static::createClient();

        $user = self::getContainer()->get(EntityManagerInterface::class)->find(User::class, $this->userId);
        $client->loginUser($user, 'api');

        return $client;
    }

    /** @return array<string, mixed> */
    private function get(string $uri): array
    {
        $client = $this->authenticatedClient();
        $client->request('GET', $uri);

        $this->assertResponseIsSuccessful();

        return json_decode($client->getResponse()->getContent(), true);
    }

    /** @return array{0: User, 1: Club} */
    private function seedUserWithClub(): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $user = new User('ledger-' . uniqid() . '@example.com');
        $user->setPassword('x');
        $user->setRoles([User::ROLE_CLUB]);

        $club = new Club('Ledger FC', $user);

        $em->persist($user);
        $em->persist($club);
        $em->flush();

        $this->userId    = $user->getId();
        $this->refPrefix = uniqid('t', true);

        return [$user, $club];
    }
}
