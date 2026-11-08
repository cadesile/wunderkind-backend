<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Admin;
use App\Entity\Location\LocationConfig;
use App\Enum\CitySize;
use App\Repository\Location\LocationConfigRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

/**
 * The Locations &amp; land configuration screen.
 *
 * Worth testing beyond "does it render": the figures it edits decide how many clubs every town
 * in the game can hold, and the save path is the only place an operator can set them. The
 * guards matter for the same reason every other admin write here is guarded — a stray GET must
 * not be able to change the land economy.
 */
class LocationConfigControllerTest extends WebTestCase
{
    private const ADMIN_EMAIL = 'location-config-test@example.com';

    protected function tearDown(): void
    {
        // Singleton row, shared with every other test in the suite.
        $conn = self::getContainer()->get(Connection::class);
        $conn->executeStatement('DELETE FROM location_config');
        $conn->executeStatement("DELETE FROM land_plot WHERE location_id IN (SELECT id FROM location WHERE country_code = 'ZQ')");
        $conn->executeStatement("DELETE FROM location WHERE country_code = 'ZQ'");
        $conn->executeStatement("DELETE FROM country_profile WHERE code = 'ZQ'");
        parent::tearDown();
    }

    public function testTheScreenRenders(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $client->request('GET', '/admin', ['routeName' => 'admin_location_config']);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[action*="admin_location_config_save_plots"]');

        // One input per city size, named off the enum so a new size appears without a template
        // edit — and so a renamed one fails here rather than silently stopping saving.
        foreach (CitySize::cases() as $size) {
            self::assertSelectorExists(
                \sprintf('input[name="plots_%s"]', $size->value),
                "an input for {$size->value} must exist",
            );
        }
    }

    public function testItShowsTheCurrentFiguresNotTheDefaults(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $config = self::getContainer()->get(LocationConfigRepository::class)->getConfig();
        $config->setPlotsByCitySize([CitySize::BIG->value => 9]);
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $crawler = $client->request('GET', '/admin', ['routeName' => 'admin_location_config']);

        self::assertSame(
            '9',
            $crawler->filter(\sprintf('input[name="plots_%s"]', CitySize::BIG->value))->attr('value'),
        );
    }

    public function testSavingPersistsTheNewFigures(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $client->request('POST', '/admin/locations/config/plots', [
            '_token'                                    => $this->csrfToken($client, 'location_config_plots'),
            'plots_' . CitySize::BIG->value             => '6',
            'plots_' . CitySize::MEDIUM->value          => '3',
            'plots_' . CitySize::SMALL->value           => '2',
        ]);

        self::assertResponseRedirects();

        $config = self::getContainer()->get(LocationConfigRepository::class)->getConfig();
        self::assertSame(6, $config->plotsForCitySize(CitySize::BIG));
        self::assertSame(3, $config->plotsForCitySize(CitySize::MEDIUM));
        self::assertSame(2, $config->plotsForCitySize(CitySize::SMALL));
    }

    public function testAnOutOfRangeFigureIsClampedServerSide(): void
    {
        // The form carries min/max attributes, which a client can simply not send. Clamping
        // lives on the entity so the CLI gets the same guarantee.
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $client->request('POST', '/admin/locations/config/plots', [
            '_token'                        => $this->csrfToken($client, 'location_config_plots'),
            'plots_' . CitySize::BIG->value => '500',
        ]);

        $config = self::getContainer()->get(LocationConfigRepository::class)->getConfig();
        self::assertSame(LocationConfig::MAX_PLOTS, $config->plotsForCitySize(CitySize::BIG));
    }

    public function testSavingWithoutAValidTokenChangesNothing(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $client->request('POST', '/admin/locations/config/plots', [
            '_token'                        => 'nope',
            'plots_' . CitySize::BIG->value => '11',
        ]);

        $config = self::getContainer()->get(LocationConfigRepository::class)->getConfig();
        self::assertSame(
            LocationConfig::DEFAULT_PLOTS_BY_CITY_SIZE[CitySize::BIG->value],
            $config->plotsForCitySize(CitySize::BIG),
            'a tokenless save must not change the land economy',
        );
    }

    public function testSavingIsPostOnly(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $client->request('GET', '/admin/locations/config/plots');

        self::assertResponseStatusCodeSame(405);
    }

    public function testTheTopUpRunsAndReportsWhenThereIsNothingToDo(): void
    {
        // A fresh test database has no priced towns, so the honest answer is "nothing to add"
        // rather than silence — and a dry run must never write.
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $client->request('POST', '/admin/locations/config/provision', [
            '_token'  => $this->csrfToken($client, 'location_config_provision'),
            'dry_run' => '1',
        ]);

        self::assertResponseRedirects();
        $client->followRedirect();
        // Flashes render as toasts, which upper-case the message.
        self::assertStringContainsStringIgnoringCase(
            'nothing to add',
            (string) $client->getResponse()->getContent(),
        );
    }

    public function testTheTopUpIsPostOnly(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $client->request('GET', '/admin/locations/config/provision');

        self::assertResponseStatusCodeSame(405);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    // ── City size bands ──────────────────────────────────────────────────

    public function testTheBandFieldsAndTheRecalculateQueueRender(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $client->request('GET', '/admin', ['routeName' => 'admin_location_config']);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[action*="admin_location_config_save_bands"]');
        self::assertSelectorExists('input[name="bigSharePercent"]');
        self::assertSelectorExists('input[name="smallSharePercent"]');
    }

    public function testSavingTheBandsPersistsThem(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $client->request('POST', '/admin/locations/config/bands', [
            '_token'            => $this->csrfToken($client, 'location_config_bands'),
            'bigSharePercent'   => '10',
            'smallSharePercent' => '70',
        ]);

        self::assertResponseRedirects();

        $config = $this->freshConfig();
        self::assertSame(10, $config->getBigSharePercent());
        self::assertSame(70, $config->getSmallSharePercent());
        self::assertSame(20, $config->getMediumSharePercent(), 'medium is the remainder');
    }

    /**
     * Overlapping bands are resolved the way the ranking actually resolves them.
     *
     * classify() tests the big cutoff before the small one, so 60/60 really produces 60 big
     * and 40 small. Storing what was typed would leave the screen describing a split that
     * never happens.
     */
    public function testSmallGivesWayWhenTheBandsOverlap(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $client->request('POST', '/admin/locations/config/bands', [
            '_token'            => $this->csrfToken($client, 'location_config_bands'),
            'bigSharePercent'   => '60',
            'smallSharePercent' => '60',
        ]);

        $config = $this->freshConfig();
        self::assertSame(60, $config->getBigSharePercent());
        self::assertSame(40, $config->getSmallSharePercent(), 'clamped to what is left');
        self::assertSame(0, $config->getMediumSharePercent());
    }

    public function testOutOfRangeBandsAreClampedNotTrusted(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $client->request('POST', '/admin/locations/config/bands', [
            '_token'            => $this->csrfToken($client, 'location_config_bands'),
            'bigSharePercent'   => '900',
            'smallSharePercent' => '-20',
        ]);

        $config = $this->freshConfig();
        self::assertSame(100, $config->getBigSharePercent());
        self::assertSame(0, $config->getSmallSharePercent());
    }

    public function testSavingTheBandsNeedsAValidToken(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $client->request('POST', '/admin/locations/config/bands', [
            '_token'          => 'not-a-real-token',
            'bigSharePercent' => '99',
        ]);

        self::assertResponseRedirects();
        self::assertNotSame(99, $this->freshConfig()->getBigSharePercent());
    }

    // ── The recalculation queue ──────────────────────────────────────────

    public function testReclassifyChunkRejectsABadToken(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $client->request('POST', '/admin/locations/config/reclassify-chunk', [
            '_token'  => 'not-a-real-token',
            'country' => 'EN',
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertFalse(json_decode($client->getResponse()->getContent(), true)['success']);
    }

    public function testReclassifyChunkNeedsACountry(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $client->request('POST', '/admin/locations/config/reclassify-chunk', [
            '_token'  => $this->csrfToken($client, 'location_config_reclassify'),
            'country' => '',
        ]);

        self::assertResponseStatusCodeSame(400);
    }

    /**
     * A country with nothing to rank is "skipped", not a failure.
     *
     * Same shape the pool generate-chunk endpoints use for a pool already at target, which is
     * what lets the shared BMC.runJobs() runner drive this without knowing about locations.
     */
    public function testACountryWithNoPricedTownsIsSkipped(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $client->request('POST', '/admin/locations/config/reclassify-chunk', [
            '_token'  => $this->csrfToken($client, 'location_config_reclassify'),
            'country' => 'ZZ',
        ]);

        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertTrue($data['success']);
        self::assertTrue($data['skipped']);
        self::assertSame(0, $data['count']);
    }

    /**
     * The whole feature, end to end: change the bands, run a step, towns move.
     *
     * The other tests here prove the bands persist and the endpoint answers. This is the one
     * that proves they are connected — ten towns at 20/50 band 2/3/5, and at 50/20 they band
     * 5/3/2. Without it the screen could save numbers nothing reads.
     */
    public function testChangingTheBandsAndRunningAStepMovesTowns(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $this->seedTowns('ZQ', 10);

        $client->request('POST', '/admin/locations/config/bands', [
            '_token'            => $this->csrfToken($client, 'location_config_bands'),
            'bigSharePercent'   => '50',
            'smallSharePercent' => '20',
        ]);

        $client->request('POST', '/admin/locations/config/reclassify-chunk', [
            '_token'  => $this->csrfToken($client, 'location_config_reclassify'),
            'country' => 'ZQ',
        ]);

        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);

        self::assertTrue($data['success']);
        self::assertSame(10, $data['count']);
        self::assertSame(5, $data['distribution'][CitySize::BIG->value]);
        self::assertSame(2, $data['distribution'][CitySize::SMALL->value]);
        self::assertSame(3, $data['distribution'][CitySize::MEDIUM->value]);

        // And the rows really carry it, not just the response.
        $big = self::getContainer()->get(Connection::class)->fetchOne(
            "SELECT COUNT(*) FROM location WHERE country_code = 'ZQ' AND city_size = 'BIG'",
        );
        self::assertSame(5, (int) $big);
    }

    /**
     * Ten priced towns with distinct populations, so the ranking is unambiguous.
     *
     * Through the entities rather than raw SQL: both tables carry NOT NULL columns the
     * constructors fill in — country_profile.default_locale, location.land_source — and
     * chasing them one INSERT error at a time is how this started.
     */
    private function seedTowns(string $code, int $n): void
    {
        $em = self::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);

        $profile = $em->find(\App\Entity\Location\CountryProfile::class, $code);

        if ($profile === null) {
            $profile = new \App\Entity\Location\CountryProfile($code, $code);
            $em->persist($profile);
            $em->flush();
        }

        for ($i = 1; $i <= $n; $i++) {
            $town = 'Town' . $i;
            $location = new \App\Entity\Location\Location($profile, $town, $code . '-t' . $i, strtolower($town));
            $location->setRegion('R');
            $location->setPopulation($i * 1000);
            $location->setCoordinates(50.0, 0.0);
            $location->setLandPence(100000);
            $location->setLandValuedAt(new \DateTimeImmutable());

            $em->persist($location);
        }

        $em->flush();
        $em->clear();
    }

    private function freshConfig(): \App\Entity\Location\LocationConfig
    {
        self::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class)->clear();

        return self::getContainer()->get(\App\Repository\Location\LocationConfigRepository::class)->getConfig();
    }

    private function loginAsAdmin(KernelBrowser $client): void
    {
        $client->disableReboot();

        $em    = self::getContainer()->get(EntityManagerInterface::class);
        $admin = $em->getRepository(Admin::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        if ($admin === null) {
            $admin = new Admin(self::ADMIN_EMAIL);
            $admin->setPassword('not-used-for-login-here');
            $em->persist($admin);
            $em->flush();
        }

        $client->loginUser($admin, 'admin');
    }

    /**
     * A token the controller will accept.
     *
     * Seeded into the session under SessionTokenStorage's own key, because loginUser() performs
     * no HTTP request and there is nothing to read a real token from yet. Same pattern as
     * LocationAdminTest and NpcClubSizeWeightsControllerTest.
     */
    private function csrfToken(KernelBrowser $client, string $tokenId): string
    {
        $value = 'test-csrf-token-' . bin2hex(random_bytes(8));

        $session = $client->getContainer()->get('session.factory')->createSession();

        // CONTINUES the existing session rather than starting a new one. Creating a fresh
        // session here overwrote the cookie loginUser() had set, so every guarded POST
        // redirected to the sign-in page instead of exercising the guard.
        $existing = $client->getCookieJar()->get($session->getName());

        if ($existing !== null) {
            $session->setId($existing->getValue());
        }

        $session->start();
        $session->set("_csrf/{$tokenId}", $value);
        $session->save();

        $client->getCookieJar()->set(new Cookie($session->getName(), $session->getId()));

        return $value;
    }

    /**
     * The redesigned shell, held to the same checklist as the other six rebuilt pages.
     *
     * NOT DECORATION. Each of these has its own failure mode and each was hit at least once
     * while the pages were being migrated: a controller that forgets bmcGroup/bmcTitle renders
     * a strip with an empty crumb and no heading, and a page that leaves <main data-bmc-page>
     * empty silently breaks the sidebar pin.
     */
    public function testThePageRendersInTheRedesignedShell(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $client->request('GET', '/admin', ['routeName' => 'admin_location_config']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.bmc-title', 'CONFIGURATION');
        self::assertSelectorTextContains('.bmc-crumb', 'LOCATIONS & LAND');
        self::assertSelectorExists('.bmc-page');
        self::assertSelectorNotExists('.card-body', 'no Bootstrap cards should survive the rebuild');
    }

    /**
     * BOTH forms keep their own save bar, and both are inline rather than sticky.
     *
     * The plot counts and the size bands post to different routes on purpose — saveBands()
     * argues it, and they mean different things to the estate. Two position:sticky bars would
     * pin to bottom:0 and overlap, so .bmc-savebar--inline is what keeps each one in its own
     * card. If a later edit merges the forms or drops the modifier, this is where it shows.
     */
    public function testEachSettingsFormCarriesItsOwnInlineSaveBar(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $crawler = $client->request('GET', '/admin', ['routeName' => 'admin_location_config']);

        self::assertResponseIsSuccessful();
        self::assertCount(
            2,
            $crawler->filter('form[data-bmc-form] .bmc-savebar--inline'),
            'one inline save bar inside each of the two settings forms',
        );
        self::assertCount(
            0,
            $crawler->filter('.bmc-savebar:not(.bmc-savebar--inline)'),
            'this page must not add a page-level sticky save bar',
        );
    }

    /** The two actions are not settings, so they must stay outside the dirty-tracked forms. */
    public function testTheTopUpAndRecalculateControlsAreNotInsideASettingsForm(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $crawler = $client->request('GET', '/admin', ['routeName' => 'admin_location_config']);

        self::assertResponseIsSuccessful();
        self::assertCount(
            0,
            $crawler->filter('form[data-bmc-form] form[action*="admin_location_config_provision"]'),
            'the top-up must not be nested in a settings form',
        );
        self::assertSelectorExists('form[action*="admin_location_config_provision"]');
    }
}
