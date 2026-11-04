<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\StarterConfig;
use App\Entity\User;
use App\Enum\UserLedgerEntryType;
use App\Repository\StarterConfigRepository;
use App\Repository\UserLedgerRepository;
use App\Service\StartingGrantService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The account's opening credit.
 *
 * Two properties carry the weight here. The amount must be DETERMINISTIC, because it becomes
 * real money in a ledger that support has to be able to explain months later. And the grant
 * must be IDEMPOTENT, because the endpoint it hangs off (POST /api/owner-avatar) is a
 * partial-update call the client makes repeatedly — a "first save" heuristic would pay out
 * again every time a player renamed themselves.
 */
class StartingGrantServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private StartingGrantService $grants;
    private StarterConfigRepository $configRepository;
    private UserLedgerRepository $ledgerRepository;

    /** @var array{min: int, max: int}|null */
    private ?array $originalRange = null;

    protected function setUp(): void
    {
        self::bootKernel();

        $c = self::getContainer();

        $this->em               = $c->get(EntityManagerInterface::class);
        $this->connection       = $c->get(Connection::class);
        $this->grants           = $c->get(StartingGrantService::class);
        $this->configRepository = $c->get(StarterConfigRepository::class);
        $this->ledgerRepository = $c->get(UserLedgerRepository::class);

        $config              = $this->configRepository->getConfig();
        $this->originalRange = [
            'min' => $config->getUserStartingBalanceMinPence(),
            'max' => $config->getUserStartingBalanceMaxPence(),
        ];

        $this->purge();
    }

    protected function tearDown(): void
    {
        // starter_config is a singleton shared by the whole suite — restore it, or every later
        // test sees whatever range the last case here happened to set.
        if ($this->originalRange !== null) {
            $this->configRepository->getConfig()
                ->setUserStartingBalanceRangePence($this->originalRange['min'], $this->originalRange['max']);
            $this->em->flush();
        }

        $this->purge();

        parent::tearDown();
    }

    // ───────────────────────── the range itself ─────────────────────────

    public function testTheDefaultRangeMatchesWhatTheLandStepWasBuiltAgainst(): void
    {
        $config = new StarterConfig();

        // £5m-£6m. The prototype's land step assumed this, so a changed default would silently
        // make most towns unaffordable at account creation.
        $this->assertSame(500_000_000, $config->getUserStartingBalanceMinPence());
        $this->assertSame(600_000_000, $config->getUserStartingBalanceMaxPence());
    }

    public function testATransposedRangeIsNormalisedRatherThanRefused(): void
    {
        $config = new StarterConfig();
        $config->setUserStartingBalanceRangePence(600_000_000, 500_000_000);

        // An admin typing the bounds the wrong way round gets the range they obviously meant.
        // Refusing the whole starter-config form over it would be worse.
        $this->assertSame(500_000_000, $config->getUserStartingBalanceMinPence());
        $this->assertSame(600_000_000, $config->getUserStartingBalanceMaxPence());
    }

    public function testANegativeFloorIsClampedToZero(): void
    {
        $config = new StarterConfig();
        $config->setUserStartingBalanceRangePence(-1_000, 50_000);

        $this->assertSame(0, $config->getUserStartingBalanceMinPence());
        $this->assertSame(50_000, $config->getUserStartingBalanceMaxPence());
    }

    public function testPoundsAccessorsRoundTrip(): void
    {
        $config = new StarterConfig();
        $config->setUserStartingBalanceRangePounds(1_000_000, 2_000_000);

        $this->assertSame(100_000_000, $config->getUserStartingBalanceMinPence());
        $this->assertSame(1_000_000, $config->getUserStartingBalanceMinPounds());
        $this->assertSame(2_000_000, $config->getUserStartingBalanceMaxPounds());
    }

    public function testTheAmountIsInsideTheRangeAndDeterministic(): void
    {
        $config = new StarterConfig();
        $config->setUserStartingBalanceRangePence(500_000_000, 600_000_000);

        $seed  = 'd1f0c4a2-0000-7000-8000-000000000001';
        $first = $config->resolveUserStartingBalancePence($seed);

        $this->assertGreaterThanOrEqual(500_000_000, $first);
        $this->assertLessThanOrEqual(600_000_000, $first);

        // The same account must always resolve to the same figure — before the grant row
        // exists, after it exists, and in a support query next year.
        $this->assertSame($first, $config->resolveUserStartingBalancePence($seed));
    }

    public function testDifferentAccountsGetDifferentAmounts(): void
    {
        $config = new StarterConfig();
        $config->setUserStartingBalanceRangePence(500_000_000, 600_000_000);

        $amounts = [];
        for ($i = 0; $i < 25; $i++) {
            $amounts[] = $config->resolveUserStartingBalancePence('seed-' . $i);
        }

        // The whole reason it is a range rather than a value: the first decision a player makes
        // should not be identical for everybody.
        $this->assertGreaterThan(15, count(array_unique($amounts)));
    }

    public function testATransposedPairFromAConfigImportStillResolvesSanely(): void
    {
        $config = new StarterConfig();

        // Reflective config import writes the two columns in whatever order it finds them, so
        // the single-ended setters cannot order the pair. The resolver normalises on read.
        $config->setUserStartingBalanceMaxPence(500_000_000);
        $config->setUserStartingBalanceMinPence(600_000_000);

        $amount = $config->resolveUserStartingBalancePence('seed');

        $this->assertGreaterThanOrEqual(500_000_000, $amount);
        $this->assertLessThanOrEqual(600_000_000, $amount);
    }

    public function testTheRangeSurvivesAConfigExportImportRoundTrip(): void
    {
        $config = $this->configRepository->getConfig();
        $config->setUserStartingBalanceRangePence(123_000_000, 456_000_000);
        $this->em->flush();

        $service = self::getContainer()->get(\App\Service\ConfigImportExportService::class);
        $data    = $service->export();

        // Changed away, then restored from the file — which is the whole point of the export.
        $config->setUserStartingBalanceRangePence(1, 2);
        $this->em->flush();

        $service->import($data);
        $this->em->flush();

        $this->assertSame(123_000_000, $config->getUserStartingBalanceMinPence());
        $this->assertSame(456_000_000, $config->getUserStartingBalanceMaxPence());
    }

    public function testAZeroWidthRangeIsAFixedGrant(): void
    {
        $config = new StarterConfig();
        $config->setUserStartingBalanceRangePence(250_000_000, 250_000_000);

        $this->assertSame(250_000_000, $config->resolveUserStartingBalancePence('anything'));
        $this->assertSame(250_000_000, $config->resolveUserStartingBalancePence('else'));
    }

    // ───────────────────────── granting ─────────────────────────

    public function testTheGrantIsWrittenOnceAndCreditsTheAccount(): void
    {
        $user = $this->user();

        $expected = $this->grants->amountFor($user);
        $entry    = $this->grants->grantIfDue($user);

        $this->assertNotNull($entry);
        $this->assertSame(UserLedgerEntryType::STARTING_GRANT, $entry->getType());
        $this->assertSame($expected, $entry->getAmountPence());
        $this->assertSame($expected, $this->ledgerRepository->getCurrentBalance($user));

        // Account-level: the grant predates any club and must outlive every one of them.
        $this->assertNull($entry->getClub());
    }

    public function testGrantingRepeatedlyPaysExactlyOnce(): void
    {
        $user = $this->user();

        $first = $this->grants->grantIfDue($user);

        // POST /api/owner-avatar is a partial-update endpoint the client calls on every rename
        // and re-roll. Without idempotency this pays out each time.
        for ($i = 0; $i < 5; $i++) {
            $repeat = $this->grants->grantIfDue($user);
            $this->assertSame((string) $first->getId(), (string) $repeat->getId(), 'same entry returned');
        }

        $this->assertSame($first->getAmountPence(), $this->ledgerRepository->getCurrentBalance($user));
        $this->assertSame(1, $this->countGrantsFor($user));
    }

    public function testAZeroRangeGrantsNothingAtAll(): void
    {
        $this->configRepository->getConfig()->setUserStartingBalanceRangePence(0, 0);
        $this->em->flush();

        $user = $this->user();

        // A legitimate configuration — an operator turning the grant off — not an error.
        $this->assertNull($this->grants->grantIfDue($user));
        $this->assertSame(0, $this->ledgerRepository->getCurrentBalance($user));
        $this->assertSame(0, $this->countGrantsFor($user));
    }

    public function testTheGrantRespectsAnAdminEditedRange(): void
    {
        $this->configRepository->getConfig()->setUserStartingBalanceRangePence(10_000_000, 10_000_000);
        $this->em->flush();

        $entry = $this->grants->grantIfDue($this->user());

        $this->assertSame(10_000_000, $entry->getAmountPence());
    }

    public function testTheGrantDoesNotAppearOnTheClubEarningsAggregates(): void
    {
        $user  = $this->user();
        $entry = $this->grants->grantIfDue($user);

        // The grant is account-level with club IS NULL, which is exactly what
        // sumDividendsByClub() excludes — otherwise every account would show up on the public
        // career-earnings board at its grant value.
        $this->assertNull($entry->getClub());

        foreach ($this->ledgerRepository->sumDividendsByClub() as $row) {
            $this->assertNotSame(
                $entry->getAmountPence(),
                $row['score'],
                'a starting grant must never be summed as club earnings',
            );
        }
    }

    // ───────────────────────── helpers ─────────────────────────

    private function countGrantsFor(User $user): int
    {
        return (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM user_ledger WHERE user_id = :u AND type = 'starting_grant'",
            ['u' => (string) $user->getId()],
        );
    }

    private function user(): User
    {
        $user = new User('grant-' . bin2hex(random_bytes(5)) . '@test.local');
        $user->setPassword('x');

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function purge(): void
    {
        $u = "email LIKE 'grant-%@test.local'";

        $this->connection->executeStatement("DELETE FROM user_ledger WHERE user_id IN (SELECT id FROM \"user\" WHERE $u)");
        $this->connection->executeStatement("DELETE FROM \"user\" WHERE $u");
    }
}
