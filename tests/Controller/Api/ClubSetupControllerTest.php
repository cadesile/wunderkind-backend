<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Club;
use App\Entity\ClubFacility;
use App\Entity\FacilityTemplate;
use App\Entity\User;
use App\Enum\UserLedgerEntryType;
use App\Service\ClubResolver;
use App\Service\UserLedgerService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * POST /api/club/facilities/{site}, POST /api/club/fund and GET /api/club/setup-state.
 *
 * THE ASSERTIONS THAT MATTER MOST ARE THE IDEMPOTENCY ONES. Both writes spend real account
 * credit and the client retries them after a dropped response, so "the same request twice
 * charges once" is the whole contract — a regression there bills a player twice for one
 * purchase, which no amount of client-side care can prevent.
 *
 * The second group is the BOUNDARY: neither endpoint may write `club.balance`. The device owns
 * that column and overwrites it absolutely on every sync, so a server-side figure there looks
 * right until the first sync and then vanishes.
 */
class ClubSetupControllerTest extends WebTestCase
{
    private const EMAIL_PREFIX = 'setup-';

    private KernelBrowser $client;
    private string $currentUserId = '';
    private EntityManagerInterface $em;
    private Connection $connection;
    private string $clubId = '';
    /**
     * Slugs this test created, so purge() can remove exactly those.
     *
     * NOT A TIDINESS MEASURE. `facility_template` is a SHARED singleton-ish table that the test
     * database carries no rows for, and FacilitySpriteMapTest skips itself when it is empty but
     * FAILS when it is partially populated — "the sprite draws something the game does not
     * seed". Leaving four rows behind therefore broke an unrelated test file, which is exactly
     * the cross-file contamination the suite is hard to debug for.
     *
     * @var list<string>
     */
    private array $createdTemplateSlugs = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();

        $c                = self::getContainer();
        $this->em         = $c->get(EntityManagerInterface::class);
        $this->connection = $c->get(Connection::class);

        $this->purge();
        $this->seedTemplates();
    }

    protected function tearDown(): void
    {
        $this->purge();

        parent::tearDown();
    }

    // ───────────────────────── building a site ─────────────────────────

    public function testBuildingASiteChargesTheAccountAndSetsTheLevels(): void
    {
        $this->accountWithClub(creditPence: 1_000_000);

        $this->authedRequest('POST', '/api/club/facilities/training', [
            'levels' => ['training_pitch' => 2, 'strength_suite' => 1],
        ]);

        self::assertResponseIsSuccessful();
        $body = $this->json();

        // training_pitch to level 2 = 100 + 200 = 300; strength_suite to level 1 = 100.
        self::assertTrue($body['charged']);
        self::assertSame(400, $body['chargedPence']);
        self::assertSame(2, $body['levels']['training_pitch']);
        self::assertSame(1, $body['levels']['strength_suite']);
        self::assertSame(1_000_000 - 400, $body['balancePence']);

        // One debit, stored NEGATIVE — the sign convention the ledger's balance chaining
        // depends on.
        self::assertSame(
            -400,
            (int) $this->connection->fetchOne(
                "SELECT amount_pence FROM user_ledger WHERE type = 'facility_training' AND user_id = ?",
                [$this->currentUserId],
            ),
        );
    }

    /**
     * The same levels twice: one charge.
     *
     * This is the retry-after-a-dropped-response case, and the reason setup-flow.md #5 chose a
     * natural key over an Idempotency-Key header.
     */
    public function testRebuildingTheSameLevelsDoesNotChargeTwice(): void
    {
        $this->accountWithClub(creditPence: 1_000_000);

        $levels = ['levels' => ['training_pitch' => 2]];

        $this->authedRequest('POST', '/api/club/facilities/training', $levels);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->json()['charged']);

        $this->authedRequest('POST', '/api/club/facilities/training', $levels);

        self::assertResponseIsSuccessful();
        $body = $this->json();

        // Reports the ORIGINAL charge so the client can still render a price, but charged false.
        self::assertFalse($body['charged']);
        self::assertSame(300, $body['chargedPence']);

        self::assertSame(
            1,
            (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM user_ledger WHERE type = 'facility_training' AND user_id = ?",
                [$this->currentUserId],
            ),
            'a retry must not write a second debit',
        );
        self::assertSame(1_000_000 - 300, $this->json()['balancePence']);
    }

    /** Different levels after a charge is a conflict, not a second purchase. */
    public function testDifferentLevelsAfterAChargeAreRefused(): void
    {
        $this->accountWithClub(creditPence: 1_000_000);

        $this->authedRequest('POST', '/api/club/facilities/training', ['levels' => ['training_pitch' => 2]]);
        self::assertResponseIsSuccessful();

        $this->authedRequest('POST', '/api/club/facilities/training', ['levels' => ['training_pitch' => 4]]);

        self::assertResponseStatusCodeSame(409);
        $body = $this->json();
        self::assertSame('site_already_built', $body['error']);
        // Carries what IS standing, so the client can show the player what they already own
        // rather than only that it said no.
        self::assertSame(2, $body['builtLevels']['training_pitch']);
        self::assertSame(300, $body['chargedPence']);

        self::assertSame(
            1,
            (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM user_ledger WHERE type = 'facility_training' AND user_id = ?",
                [$this->currentUserId],
            ),
        );
    }

    /** 402, with the arithmetic, so the client can say how short the player is. */
    public function testBuildingBeyondTheBalanceIsRefusedWithoutCharging(): void
    {
        $this->accountWithClub(creditPence: 250);

        $this->authedRequest('POST', '/api/club/facilities/training', ['levels' => ['training_pitch' => 2]]);

        self::assertResponseStatusCodeSame(402);
        $body = $this->json();
        self::assertSame('insufficient_credit', $body['error']);
        self::assertSame(250, $body['balancePence']);
        self::assertSame(300, $body['requiredPence']);

        self::assertSame(
            0,
            (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM user_ledger WHERE type = 'facility_training' AND user_id = ?",
                [$this->currentUserId],
            ),
            'a refused build must leave no ledger row',
        );
        self::assertSame(
            0,
            (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM club_facility WHERE club_id = ?',
                [$this->clubId],
            ),
            'a refused build must leave no facility rows',
        );
    }

    public function testAnUnknownSiteIs404AndListsTheRealOnes(): void
    {
        $this->accountWithClub(creditPence: 1_000_000);

        $this->authedRequest('POST', '/api/club/facilities/spaceport', ['levels' => ['training_pitch' => 1]]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('unknown_site', $this->json()['error']);
        self::assertContains('training', $this->json()['knownSites']);
    }

    /** A slug that belongs to another site cannot be bought through this one. */
    public function testAFacilityFromAnotherSiteIsRefused(): void
    {
        $this->accountWithClub(creditPence: 1_000_000);

        $this->authedRequest('POST', '/api/club/facilities/training', ['levels' => ['physio_clinic' => 1]]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('unknown_facility', $this->json()['error']);
    }

    public function testALevelAboveTheTemplateMaximumIsRefused(): void
    {
        $this->accountWithClub(creditPence: 100_000_000);

        $this->authedRequest('POST', '/api/club/facilities/training', ['levels' => ['training_pitch' => 99]]);

        self::assertResponseStatusCodeSame(422);
        $body = $this->json();
        self::assertSame('invalid_level', $body['error']);
        self::assertSame(5, $body['maxLevel']);
    }

    /**
     * A target below what is already built is refused rather than silently ignored.
     *
     * There is no facility sell-back, so a lower number can only be a client bug or a stale
     * screen. Reporting success while charging nothing would leave the two disagreeing about
     * what the club has.
     */
    public function testADowngradeIsRefused(): void
    {
        $this->accountWithClub(creditPence: 1_000_000);

        $facility = new ClubFacility($this->em->find(Club::class, $this->clubId), 'training_pitch');
        $facility->setLevel(4);
        $this->em->persist($facility);
        $this->em->flush();

        $this->authedRequest('POST', '/api/club/facilities/training', ['levels' => ['training_pitch' => 2]]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('invalid_level', $this->json()['error']);
    }

    /** Pricing starts from the level already held, so a part-built site is not re-charged. */
    public function testAPartBuiltSiteIsChargedOnlyForTheDifference(): void
    {
        $this->accountWithClub(creditPence: 1_000_000);

        $facility = new ClubFacility($this->em->find(Club::class, $this->clubId), 'training_pitch');
        $facility->setLevel(2);
        $this->em->persist($facility);
        $this->em->flush();

        $this->authedRequest('POST', '/api/club/facilities/training', ['levels' => ['training_pitch' => 4]]);

        self::assertResponseIsSuccessful();
        // Levels 3 and 4 only: 300 + 400. Not the full 1000 of reaching level 4 from nothing.
        self::assertSame(700, $this->json()['chargedPence']);
    }

    public function testBuildRequiresLevels(): void
    {
        $this->accountWithClub(creditPence: 1_000_000);

        $this->authedRequest('POST', '/api/club/facilities/training', []);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('levels_required', $this->json()['error']);
    }

    /**
     * Each site bills under its OWN ledger type.
     *
     * The client localises a ledger row on its type and ships one i18n key per site, so a single
     * shared `facility_build` type left four of those keys dead and every row labelled with
     * whatever string the server sent. Asserted per site rather than once, because the mapping
     * is a match expression that is easy to half-update.
     */
    public function testEachSiteBillsUnderItsOwnLedgerType(): void
    {
        $expected = [
            'training' => 'facility_training',
            'medical'  => 'facility_medical',
            'scouting' => 'facility_scouting',
        ];

        foreach ($expected as $site => $type) {
            $this->accountWithClub(creditPence: 1_000_000);

            $slug = match ($site) {
                'training' => 'training_pitch',
                'medical'  => 'physio_clinic',
                'scouting' => 'scouting_center',
            };

            $this->authedRequest('POST', "/api/club/facilities/{$site}", ['levels' => [$slug => 1]]);
            self::assertResponseIsSuccessful();

            self::assertSame(
                $type,
                (string) $this->connection->fetchOne(
                    'SELECT type FROM user_ledger WHERE user_id = ? AND type LIKE \'facility%\'',
                    [$this->currentUserId],
                ),
                "the {$site} site must bill as {$type}",
            );
        }
    }

    // ───────────────────────── funding ─────────────────────────

    public function testFundingDebitsTheAccountAndReturnsTheStake(): void
    {
        $this->accountWithClub(creditPence: 500_000);

        $this->authedRequest('POST', '/api/club/fund', ['amountPence' => 200_000]);

        self::assertResponseIsSuccessful();
        $body = $this->json();

        self::assertTrue($body['funded']);
        self::assertSame(200_000, $body['stakedPence']);
        self::assertSame(300_000, $body['balancePence']);
        // No minimum by default — the agreed flow lets the player stake any part of the balance.
        self::assertSame(0, $body['minimumPence']);

        self::assertSame(
            -200_000,
            (int) $this->connection->fetchOne(
                "SELECT amount_pence FROM user_ledger WHERE type = 'club_funding' AND user_id = ?",
                [$this->currentUserId],
            ),
        );
    }

    /**
     * Funding must not write `club.balance`.
     *
     * THE BOUNDARY, ASSERTED. The device owns that column and SyncService::process() assigns it
     * absolutely from the device on every sync, so a figure written here would survive until the
     * first sync and then disappear. The staked amount is returned for the client to seed its
     * own local capital with instead.
     */
    public function testFundingDoesNotTouchTheClubBalance(): void
    {
        $this->accountWithClub(creditPence: 500_000);

        $before = (int) $this->connection->fetchOne('SELECT balance FROM club WHERE id = ?', [$this->clubId]);

        $this->authedRequest('POST', '/api/club/fund', ['amountPence' => 200_000]);
        self::assertResponseIsSuccessful();

        self::assertSame(
            $before,
            (int) $this->connection->fetchOne('SELECT balance FROM club WHERE id = ?', [$this->clubId]),
            'club.balance is device-owned and must not be written by the funding endpoint',
        );
    }

    public function testFundingTheSameAmountTwiceDebitsOnce(): void
    {
        $this->accountWithClub(creditPence: 500_000);

        $this->authedRequest('POST', '/api/club/fund', ['amountPence' => 200_000]);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->json()['funded']);

        $this->authedRequest('POST', '/api/club/fund', ['amountPence' => 200_000]);

        self::assertResponseIsSuccessful();
        self::assertFalse($this->json()['funded']);
        self::assertSame(200_000, $this->json()['stakedPence']);

        self::assertSame(
            1,
            (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM user_ledger WHERE type = 'club_funding' AND user_id = ?",
                [$this->currentUserId],
            ),
        );
    }

    public function testFundingADifferentAmountAfterTheFirstIsRefused(): void
    {
        $this->accountWithClub(creditPence: 500_000);

        $this->authedRequest('POST', '/api/club/fund', ['amountPence' => 200_000]);
        self::assertResponseIsSuccessful();

        $this->authedRequest('POST', '/api/club/fund', ['amountPence' => 250_000]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('already_funded', $this->json()['error']);
        self::assertSame(200_000, $this->json()['stakedPence']);
    }

    public function testStakingMoreThanTheBalanceIsRefused(): void
    {
        $this->accountWithClub(creditPence: 100_000);

        $this->authedRequest('POST', '/api/club/fund', ['amountPence' => 150_000]);

        self::assertResponseStatusCodeSame(402);
        self::assertSame('insufficient_credit', $this->json()['error']);
    }

    /** The whole balance is a legitimate stake — "a partial sum or 100%". */
    public function testStakingTheWholeBalanceIsAllowed(): void
    {
        $this->accountWithClub(creditPence: 100_000);

        $this->authedRequest('POST', '/api/club/fund', ['amountPence' => 100_000]);

        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->json()['balancePence']);
    }

    public function testAZeroStakeIsRefused(): void
    {
        $this->accountWithClub(creditPence: 100_000);

        $this->authedRequest('POST', '/api/club/fund', ['amountPence' => 0]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_amount', $this->json()['error']);
    }

    /** Zero means "no minimum", so an operator-set floor is what turns the refusal on. */
    public function testAConfiguredMinimumIsEnforced(): void
    {
        $this->accountWithClub(creditPence: 500_000);
        $this->setFundingMinimum(300_000);

        try {
            $this->authedRequest('POST', '/api/club/fund', ['amountPence' => 200_000]);

            self::assertResponseStatusCodeSame(422);
            $body = $this->json();
            self::assertSame('below_funding_minimum', $body['error']);
            self::assertSame(300_000, $body['minimumPence']);
        } finally {
            $this->setFundingMinimum(0);
        }
    }

    /** The projection says out loud that wages are not in it — the server has no roster. */
    public function testTheProjectionReportsFacilityUpkeepAndDisclaimsWages(): void
    {
        $this->accountWithClub(creditPence: 1_000_000);

        $facility = new ClubFacility($this->em->find(Club::class, $this->clubId), 'training_pitch');
        $facility->setLevel(1);
        $this->em->persist($facility);
        $this->em->flush();

        $this->authedRequest('POST', '/api/club/fund', ['amountPence' => 100_000]);

        self::assertResponseIsSuccessful();
        $projection = $this->json()['projection'];

        // weeklyUpkeepBase is 1000 on the seeded template, and upkeep is `base x 1.5^level`
        // — so a level-1 facility runs at 1500, not 1000. That is 50% more than this test
        // asserted before 2026-11-12, when the server was aligned to the charge the app
        // actually makes; see FacilityCostCalculator::weeklyUpkeep().
        self::assertSame(1500, $projection['facilityUpkeepPerWeekPence']);
        self::assertSame(1500 * $projection['weeks'], $projection['facilityUpkeepPence']);
        self::assertFalse($projection['wagesIncluded']);
    }

    // ───────────────────────── setup state ─────────────────────────

    public function testSetupStateDerivesProgressFromWhatWasBilled(): void
    {
        $this->accountWithClub(creditPence: 1_000_000);

        $this->authedRequest('GET', '/api/club/setup-state');
        self::assertResponseIsSuccessful();

        $before = $this->json();
        self::assertFalse($before['sites']['training']['charged']);
        self::assertFalse($before['funding']['funded']);
        self::assertFalse($before['complete']);

        $this->authedRequest('POST', '/api/club/facilities/training', ['levels' => ['training_pitch' => 2]]);
        self::assertResponseIsSuccessful();
        $this->authedRequest('POST', '/api/club/fund', ['amountPence' => 150_000]);
        self::assertResponseIsSuccessful();

        $this->authedRequest('GET', '/api/club/setup-state');
        self::assertResponseIsSuccessful();

        $after = $this->json();
        self::assertTrue($after['sites']['training']['charged']);
        self::assertSame(300, $after['sites']['training']['chargedPence']);
        self::assertSame(2, $after['sites']['training']['levels']['training_pitch']);
        self::assertTrue($after['funding']['funded']);
        self::assertSame(150_000, $after['funding']['stakedPence']);
        // The one thing that gates entering the game: the club has capital.
        self::assertTrue($after['complete']);
        // A site nobody built reports unbuilt rather than being absent.
        self::assertFalse($after['sites']['medical']['charged']);
    }

    public function testSetupStateIsNeverSharedCacheable(): void
    {
        $this->accountWithClub(creditPence: 1_000_000);

        $this->authedRequest('GET', '/api/club/setup-state');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'private',
            (string) $this->client->getResponse()->headers->get('Cache-Control'),
        );
    }

    /** Another account's club is not reachable, even with a valid id. */
    public function testAnotherAccountsClubIsRefused(): void
    {
        $this->accountWithClub(creditPence: 1_000_000);
        $victimClubId = $this->clubId;

        // A second account, which then names the first account's club.
        $this->accountWithClub(creditPence: 1_000_000);
        $this->clubId = $victimClubId;

        $this->authedRequest('POST', '/api/club/fund', ['amountPence' => 1000]);

        self::assertResponseStatusCodeSame(403);
    }

    // ───────────────────────── helpers ─────────────────────────

    /**
     * Four facility templates across two sites, with round numbers so the expected charges
     * above are readable arithmetic rather than fixtures.
     */
    private function seedTemplates(): void
    {
        $specs = [
            ['training_pitch',  'TRAINING', 100],
            ['strength_suite',  'TRAINING', 100],
            ['physio_clinic',   'MEDICAL',  100],
            ['scouting_center', 'SCOUTING', 100],
        ];

        $this->createdTemplateSlugs = [];

        foreach ($specs as [$slug, $category, $baseCost]) {
            $existing = $this->em->getRepository(FacilityTemplate::class)->findOneBy(['slug' => $slug]);

            if ($existing instanceof FacilityTemplate) {
                // The table is shared with other test files and with whatever the dev database
                // carries, so align the fields these assertions depend on rather than assuming.
                $existing->setBaseCost($baseCost);
                $existing->setMaxLevel(5);
                $existing->setWeeklyUpkeepBase(1000);
                $existing->setIsActive(true);
                continue;
            }

            $template = new FacilityTemplate($slug, ucfirst(str_replace('_', ' ', $slug)), 'Test facility', $category, $baseCost);
            $template->setMaxLevel(5);
            $template->setWeeklyUpkeepBase(1000);
            $template->setIsActive(true);
            $this->em->persist($template);
            // Only rows WE created are removed again — a pre-existing row (a dev database
            // reused for tests) is left exactly as it was found.
            $this->createdTemplateSlugs[] = $slug;
        }

        $this->em->flush();
    }

    private function accountWithClub(int $creditPence): void
    {
        $user = new User(self::EMAIL_PREFIX . bin2hex(random_bytes(5)) . '@test.local');
        $user->setPassword('x');
        $user->setRoles([User::ROLE_CLUB]);
        $this->em->persist($user);

        $club = new Club('Setup FC ' . bin2hex(random_bytes(3)), $user);
        $this->em->persist($club);
        $this->em->flush();

        $this->currentUserId = (string) $user->getId();
        $this->clubId        = $club->getId()->toRfc4122();

        if ($creditPence > 0) {
            self::getContainer()->get(UserLedgerService::class)->append(
                $user,
                null,
                UserLedgerEntryType::STARTING_GRANT,
                $creditPence,
                'test',
                'setup-grant-' . bin2hex(random_bytes(5)),
            );
        }
    }

    private function setFundingMinimum(int $pence): void
    {
        $this->connection->executeStatement('UPDATE starter_config SET club_funding_minimum_pence = :p', ['p' => $pence]);
        $this->em->clear();
    }

    /**
     * A fresh, authenticated client for exactly ONE request, with the club named explicitly.
     *
     * loginUser() authenticates a single request against this stateless JWT firewall and the
     * client never recovers, so every request needs a new one — same constraint
     * LocationControllerTest documents. The X-Club-Id header is sent because these endpoints
     * use resolveRequiredFromRequest(), which never guesses which save a charge lands on.
     *
     * @param array<string, mixed> $body
     */
    private function authedRequest(string $method, string $uri, array $body = []): void
    {
        self::ensureKernelShutdown();

        $this->client = static::createClient();

        $c                = self::getContainer();
        $this->em         = $c->get(EntityManagerInterface::class);
        $this->connection = $c->get(Connection::class);

        $this->client->loginUser($this->em->find(User::class, $this->currentUserId), 'api');

        $this->client->request(
            $method,
            $uri,
            server: [
                'CONTENT_TYPE'           => 'application/json',
                'HTTP_' . str_replace('-', '_', strtoupper(ClubResolver::CLUB_ID_HEADER)) => $this->clubId,
            ],
            content: $body === [] ? '' : json_encode($body),
        );
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function purge(): void
    {
        $c    = $this->connection;
        $like = self::EMAIL_PREFIX . '%@test.local';

        $c->executeStatement(
            'DELETE FROM club_facility WHERE club_id IN (SELECT id FROM club WHERE user_id IN (SELECT id FROM "user" WHERE email LIKE ?))',
            [$like],
        );
        $c->executeStatement('DELETE FROM user_ledger WHERE user_id IN (SELECT id FROM "user" WHERE email LIKE ?)', [$like]);
        $c->executeStatement('DELETE FROM club WHERE user_id IN (SELECT id FROM "user" WHERE email LIKE ?)', [$like]);
        $c->executeStatement('DELETE FROM "user" WHERE email LIKE ?', [$like]);

        if ($this->createdTemplateSlugs !== []) {
            $c->executeStatement(
                'DELETE FROM facility_template WHERE slug IN (?)',
                [$this->createdTemplateSlugs],
                [\Doctrine\DBAL\ArrayParameterType::STRING],
            );
            $this->createdTemplateSlugs = [];
        }
    }
}
