<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Admin;
use App\Entity\User;
use App\Entity\UserLedger;
use App\Enum\UserLedgerEntryType;
use App\Service\UserLedgerService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

/**
 * The admin "New Adjustment" form — the only place a human writes a ledger row.
 *
 * THIS MOVES REAL MONEY, so the assertions that matter are not "does it render". They are:
 *
 *  1. the stored balances come from the SERVICE and cannot be influenced by the form, however
 *     the request is crafted;
 *  2. resubmitting one rendered form writes once, not twice — the double-click case;
 *  3. every refusal writes nothing at all.
 *
 * The read-only CRUD must also stay read-only: there is a test here asserting NEW and EDIT are
 * still disabled, because "why isn't this just an EasyAdmin form?" is the first thing anyone
 * will ask, and the answer is that it would take balances from form input and bypass the
 * per-user advisory lock.
 */
class UserLedgerAdjustmentTest extends WebTestCase
{
    private const ADMIN_EMAIL  = 'user-ledger-adjustment-admin@example.com';
    private const EMAIL_PREFIX = 'ledger-adj-';
    /** Opening balance seeded through the service, so it is a real chained row. */
    private const OPENING_PENCE = 50_000;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $connection;
    private string $userId = '';

    protected function setUp(): void
    {
        $this->client = static::createClient();

        $this->em         = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = self::getContainer()->get(Connection::class);

        $this->purge();
        $this->loginAsAdmin($this->client);
        $this->userId = $this->seedUserWithBalance();
    }

    protected function tearDown(): void
    {
        $this->purge();

        parent::tearDown();
    }

    // ───────────────────────── the CRUD stays read-only ─────────────────────────

    public function testTheIndexRendersAndOffersTheAdjustmentButton(): void
    {
        $crawler = $this->client->request('GET', '/admin/user-ledger');

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(
            0,
            $crawler->filter('a[href*="admin_user_ledger_create_adjustment"]')->count(),
            'the index should link to the adjustment form through /admin?routeName=',
        );
    }

    /**
     * NEW and EDIT must stay disabled.
     *
     * The guard against someone "simplifying" this feature into an EasyAdmin form: that would
     * map balanceBefore from form input and bypass the advisory lock append() holds while it
     * reads the real balance, so two admins on one account would fork the chain.
     */
    public function testEasyAdminCreateAndEditRemainDisabled(): void
    {
        $this->client->request('GET', '/admin/user-ledger/new');
        self::assertResponseStatusCodeSame(403);

        $entryId = (string) $this->anyEntryId();
        $this->client->request('GET', "/admin/user-ledger/{$entryId}/edit");
        self::assertResponseStatusCodeSame(403);
    }

    public function testTheFormRendersThroughTheEasyAdminEntryPoint(): void
    {
        $crawler = $this->requestForm();

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('#ledger-adjustment-form')->count());
    }

    /**
     * The balance fields cannot be posted at all.
     *
     * They carry no `name`, which is a stronger guarantee than `disabled` or `readonly`: there
     * is no path by which a browser could submit them, so the service is necessarily the only
     * source of those numbers.
     */
    public function testTheBalanceFieldsCarryNoNameSoTheyCannotBeSubmitted(): void
    {
        $crawler = $this->requestForm();

        self::assertSame(1, $crawler->filter('#balanceBeforeDisplay')->count());
        self::assertSame(1, $crawler->filter('#balanceAfterDisplay')->count());
        self::assertSame(
            0,
            $crawler->filter('[name="balanceBeforePence"], [name="balanceAfterPence"]')->count(),
            'nothing on this form may post a balance',
        );
    }

    // ───────────────────────── writing ─────────────────────────

    public function testAValidAdjustmentWritesOneAccountLevelRow(): void
    {
        $this->submit(['amountPence' => '2500', 'description' => 'Goodwill credit']);

        self::assertResponseRedirects();

        $row = $this->onlyAdjustment();
        self::assertSame(UserLedgerEntryType::CUSTOM_INCOME, $row->getType());
        self::assertSame(2500, $row->getAmountPence());
        self::assertNull($row->getClub(), 'an adjustment is an account fact, never club-scoped');
        self::assertSame('Goodwill credit', $row->getDescription());
    }

    /**
     * THE ASSERTION THIS WHOLE FEATURE TURNS ON.
     *
     * Balances are posted deliberately, with wrong values, to prove they are ignored — the form
     * does not render those names, but a crafted request can still send them, and the server
     * must not care. The stored figures must be the service's: the seeded opening balance, and
     * that plus the amount.
     */
    public function testTheStoredBalancesComeFromTheServiceNotTheRequest(): void
    {
        $this->submit([
            'amountPence'        => '2500',
            'description'        => 'Balances must be ignored',
            'balanceBeforePence' => '999999',
            'balanceAfterPence'  => '888888',
        ]);

        self::assertResponseRedirects();

        $row = $this->onlyAdjustment();
        self::assertSame(self::OPENING_PENCE, $row->getBalanceBeforePence());
        self::assertSame(self::OPENING_PENCE + 2500, $row->getBalanceAfterPence());
    }

    /** Either sign — the reason custom_income is deliberately not a debit type. */
    public function testANegativeAdjustmentDeductsRatherThanThrowing(): void
    {
        $this->submit(['amountPence' => '-2500', 'description' => 'Correcting a duplicate credit']);

        self::assertResponseRedirects();

        $row = $this->onlyAdjustment();
        self::assertSame(-2500, $row->getAmountPence());
        self::assertSame(self::OPENING_PENCE - 2500, $row->getBalanceAfterPence());
    }

    public function testTheRowIdentifiesTheActingAdminAndFitsTheColumns(): void
    {
        $this->submit(['amountPence' => '100', 'description' => 'Attribution check']);
        self::assertResponseRedirects();

        $row      = $this->onlyAdjustment();
        $adminId  = (string) $this->adminEntity()->getId();

        self::assertSame('admin_adjustment', $row->getSourceKind());
        self::assertLessThanOrEqual(24, strlen((string) $row->getSourceKind()), 'source_kind is varchar(24)');

        self::assertStringStartsWith($adminId . ':', (string) $row->getSourceRef());
        self::assertLessThanOrEqual(
            64,
            strlen((string) $row->getSourceRef()),
            'source_ref is varchar(64) — the admin uuid plus the token must fit',
        );
    }

    // ───────────────────────── resubmission ─────────────────────────

    /**
     * Resubmitting one rendered form credits once.
     *
     * The double-click / F5 case, and the single most valuable test here: the submission token
     * is minted when the form is rendered, so the second POST lands on append()'s idempotency
     * guard and writes nothing. A token generated per-request instead would credit twice.
     */
    public function testResubmittingTheSameFormWritesOnlyOnce(): void
    {
        $token = $this->freshSubmissionToken();

        $this->submit(['amountPence' => '2500', 'description' => 'Double click'], $token);
        self::assertResponseRedirects();

        $this->submit(['amountPence' => '2500', 'description' => 'Double click'], $token);
        self::assertResponseRedirects();

        self::assertSame(1, $this->adjustmentCount(), 'the second submission must not credit again');
    }

    /**
     * Going back, changing the amount and resubmitting must not look like success.
     *
     * The dangerous shape of the idempotency hit: the operator believes the new figure landed
     * while the original stands. The flash has to be a warning that names what was actually
     * recorded.
     */
    public function testResubmittingWithAChangedAmountWarnsAndKeepsTheOriginal(): void
    {
        $token = $this->freshSubmissionToken();

        $this->submit(['amountPence' => '2500', 'description' => 'First'], $token);
        self::assertResponseRedirects();
        // Follow it so the first success flash is consumed. Flashes survive in the session until
        // something renders them, and peeking without this would see the FIRST request's success
        // and report a pass for the wrong reason.
        $this->client->followRedirect();

        $this->submit(['amountPence' => '9999', 'description' => 'Second'], $token);
        self::assertResponseRedirects();

        self::assertSame(1, $this->adjustmentCount());
        self::assertSame(2500, $this->onlyAdjustment()->getAmountPence(), 'the original amount stands');

        $flashes = $this->client->getRequest()->getSession()->getFlashBag()->peekAll();
        self::assertArrayNotHasKey('success', $flashes, 'a replay must never report success');
        self::assertArrayHasKey('warning', $flashes);
        self::assertStringContainsString('already submitted', $flashes['warning'][0]);
        self::assertStringContainsString(
            '£25.00',
            $flashes['warning'][0],
            'the warning must name what was ACTUALLY recorded, not what was just typed',
        );
    }

    /** A fresh form is a fresh token, so a genuine second adjustment still works. */
    public function testAFreshFormCanRecordASecondAdjustment(): void
    {
        $this->submit(['amountPence' => '2500', 'description' => 'First'], $this->freshSubmissionToken());
        self::assertResponseRedirects();

        $this->submit(['amountPence' => '2500', 'description' => 'Second'], $this->freshSubmissionToken());
        self::assertResponseRedirects();

        self::assertSame(2, $this->adjustmentCount());
    }

    // ───────────────────────── refusals ─────────────────────────

    /** @param array<string, string> $overrides */
    #[DataProvider('invalidSubmissions')]
    public function testInvalidInputIsRefusedAndWritesNothing(array $overrides, string $expectedError): void
    {
        $this->submit(array_merge(['amountPence' => '2500', 'description' => 'Valid note'], $overrides));

        // Re-renders with errors rather than redirecting, so the operator keeps their input.
        self::assertResponseIsSuccessful();
        self::assertStringContainsString($expectedError, (string) $this->client->getResponse()->getContent());
        self::assertSame(0, $this->adjustmentCount());
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function invalidSubmissions(): iterable
    {
        yield 'no user'            => [['userId' => ''], 'Choose a user'];
        yield 'unknown user'       => [['userId' => '0199bb11-2233-7444-8555-666677778888'], 'Choose a user'];
        yield 'malformed user id'  => [['userId' => 'not-a-uuid'], 'Choose a user'];
        yield 'empty amount'       => [['amountPence' => ''], 'Enter an amount'];
        yield 'zero amount'        => [['amountPence' => '0'], 'moves nothing'];
        yield 'decimal amount'     => [['amountPence' => '25.50'], 'whole pence'];
        yield 'comma amount'       => [['amountPence' => '1,000'], 'whole pence'];
        yield 'words for amount'   => [['amountPence' => 'lots'], 'whole pence'];
        yield 'amount over the cap' => [['amountPence' => '99999999999'], 'cap for a single adjustment'];
        yield 'empty description'  => [['description' => '   '], 'Describe the adjustment'];
    }

    public function testADescriptionOverTheColumnWidthIsRefused(): void
    {
        $this->submit(['amountPence' => '2500', 'description' => str_repeat('a', 256)]);

        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->adjustmentCount());
    }

    public function testABadCsrfTokenWritesNothing(): void
    {
        $this->postForm([
            'userId'          => $this->userId,
            'amountPence'     => '2500',
            'description'     => 'Should not land',
            'submissionToken' => $this->freshSubmissionToken(),
            '_token'          => 'not-a-real-token',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->adjustmentCount());
    }

    // ───────────────────────── helpers ─────────────────────────

    private function requestForm(): \Symfony\Component\DomCrawler\Crawler
    {
        return $this->client->request('GET', '/admin', [
            'routeName' => 'admin_user_ledger_create_adjustment',
        ]);
    }

    /** The token the rendered form carries, which is what a real browser would post back. */
    private function freshSubmissionToken(): string
    {
        $crawler = $this->requestForm();

        return (string) $crawler->filter('input[name="submissionToken"]')->attr('value');
    }

    /** @param array<string, string> $overrides */
    private function submit(array $overrides, ?string $submissionToken = null): void
    {
        $this->postForm(array_merge(
            [
                'userId'          => $this->userId,
                'submissionToken' => $submissionToken ?? $this->freshSubmissionToken(),
                '_token'          => $this->csrfToken('user_ledger_create_adjustment'),
            ],
            $overrides,
        ));
    }

    /** @param array<string, string> $parameters */
    private function postForm(array $parameters): void
    {
        if (!isset($parameters['_token'])) {
            $parameters['_token'] = $this->csrfToken('user_ledger_create_adjustment');
        }

        $this->client->request('POST', '/admin?routeName=admin_user_ledger_create_adjustment', $parameters);
    }

    private function onlyAdjustment(): UserLedger
    {
        $rows = $this->em->getRepository(UserLedger::class)
            ->findBy(['type' => UserLedgerEntryType::CUSTOM_INCOME]);

        self::assertCount(1, $rows, 'expected exactly one adjustment row');

        return $rows[0];
    }

    private function adjustmentCount(): int
    {
        $this->em->clear();

        return (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM user_ledger WHERE type = 'custom_income'",
        );
    }

    private function anyEntryId(): string
    {
        return (string) $this->connection->fetchOne('SELECT id FROM user_ledger LIMIT 1');
    }

    /**
     * A user with a real, service-written opening balance.
     *
     * Written through UserLedgerService rather than inserted, so the chain the adjustment
     * extends is genuine and balanceBefore has something true to be read from.
     */
    private function seedUserWithBalance(): string
    {
        $user = new User(self::EMAIL_PREFIX . bin2hex(random_bytes(5)) . '@test.local');
        $user->setPassword('x');
        $user->setRoles([User::ROLE_CLUB]);
        $this->em->persist($user);
        $this->em->flush();

        self::getContainer()->get(UserLedgerService::class)->append(
            $user,
            null,
            UserLedgerEntryType::STARTING_GRANT,
            self::OPENING_PENCE,
            'test',
            'ledger-adj-' . bin2hex(random_bytes(5)),
        );

        return (string) $user->getId();
    }

    private function adminEntity(): Admin
    {
        return $this->em->getRepository(Admin::class)->findOneBy(['email' => self::ADMIN_EMAIL]);
    }

    private function loginAsAdmin(KernelBrowser $client): void
    {
        $client->disableReboot();

        $admin = $this->em->getRepository(Admin::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        if ($admin === null) {
            $admin = new Admin(self::ADMIN_EMAIL);
            $admin->setPassword('not-used-for-login-here');
            $this->em->persist($admin);
            $this->em->flush();
        }

        $client->loginUser($admin, 'admin');
    }

    /**
     * A token the controller will accept.
     *
     * Reading one from the token manager needs an active request and session, and loginUser()
     * performs no HTTP request — so the session is seeded directly. CsrfTokenManager falls back
     * to a raw comparison for a value that is not in `x.y.z` form, which is why a plain string
     * works. Same trick as LocationAdminTest.
     */
    private function csrfToken(string $tokenId): string
    {
        $value = 'test-csrf-token-' . bin2hex(random_bytes(8));

        $session  = $this->client->getContainer()->get('session.factory')->createSession();
        $existing = $this->client->getCookieJar()->get($session->getName());

        if ($existing !== null) {
            $session->setId($existing->getValue());
        }

        $session->start();
        $session->set("_csrf/{$tokenId}", $value);
        $session->save();

        $this->client->getCookieJar()->set(new Cookie($session->getName(), $session->getId()));

        return $value;
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
