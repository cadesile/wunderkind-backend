<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Admin;
use App\Entity\Location\CountryProfile;
use App\Enum\EconomyWageModel;
use App\Repository\GameConfigRepository;
use App\Repository\InflationRateHistoryRepository;
use App\Repository\Location\CountryProfileRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

/**
 * World → Economy: the screen where the scaling gets turned on.
 *
 * THE TESTS THAT MATTER HERE ARE THE ONES ABOUT MOVING THE BASELINE. `country_profile` has a
 * partial unique index on `is_economy_baseline = true`, so a naive "clear the old, set the new"
 * save has an intermediate state the database refuses — and the failure would be a 500 on an
 * operator action that looks completely ordinary. The controller does it as one UPDATE with a
 * CASE; these assert that works from both directions and leaves exactly one baseline.
 *
 * The rest guard the blank-field rule the game-config screen's contract-value pair needed and
 * did not have: a cleared field posts '', which casts to 0, and 0 is a live configuration here
 * rather than an absent one. An operator who tabs through a field must not switch an economy
 * off.
 *
 * Driven through HTTP because all three behaviours live in the save handlers, not on the
 * entities — and because the baseline move is a raw SQL statement whose whole point is what the
 * database does with it.
 */
class EconomyAdminTest extends WebTestCase
{
    private const ADMIN_EMAIL = 'economy-admin@example.com';

    /** Profiles this test creates, so tearDown removes exactly those. */
    private const CODES = ['Z1', 'Z2', 'Z3'];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->loginAsAdmin();
        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->purge();

        parent::tearDown();
    }

    // ── It renders ────────────────────────────────────────────────────────

    public function testTheScreenRendersWithTheShippedInertDefaults(): void
    {
        $crawler = $this->client->request('GET', '/admin', ['routeName' => 'admin_economy']);

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();

        // The status card has to say the model is off, or an operator cannot tell what they are
        // looking at — the preview shows turnover numbers either way.
        self::assertStringContainsString('LEGACY', $body);
        self::assertStringContainsString('RESOLVER PREVIEW', $body);
        // Every tier is unauthored out of the box, and the preview must SAY so rather than
        // printing a plausible zero.
        self::assertStringContainsString('UNAUTHORED', $body);
        self::assertGreaterThan(0, $crawler->filter('form')->count());
    }

    public function testThePreviewFollowsTheCountryQueryParameter(): void
    {
        $this->insertProfile('Z1', tierCount: 4);

        $this->client->request('GET', '/admin', ['routeName' => 'admin_economy', 'preview' => 'Z1']);

        self::assertResponseIsSuccessful();
        // A 4-tier country must show four rows, not eight — tier index equals row index, with
        // no stretching.
        self::assertStringContainsString('4 tier(s)', (string) $this->client->getResponse()->getContent());
    }

    // ── Moving the baseline ───────────────────────────────────────────────

    public function testMovingTheBaselineLeavesExactlyOne(): void
    {
        $this->insertProfile('Z1', isBaseline: true);
        $this->insertProfile('Z2');

        $this->submitCountries(['baselineCountry' => 'Z2']);

        self::assertSame(['Z2'], $this->baselineCodes(), 'the move must land on exactly one row');
    }

    public function testMovingTheBaselineBackAgainAlsoWorks(): void
    {
        // The second direction, because a one-statement CASE update is only correct if it
        // handles both — and a half-written implementation usually gets one of them.
        $this->insertProfile('Z1', isBaseline: true);
        $this->insertProfile('Z2');

        $this->submitCountries(['baselineCountry' => 'Z2']);
        $this->submitCountries(['baselineCountry' => 'Z1']);

        self::assertSame(['Z1'], $this->baselineCodes());
    }

    public function testTheBaselineIsNeverMomentarilyDuplicatedOrAbsent(): void
    {
        // THE PROPERTY THE PARTIAL UNIQUE INDEX ENFORCES, asserted from the outside: a save
        // that produced two baselines would 500 on a constraint violation, and one that
        // produced none would silently resolve every country at parity. Neither happens.
        $this->insertProfile('Z1', isBaseline: true);
        $this->insertProfile('Z2');
        $this->insertProfile('Z3');

        foreach (['Z2', 'Z3', 'Z1', 'Z3'] as $target) {
            $this->submitCountries(['baselineCountry' => $target]);
            self::assertResponseRedirects();
            self::assertSame([$target], $this->baselineCodes(), "after moving to {$target}");
        }
    }

    public function testFactorsSaveAndAreFlooredAtOne(): void
    {
        $this->insertProfile('Z1');

        $this->submitCountries(['factorBp' => ['Z1' => '4200']]);
        self::assertSame(4200, $this->factorFor('Z1'));

        // Zero would resolve every wage, balance and ceiling in the country to nothing — a
        // state with no legitimate meaning that reads as "the economy is off here".
        $this->submitCountries(['factorBp' => ['Z1' => '0']]);
        self::assertSame(1, $this->factorFor('Z1'));
    }

    public function testABlankFactorLeavesTheStoredValueAlone(): void
    {
        $this->insertProfile('Z1');
        $this->submitCountries(['factorBp' => ['Z1' => '4200']]);

        $this->submitCountries(['factorBp' => ['Z1' => '']]);

        self::assertSame(4200, $this->factorFor('Z1'), 'a cleared field must not re-price a country');
    }

    // ── The scalar knobs ──────────────────────────────────────────────────

    public function testTheWageModelCanBeFlipped(): void
    {
        $this->submitSave(['economyWageModel' => 'turnover']);
        self::assertSame(EconomyWageModel::TURNOVER, $this->config()->getEconomyWageModel());

        $this->submitSave(['economyWageModel' => 'legacy']);
        self::assertSame(EconomyWageModel::LEGACY, $this->config()->getEconomyWageModel());
    }

    public function testAnUnknownWageModelIsIgnoredRatherThanStored(): void
    {
        $this->submitSave(['economyWageModel' => 'nonsense']);

        self::assertSame(EconomyWageModel::LEGACY, $this->config()->getEconomyWageModel());
    }

    public function testBlankScalarsLeaveTheStoredValuesAlone(): void
    {
        // THE GUARD THE GAME-CONFIG SCREEN DID NOT HAVE. These are multipliers on money nothing
        // on screen prices until the preview reloads, so a cleared field casting to 0 would
        // switch an economy off with the form reporting success.
        $this->submitSave([
            'economyWageToTurnoverRatioBp' => '7000',
            'economyStaffWageShareBp'      => '3000',
            'economyWeeksPerSeason'        => '40',
        ]);

        $this->submitSave([
            'economyWageToTurnoverRatioBp' => '',
            'economyStaffWageShareBp'      => '',
            'economyWeeksPerSeason'        => '',
        ]);

        $config = $this->config();
        self::assertSame(7000, $config->getEconomyWageToTurnoverRatioBp());
        self::assertSame(3000, $config->getEconomyStaffWageShareBp());
        self::assertSame(40, $config->getEconomyWeeksPerSeason());
    }

    public function testTurnoverIsTypedInPoundsAndStoredInPence(): void
    {
        // England tier 1 is around half a billion POUNDS. Typing that in pence is how two
        // orders of magnitude go missing, so the form takes pounds.
        $this->submitSave(['baselineTurnoverPounds' => [0 => '492,578,230', 7 => '272,195']]);

        $stored = $this->config()->getEconomyBaselineTurnoverPence();

        self::assertSame(49_257_823_000, $stored[0]);
        self::assertSame(27_219_500, $stored[7]);
    }

    public function testAPartialTierTableIsStillEightRows(): void
    {
        // These are read BY INDEX, so a short list would resolve a deep country's lower tiers
        // to null mid-chain — which surfaces as a wage of zero rather than an error.
        $this->submitSave(['tierFactorsBp' => [0 => '12000', 1 => '11000']]);

        self::assertCount(8, $this->config()->getEconomyTierFactorsBp());
    }

    public function testTheGlobalParachuteIsStoredVerbatimAndClearable(): void
    {
        $block = ['seasons' => 3, 'appOwnedKey' => ['nested' => true]];

        $this->submitSave(['leagueParachute' => json_encode($block)]);
        self::assertSame($block, $this->config()->getLeagueParachute());

        // Malformed must not wipe a schedule because of a missing brace.
        $this->submitSave(['leagueParachute' => '{not json']);
        self::assertSame($block, $this->config()->getLeagueParachute());

        // Blank is the explicit "no global default", and the only way to remove one.
        $this->submitSave(['leagueParachute' => '']);
        self::assertNull($this->config()->getLeagueParachute());
    }

    // ── Inflation ─────────────────────────────────────────────────────────

    public function testSettingTheRateRecordsWhatItWas(): void
    {
        $this->submitInflation(['inflationRatePerSeasonBp' => '250', 'note' => 'first pass']);

        self::assertSame(250, $this->config()->getInflationRatePerSeasonBp());

        $recent = $this->history()->findRecent(5);
        self::assertNotEmpty($recent, 'a rate change must leave an audit row');
        self::assertSame(250, $recent[0]->getRatePerSeasonBp());
        self::assertSame(0, $recent[0]->getPreviousRatePerSeasonBp(), 'the previous value is STORED, not inferred');
        self::assertSame('first pass', $recent[0]->getNote());
        self::assertNotNull($recent[0]->getChangedBy(), 'the acting admin is attributed');
    }

    public function testSettingTheSameRateRecordsNothing(): void
    {
        // An audit trail of non-changes is noise, and the operator did not do anything.
        $this->submitInflation(['inflationRatePerSeasonBp' => '250']);
        $before = count($this->history()->findRecent(50));

        $this->submitInflation(['inflationRatePerSeasonBp' => '250']);

        self::assertCount($before, $this->history()->findRecent(50));
    }

    public function testABlankRateChangesNothing(): void
    {
        $this->submitInflation(['inflationRatePerSeasonBp' => '250']);
        $this->submitInflation(['inflationRatePerSeasonBp' => '']);

        self::assertSame(250, $this->config()->getInflationRatePerSeasonBp());
    }

    public function testDeflationIsRefused(): void
    {
        // A negative rate makes a long save's ceiling approach zero, so the owner who has
        // played longest could invest least.
        $this->submitInflation(['inflationRatePerSeasonBp' => '-500']);

        self::assertSame(0, $this->config()->getInflationRatePerSeasonBp());
    }

    // ── CSRF ──────────────────────────────────────────────────────────────

    public function testEverySavePathRefusesWithoutAToken(): void
    {
        foreach ([
            'admin_economy_save'      => '/admin/economy/save',
            'admin_economy_countries' => '/admin/economy/countries',
            'admin_economy_inflation' => '/admin/economy/inflation',
        ] as $path) {
            $this->client->request('POST', $path, ['_token' => 'wrong']);
            self::assertResponseRedirects(null, 302, "{$path} must refuse a bad token");
        }

        self::assertSame(EconomyWageModel::LEGACY, $this->config()->getEconomyWageModel());
    }

    // ───────────────────────────── plumbing ─────────────────────────────

    /** @param array<string, mixed> $fields */
    private function submitSave(array $fields): void
    {
        $this->post('/admin/economy/save', 'economy_save', $fields);
    }

    /** @param array<string, mixed> $fields */
    private function submitCountries(array $fields): void
    {
        $this->post('/admin/economy/countries', 'economy_countries', $fields);
    }

    /** @param array<string, mixed> $fields */
    private function submitInflation(array $fields): void
    {
        $this->post('/admin/economy/inflation', 'economy_inflation', $fields);
    }

    /** @param array<string, mixed> $fields */
    private function post(string $path, string $tokenId, array $fields): void
    {
        $this->client->request('POST', $path, ['_token' => $this->seedValidCsrfToken($tokenId)] + $fields);
        self::assertResponseRedirects();
        self::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    private function config(): \App\Entity\GameConfig
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return self::getContainer()->get(GameConfigRepository::class)->getConfig();
    }

    private function history(): InflationRateHistoryRepository
    {
        return self::getContainer()->get(InflationRateHistoryRepository::class);
    }

    /** @return list<string> */
    private function baselineCodes(): array
    {
        return array_map(
            'strval',
            $this->connection()->fetchFirstColumn(
                'SELECT code FROM country_profile WHERE is_economy_baseline ORDER BY code',
            ),
        );
    }

    private function factorFor(string $code): int
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return (int) self::getContainer()->get(CountryProfileRepository::class)
            ->findByCode($code)?->getEconomyFactorBp();
    }

    /**
     * Raw INSERT, so the DATABASE defaults fill the two economy columns — the inert state this
     * screen is supposed to start from.
     */
    private function insertProfile(string $code, bool $isBaseline = false, int $tierCount = 8): void
    {
        $this->connection()->executeStatement(
            'INSERT INTO country_profile (code, name, default_locale, distance_unit, status, league_tier_count, created_at, updated_at, is_economy_baseline)'
            . " VALUES (:code, :name, 'en_GB', 'km', 'draft', :tiers, now(), now(), :baseline)",
            ['code' => $code, 'name' => 'Testland ' . $code, 'tiers' => $tierCount, 'baseline' => $isBaseline],
            // DBAL 4's OWN ENUM, not \PDO::PARAM_BOOL. The PDO constants are plain ints and
            // DBAL 4 type-hints ParameterType, so passing one fails inside the parameter
            // expander with a message that names neither this file nor the real cause.
            ['baseline' => \Doctrine\DBAL\ParameterType::BOOLEAN],
        );
        self::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    private function purge(): void
    {
        // The economy columns are singleton config nothing else resets, so the row is returned
        // to its SHIPPED state rather than to whatever the previous test left.
        $this->connection()->executeStatement(<<<'SQL'
            UPDATE game_config SET
                economy_wage_model = 'legacy',
                economy_tier_factors_bp = '[10000,10000,10000,10000,10000,10000,10000,10000]',
                economy_baseline_turnover_pence = '[0,0,0,0,0,0,0,0]',
                economy_wage_to_turnover_ratio_bp = 6000,
                economy_staff_wage_share_bp = 2500,
                economy_wage_curve_exponent_bp = 25000,
                economy_reputation_spread_bp = 0,
                economy_weeks_per_season = 46,
                inflation_rate_per_season_bp = 0,
                league_parachute = NULL
        SQL);
        $this->connection()->executeStatement('DELETE FROM inflation_rate_history');
        // One scalar statement per code rather than an IN (?) array parameter: three rows is
        // not worth the DBAL array-type plumbing, and the typed-array API differs enough
        // between versions to be a liability in a teardown that must never itself fail.
        foreach (self::CODES as $code) {
            $this->connection()->executeStatement('DELETE FROM country_profile WHERE code = :code', ['code' => $code]);
        }
        self::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }

    private function loginAsAdmin(): void
    {
        $em    = self::getContainer()->get(EntityManagerInterface::class);
        $admin = $em->getRepository(Admin::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        if ($admin === null) {
            $admin = new Admin(self::ADMIN_EMAIL);
            $admin->setPassword('not-used-for-login-here');
            $em->persist($admin);
            $em->flush();
        }

        $this->client->loginUser($admin, 'admin');
    }

    private function seedValidCsrfToken(string $tokenId): string
    {
        $value   = 'test-csrf-token-' . bin2hex(random_bytes(8));
        $session = $this->client->getContainer()->get('session.factory')->createSession();

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
}
