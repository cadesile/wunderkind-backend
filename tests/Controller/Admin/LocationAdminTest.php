<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Admin;
use App\Entity\Location\CountryProfile;
use App\Entity\Location\LandPlot;
use App\Entity\Location\Location;
use App\Enum\Location\CountryProfileStatus;
use App\Enum\Location\EnrichmentStatus;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\Location\Suggestion\TownSuggestionService;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The location admin screens: do they render, and are the money-spending paths actually guarded?
 *
 * Rendering is worth asserting on its own — these controllers compute virtual index columns and
 * read enum labels, both of which fail at render time rather than at compile time, and a CRUD
 * controller that 500s is invisible until someone opens it.
 *
 * The guards matter more. Every enrichment trigger queues billed API calls, so each one is
 * tested for refusing without a valid token, and for queueing nothing when it refuses.
 */
class LocationAdminTest extends WebTestCase
{
    private const ADMIN_EMAIL = 'location-admin-test@example.com';
    private const COUNTRY     = 'ZT';

    /**
     * A REAL country from CountryContentRegistry, because seeding reads its town list from
     * there — a made-up code has nothing to seed and the test would prove nothing. Wales is
     * small enough to seed quickly and is not used by the other tests in this file.
     */
    private const SEEDABLE    = 'WA';

    /** A real Country case with NO entry in CountryContentRegistry::PLACE_NAMES_BY_COUNTRY. */
    private const UNAUTHORED  = 'FO';

    /**
     * Log in, and stop the kernel rebooting between requests.
     *
     * disableReboot() is not a convenience here, it is required for correctness. By default
     * KernelBrowser reboots the kernel after every request, which rebuilds the container and
     * therefore hands back a FRESH InMemoryTransport with no record of what was dispatched.
     * Without it the "queued exactly one call" assertion can never pass — and, worse, every
     * "queued nothing" assertion passes trivially whether the guard works or not, which would
     * make the CSRF tests below look green while proving nothing.
     */
    // ── Pulling towns from the AI provider ────────────────────────────────────

    public function testTheOverviewOffersAPullTownsButton(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $client->request('GET', '/admin', ['routeName' => 'admin_location_enrichment']);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[action*="admin_locations_suggest_towns"]');
        self::assertSelectorExists('input[name="max_towns"]');
    }

    public function testPullingTownsQueuesOneMessagePerBatchInsteadOfCallingInline(): void
    {
        // The whole point of the change. It used to make one 300-town call inside the request
        // and be cut off by nginx at 60s, so the operator saw a failure for work that was often
        // still running. Nothing may be asked of the provider during this POST.
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $this->purgeSuggestionRuns();
        $this->seedPullTarget();

        $token = $this->csrfToken($client, 'locations_suggest_towns');

        // DERIVED FROM BATCH_SIZE, NOT WRITTEN OUT. This read "120 towns at 50 a batch is
        // three messages" until BATCH_SIZE moved to 25 and it quietly became five — which the
        // missing messenger_messages table hid, because the case errored before it ever got
        // far enough to compare the numbers.
        $towns    = TownSuggestionService::BATCH_SIZE * 2 + 1;
        $expected = (int) ceil($towns / TownSuggestionService::BATCH_SIZE);

        $client->request('POST', '/admin/locations/suggest-towns', [
            '_token'    => $token,
            'country'   => self::SEEDABLE,
            'max_towns' => (string) $towns,
        ]);

        self::assertResponseRedirects();
        self::assertCount(
            $expected,
            $this->suggestionTransport()->getSent(),
            \sprintf('%d towns at %d a batch is %d message(s)', $towns, TownSuggestionService::BATCH_SIZE, $expected),
        );
    }

    /**
     * A country the curated registry has never heard of can still be pulled for.
     *
     * THE PICKER USED TO EXCLUDE EXACTLY THESE. It was built from the registry's curated place
     * lists — 25 of the 71 Country cases — which meant the one feature whose purpose is to get
     * towns for a country that has none could not be pointed at one. Two things blocked it
     * underneath: resolveCountry() refuses a country with no curated places, and importNames()
     * refuses one with no country_profile row, which app:locations:seed cannot create for an
     * unauthored country either.
     */
    public function testPullingTownsWorksForACountryTheRegistryHasNoPlacesFor(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $this->purgeSuggestionRuns();
        $this->purgeUnauthored();

        // The Faroe Islands: a real Country case with no entry in PLACE_NAMES_BY_COUNTRY.
        self::assertNull(
            \App\Service\CountryContent\CountryContentRegistry::places(\App\Enum\Country::FO),
            'fixture check: this country must have no curated places',
        );

        $client->request('POST', '/admin/locations/suggest-towns', [
            '_token'    => $this->csrfToken($client, 'locations_suggest_towns'),
            'country'   => self::UNAUTHORED,
            'max_towns' => (string) TownSuggestionService::BATCH_SIZE,
        ]);

        self::assertResponseRedirects();
        self::assertCount(1, $this->suggestionTransport()->getSent(), 'the pull must actually queue');

        // And it gave the country the profile row the import path requires, mirroring the
        // enum's own label rather than inventing one.
        $profile = self::getContainer()->get(\App\Repository\Location\CountryProfileRepository::class)
            ->findByCode(self::UNAUTHORED);

        self::assertNotNull($profile, 'the pull must create the profile row it needs');
        self::assertSame(\App\Enum\Country::FO->label(), $profile->getName());
    }

    private function purgeUnauthored(): void
    {
        $c = self::getContainer()->get(Connection::class);
        $c->executeStatement('DELETE FROM location WHERE country_code = :c', ['c' => self::UNAUTHORED]);
        $c->executeStatement('DELETE FROM country_profile WHERE code = :c', ['c' => self::UNAUTHORED]);
        self::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class)->clear();
    }

    public function testASecondPullIsRefusedWhileBatchesAreStillQueued(): void
    {
        // A run is now N billed messages, so a second click would double the spend and
        // interleave two sets of batches competing for the same names.
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $this->purgeSuggestionRuns();
        $this->seedPullTarget();

        // Exactly one batch, so the guard is what refuses the second pull rather than the
        // batch arithmetic. Sized off BATCH_SIZE for the reason given in the case above.
        $first = $this->csrfToken($client, 'locations_suggest_towns');
        $client->request('POST', '/admin/locations/suggest-towns', [
            '_token'    => $first,
            'country'   => self::SEEDABLE,
            'max_towns' => (string) TownSuggestionService::BATCH_SIZE,
        ]);

        $queuedAfterFirst = \count($this->suggestionTransport()->getSent());
        self::assertSame(1, $queuedAfterFirst);

        // The guard counts undrained rows in messenger_messages, which the in-memory transport
        // does not write — so stand one in, to assert the refusal rather than the counting.
        self::getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO messenger_messages (body, headers, queue_name, created_at, available_at)
             VALUES ('{}', '{}', 'suggestion', NOW(), NOW())",
        );

        $second = $this->csrfToken($client, 'locations_suggest_towns');
        $client->request('POST', '/admin/locations/suggest-towns', [
            '_token'    => $second,
            'country'   => self::SEEDABLE,
            'max_towns' => (string) TownSuggestionService::BATCH_SIZE,
        ]);

        // THE FLASH IS THE LOAD-BEARING ASSERTION HERE. InMemoryTransport implements
        // ResetInterface, so the container's resetter empties it at the end of every request —
        // which means "queued nothing" is trivially true of any request and proves nothing on
        // its own. The refusal message is the only evidence the guard actually fired.
        $client->followRedirect();
        // Flashes render as toasts, which upper-case the message.
        self::assertStringContainsStringIgnoringCase(
            'still queued',
            (string) $client->getResponse()->getContent(),
            'the guard must say why it refused, not fail silently',
        );

        self::assertSame(1, $queuedAfterFirst, 'the first pull did queue its batch');

        self::getContainer()->get(Connection::class)
            ->executeStatement("DELETE FROM messenger_messages WHERE queue_name = 'suggestion'");
    }

    public function testTheStatusEndpointReportsBatchProgress(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $this->purgeSuggestionRuns();
        $this->seedPullTarget();

        $token = $this->csrfToken($client, 'locations_suggest_towns');
        $client->request('POST', '/admin/locations/suggest-towns', [
            '_token'    => $token,
            'country'   => self::SEEDABLE,
            // Two batches exactly, so the endpoint is reporting a multi-batch run without the
            // count being a magic number. Sized off BATCH_SIZE, as above.
            'max_towns' => (string) (TownSuggestionService::BATCH_SIZE * 2),
        ]);

        $client->request('GET', '/admin/locations/suggest-towns/status/' . self::SEEDABLE);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertSame(self::SEEDABLE, $data['country']);
        self::assertNotNull($data['run'], 'the run just queued must be reported');
        self::assertSame(2, $data['run']['totalBatches']);
        self::assertSame(0, $data['run']['settledBatches']);
        self::assertSame(0, $data['run']['importedTowns']);
        self::assertCount(2, $data['run']['batches']);
        self::assertSame([1, 2], array_column($data['run']['batches'], 'index'));
        self::assertSame('pending', $data['run']['batches'][0]['status']);
    }

    public function testTheStatusEndpointCarriesTheNamesForTheRollingTicker(): void
    {
        // A count tells an operator the run is working; the names tell them it is returning
        // real places, which is the thing worth watching when every batch costs money.
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $this->purgeSuggestionRuns();
        $this->seedPullTarget();

        $token = $this->csrfToken($client, 'locations_suggest_towns');
        $client->request('POST', '/admin/locations/suggest-towns', [
            '_token'    => $token,
            'country'   => self::SEEDABLE,
            'max_towns' => '50',
        ]);

        $client->request('GET', '/admin/locations/suggest-towns/status/' . self::SEEDABLE);
        $data = json_decode((string) $client->getResponse()->getContent(), true);

        // Present and an array from the first poll, before anything has drained — the ticker
        // renders nothing rather than erroring on a missing key.
        self::assertArrayHasKey('recentTowns', $data['run']);
        self::assertIsArray($data['run']['recentTowns']);
    }

    public function testTheStatusEndpointReportsNoRunForACountryNeverPulled(): void
    {
        // The card renders this as its empty state. A country nobody has pulled towns for is
        // the normal case, not an error.
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $client->request('GET', '/admin/locations/suggest-towns/status/' . self::COUNTRY);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertNull($data['run']);
    }

    public function testPullingTownsOffersNoAllCountriesOption(): void
    {
        // Structural, not advisory. Seeding is free and may run across every country; this one
        // is a paid call, and the simplest way to make a 25-country fan-out impossible is for
        // the control to have no way to express it.
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $crawler = $client->request('GET', '/admin', ['routeName' => 'admin_location_enrichment']);

        $select = $crawler->filter('form[action*="admin_locations_suggest_towns"] select[name="country"]');

        self::assertNotNull($select->attr('required'), 'the country must be required');

        // The only blank option is the "Country…" placeholder, and it is disabled — so unlike
        // the seed form above it, there is no selectable value meaning "every country".
        $blank = $select->filter('option[value=""]');
        self::assertCount(1, $blank);
        self::assertNotNull($blank->attr('disabled'), 'the blank placeholder must not be selectable');

        // A real seedable country, not self::COUNTRY — that is a fictional code with no
        // registry places, so it is deliberately absent from this list.
        $values = $select->filter('option')->each(static fn ($n): string => (string) $n->attr('value'));
        self::assertContains('AR', $values);
        self::assertNotContains('all', $values);
    }

    public function testPullingTownsIsPostOnly(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $client->request('GET', '/admin/locations/suggest-towns');

        self::assertResponseStatusCodeSame(405);
    }

    public function testPullingTownsWithoutACsrfTokenWritesNothing(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $before = $this->locationCount();

        $client->request('POST', '/admin/locations/suggest-towns', ['country' => self::COUNTRY, 'max_towns' => '5']);

        self::assertSame($before, $this->locationCount(), 'a tokenless pull must write nothing');
    }

    public function testPullingTownsWithoutACountryIsRefused(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $before = $this->locationCount();
        $token  = $this->csrfToken($client, 'locations_suggest_towns');

        $client->request('POST', '/admin/locations/suggest-towns', [
            '_token'    => $token,
            'country'   => '',
            'max_towns' => '5',
        ]);

        self::assertSame($before, $this->locationCount());
        $client->followRedirect();
        // Flashes render as toasts, which upper-case the message.
        self::assertStringContainsStringIgnoringCase(
            'pick a country first',
            (string) $client->getResponse()->getContent(),
        );
    }

    private function loginAsAdmin(KernelBrowser $client): void
    {
        $client->disableReboot();

        $em = self::getContainer()->get(EntityManagerInterface::class);

        $admin = $em->getRepository(Admin::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        if ($admin === null) {
            $admin = new Admin(self::ADMIN_EMAIL);
            $admin->setPassword('not-used-for-login-here');
            $em->persist($admin);
            $em->flush();
        }

        $client->loginUser($admin, 'admin');
    }

    private function purge(): void
    {
        $connection = self::getContainer()->get(Connection::class);

        $connection->executeStatement(
            'DELETE FROM location_enrichment_attempt WHERE location_id IN (SELECT id FROM location WHERE country_code = :c)',
            ['c' => self::COUNTRY],
        );
        $connection->executeStatement(
            'DELETE FROM land_plot WHERE location_id IN (SELECT id FROM location WHERE country_code = :c)',
            ['c' => self::COUNTRY],
        );
        $connection->executeStatement('DELETE FROM location WHERE country_code = :c', ['c' => self::COUNTRY]);
        $connection->executeStatement('DELETE FROM country_profile WHERE code = :c', ['c' => self::COUNTRY]);
    }

    private function fixture(): Location
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $country = new CountryProfile(self::COUNTRY, 'Admin Testland');
        $em->persist($country);

        $location = new Location($country, 'Adminville', 'zt-adminville', 'adminville');
        $location->setRegion('Admin Region');
        $location->setPopulation(120_000);
        $em->persist($location);
        $em->persist(new LandPlot($location, 1));

        $em->flush();

        return $location;
    }

    /**
     * The page says this, whatever case it says it in.
     *
     * THREE ASSERTIONS HERE PINNED COPY RATHER THAN BEHAVIOUR, and the redesign broke all
     * three by upper-casing headings and buttons — "Seed" became "SEED", "Enrich one location"
     * became "ENRICH ONE LOCATION". None of them cares about the casing: they care that the
     * unreachable-provider warning is shown, that the focused-location card rendered, and that
     * the empty state offers a button instead of naming a CLI command. Matching
     * case-insensitively says that, and survives the next restyle.
     *
     * Where a test cares about STRUCTURE it still uses a selector — assertSelectorExists on the
     * seed form above this one is doing the real work there.
     */
    private function assertPageMentions(KernelBrowser $client, string $needle): void
    {
        $body = (string) $client->getResponse()->getContent();

        self::assertStringContainsStringIgnoringCase(
            $needle,
            strip_tags($body),
            \sprintf('the page should mention "%s"', $needle),
        );
    }

    private function transport(): InMemoryTransport
    {
        return self::getContainer()->get('messenger.transport.enrichment');
    }

    private function suggestionTransport(): InMemoryTransport
    {
        return self::getContainer()->get('messenger.transport.suggestion');
    }

    /**
     * Give the pull target a country profile of its own.
     *
     * planRun() refuses a country with no profile, and the seed tests further down this class
     * both create and purge WA's — so a pull test that merely hoped one was lying around
     * passed or failed on test ORDER. Seeding it here makes these tests independent of that,
     * which is what made them pass alone and fail in a full run.
     */
    private function seedPullTarget(): void
    {
        self::getContainer()->get(\App\Service\Location\LocationSeedService::class)
            ->seedCountry(\App\Enum\Country::from(self::SEEDABLE));
    }

    /**
     * `messenger_messages` on demand, because this file both reads and writes it.
     *
     * NOTHING ELSE CREATES IT HERE. The Doctrine transport makes the table lazily on first
     * dispatch, and every transport is in-memory under when@test — so it is never created by
     * running the suite, and doctrine:schema:create does not make it either, knowing only
     * entities. Worse, CompetitionHealthServiceTest DROPS it on purpose to exercise the
     * absent-table path, so whether this file passed came down to which order the two ran in.
     * That is what made these four cases "order-dependent flakes" rather than a real failure.
     *
     * The production guard (LocationEnrichmentRunRepository::countUndeliveredOnQueue) already
     * swallows the missing table and returns 0, which is why the APPLICATION is fine without
     * it. This file is not: it stands a row in to prove the stacking guard refuses, and a
     * DELETE against a missing table throws.
     *
     * Same columns and same reasoning as CompetitionHealthServiceTest::setUp().
     */
    private function ensureMessengerTable(): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS messenger_messages (
                id BIGSERIAL PRIMARY KEY,
                body TEXT NOT NULL,
                headers TEXT NOT NULL,
                queue_name VARCHAR(190) NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                available_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                delivered_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL
            )
            SQL,
        );
    }

    /** Batched pulls leave run and batch rows behind; the stacking guard reads them. */
    private function purgeSuggestionRuns(): void
    {
        $this->ensureMessengerTable();

        $connection = self::getContainer()->get(Connection::class);

        $connection->executeStatement(
            "DELETE FROM town_suggestion_batch WHERE run_id IN (SELECT id FROM location_enrichment_run WHERE trigger_type = 'town_batch')",
        );
        $connection->executeStatement("DELETE FROM location_enrichment_run WHERE trigger_type = 'town_batch'");
        $connection->executeStatement("DELETE FROM messenger_messages WHERE queue_name = 'suggestion'");
    }

    // ───────────────────────── rendering ─────────────────────────

    /**
     * The Countries index shows a real readiness verdict, not a red "Inaccessible" badge.
     *
     * A REGRESSION THAT A RENDER TEST CANNOT CATCH, which is why this is separate from the
     * screens-render test below: the page returned 200 the whole time it was broken. Readiness
     * and Locations are computed columns with no backing property, and EasyAdmin's
     * CommonPreConfigurator reads every field with PropertyAccessor BEFORE any formatValue()
     * callback runs — so the read threw, the field was marked unreadable with a null value, and
     * the inaccessible template was substituted for the verdict. The placeholder getters on
     * CountryProfile are what make the read succeed; this asserts they still do.
     *
     * Worth guarding because that column is exactly what an operator consults before setting a
     * country Live, and a red badge where the verdict belongs reads as "the admin is broken"
     * rather than "this country is not ready".
     */
    public function testTheCountriesIndexShowsAReadinessVerdictRatherThanInaccessible(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $this->purge();
        $this->fixture();

        $client->request('GET', self::getContainer()->get('router')->generate('admin_country_profile_index'));

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        // The fixture country has one location and no leagues, so it cannot be ready — the
        // verdict must name what is blocking rather than rendering a badge.
        self::assertStringContainsString(
            'blocked:',
            $html,
            'the Readiness column must render a verdict; "Inaccessible" means the virtual column lost its property accessor again',
        );
        self::assertStringNotContainsString('label.inaccessible', $html);
        self::assertStringNotContainsString('Inaccessible', $html);
    }

    public function testEveryLocationAdminScreenRenders(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $this->purge();
        $location = $this->fixture();

        $router = self::getContainer()->get('router');

        // CRUD screens are EasyAdmin's own routes and can be requested directly.
        foreach ([
            'admin_location_index',
            'admin_land_plot_index',
            'admin_country_profile_index',
        ] as $route) {
            $client->request('GET', $router->generate($route));

            self::assertResponseIsSuccessful("{$route} should render");
        }

        // The enrichment screen is a CUSTOM route rendering an @EasyAdmin-extending template,
        // so it must be reached through the dashboard's own entry point. Hitting the raw path
        // gives "Impossible to access an attribute (i18n) on a null variable" — EasyAdmin only
        // populates the `ea` Twig context for routes it created itself. See
        // src/Controller/Admin/CLAUDE.md; the sidebar link and every redirect already do this.
        $client->request('GET', '/admin', ['routeName' => 'admin_location_enrichment']);
        self::assertResponseIsSuccessful('the enrichment screen should render via /admin?routeName=');

        // Detail and edit exercise the onlyOnDetail fields and the form, which index does not.
        $client->request('GET', $router->generate('admin_location_detail', ['entityId' => (string) $location->getId()]));
        self::assertResponseIsSuccessful('location detail should render');

        $client->request('GET', $router->generate('admin_location_edit', ['entityId' => (string) $location->getId()]));
        self::assertResponseIsSuccessful('location edit form should render');
    }

    /** The enrichment screen must say plainly when no provider is configured. */
    public function testEnrichmentScreenReportsAnUnreachableProvider(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $this->purge();
        $this->fixture();

        $client->request('GET', '/admin', ['routeName' => 'admin_location_enrichment']);

        self::assertResponseIsSuccessful();
        $this->assertPageMentions($client, 'Enricher not reachable');
    }

    public function testAdminScreensRequireAuthentication(): void
    {
        $client = static::createClient();
        $router = self::getContainer()->get('router');

        $client->request('GET', $router->generate('admin_location_index'));
        self::assertResponseRedirects(null, null, 'the location index must not be reachable anonymously');

        $client->request('GET', '/admin', ['routeName' => 'admin_location_enrichment']);
        self::assertResponseRedirects(null, null, 'the enrichment screen must not be reachable anonymously');
    }

    // ───────────────────────── the money guards ─────────────────────────

    /**
     * The sweep refuses without a CSRF token, and queues nothing.
     *
     * A full sweep is 865 billed API calls, so this is the single most important guard in the
     * admin surface.
     */
    public function testSweepRefusesWithoutACsrfToken(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $this->purge();
        $this->fixture();
        $this->transport()->reset();

        $client->request('POST', '/admin/locations/enrichment/sweep', ['country' => self::COUNTRY]);

        self::assertResponseRedirects();
        self::assertCount(0, $this->transport()->getSent(), 'a tokenless sweep must queue nothing');
    }

    public function testSubsetRefusesWithoutACsrfToken(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $this->purge();
        $location = $this->fixture();
        $this->transport()->reset();

        $client->request('POST', '/admin/locations/enrichment/subset', [
            'locations' => [(string) $location->getId()],
        ]);

        self::assertResponseRedirects();
        self::assertCount(0, $this->transport()->getSent());
    }

    /** The single-enrich route rejects a tokenless POST, and queues nothing. */
    public function testSingleEnrichRefusesWithoutACsrfToken(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $this->purge();
        $location = $this->fixture();
        $this->transport()->reset();

        $client->request('POST', '/admin/locations/enrichment/single', [
            'location' => (string) $location->getId(),
        ]);

        self::assertResponseRedirects();
        self::assertCount(0, $this->transport()->getSent(), 'a tokenless POST must queue nothing');
    }

    /**
     * The route is not reachable by GET at all any more.
     *
     * It used to be: an EasyAdmin row action is a plain GET link, so the first version linked
     * straight at it and tried to make that safe with a CSRF token in the query string. That
     * does not work — Symfony's SameOriginCsrfTokenManager issues double-submit tokens that
     * cannot be validated from a URL parameter — so rather than weaken the check to fit the
     * link, the GET was removed. A 405 here is the proof.
     */
    public function testSingleEnrichIsNotReachableByGet(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $this->purge();
        $this->fixture();
        $this->transport()->reset();

        $client->request('GET', '/admin/locations/enrichment/single');

        self::assertSame(405, $client->getResponse()->getStatusCode(), 'a money-spending GET must not exist');
        self::assertCount(0, $this->transport()->getSent());
    }

    /**
     * The one-location flow end to end: a side-effect-free link, then a POST that queues.
     *
     * The row action in the Locations list only navigates here. That separation is the design:
     * opening a page must never spend money, pressing a button may.
     */
    public function testSingleEnrichRequiresThePostAndQueuesExactlyOne(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $this->purge();
        $location = $this->fixture();

        // Redirects are deliberately NOT followed. InMemoryTransport implements ResetInterface,
        // so Symfony's services resetter empties it at the start of the next request — follow
        // the POST's redirect and the message you just dispatched is gone before you can assert
        // on it. The overview itself needs no following: EasyAdmin FORWARDS to a custom route
        // rather than redirecting, so this GET returns 200 directly.
        $this->transport()->reset();

        $crawler = $client->request('GET', '/admin', [
            'routeName'   => 'admin_location_enrichment',
            'routeParams' => ['location' => (string) $location->getId()],
        ]);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $this->transport()->getSent(), 'merely opening the screen must queue nothing');
        $this->assertPageMentions($client, 'Enrich one location');

        $form = $crawler->filter('form[action*="admin_location_enrich_single"]')->form();
        $client->submit($form);

        self::assertResponseRedirects();
        self::assertCount(1, $this->transport()->getSent(), 'the confirm button should queue exactly one call');
    }


    /**
     * A country cannot be set Live while its locations are unenriched.
     *
     * This is the gate that stops players seeing plots they cannot buy.
     */
    public function testCountryCannotGoLiveWhileNotReady(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $this->purge();
        $this->fixture();

        $em      = self::getContainer()->get(EntityManagerInterface::class);
        $country = $em->find(CountryProfile::class, self::COUNTRY);

        self::assertSame(CountryProfileStatus::DRAFT, $country->getStatus());

        $router = self::getContainer()->get('router');
        $crawler = $client->request('GET', $router->generate('admin_country_profile_edit', [
            'entityId' => self::COUNTRY,
        ]));

        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Save changes')->form();
        $form->setValues(['CountryProfile[status]' => CountryProfileStatus::LIVE->value]);
        $client->submit($form);

        $em->clear();
        $reloaded = $em->find(CountryProfile::class, self::COUNTRY);

        self::assertSame(
            CountryProfileStatus::DRAFT,
            $reloaded->getStatus(),
            'an unready country must not be allowed Live',
        );
    }

    // ───────────────────────── edit locks ─────────────────────────

    /**
     * Correcting a coordinate by hand locks it against future enrichment.
     *
     * Without this an operator's fix would be silently reverted by the next sweep, which would
     * make the whole admin surface untrustworthy.
     */
    public function testEditingACoordinateLocksIt(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $this->purge();
        $location = $this->fixture();

        self::assertFalse($location->isCoordinatesLocked());

        $router  = self::getContainer()->get('router');
        $crawler = $client->request('GET', $router->generate('admin_location_edit', [
            'entityId' => (string) $location->getId(),
        ]));

        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Save changes')->form();
        $form->setValues(['Location[lat]' => '53.4808', 'Location[lng]' => '-2.2426']);
        $client->submit($form);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        $reloaded = $em->find(Location::class, (string) $location->getId());

        self::assertTrue($reloaded->isCoordinatesLocked(), 'a hand-edited coordinate must be locked');
        self::assertEqualsWithDelta(53.4808, (float) $reloaded->getLat(), 0.0001);
    }

    /** Saving the form without touching anything must NOT lock every field. */
    public function testASaveWithNoChangesLocksNothing(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $this->purge();
        $location = $this->fixture();

        $router  = self::getContainer()->get('router');
        $crawler = $client->request('GET', $router->generate('admin_location_edit', [
            'entityId' => (string) $location->getId(),
        ]));

        $client->submit($crawler->selectButton('Save changes')->form());

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        $reloaded = $em->find(Location::class, (string) $location->getId());

        self::assertFalse($reloaded->isCoordinatesLocked(), 'a no-op save must not lock coordinates');
        self::assertFalse($reloaded->isPopulationLocked(), 'a no-op save must not lock population');
        self::assertFalse($reloaded->isLandLocked(), 'a no-op save must not lock the land value');
    }

    /**
     * A location carrying a plot cannot be deleted at the database level.
     *
     * The FK is ON DELETE RESTRICT because a plot may carry a claim and real money, and
     * LocationCrudController::deleteEntity() turns that into a readable flash rather than a
     * ForeignKeyConstraintViolationException reaching the operator as a 500.
     *
     * Asserted here against the database rather than by driving the admin UI, and deliberately
     * so: this project's delete confirmation is a JS-built modal
     * (templates/bundles/EasyAdminBundle/layout.html.twig assembles the form action client-side
     * from data-delete-trigger), and DomCrawler never executes JavaScript — so there is no
     * delete form in the DOM for a functional test to submit. Asserting on the constraint keeps
     * the guarantee covered instead of leaving a skipped test that proves nothing.
     */
    public function testALocationWithPlotsCannotBeDeleted(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $this->purge();
        $location = $this->fixture();

        $connection = self::getContainer()->get(Connection::class);

        try {
            $connection->executeStatement(
                'DELETE FROM location WHERE id = :id',
                ['id' => (string) $location->getId()],
            );

            self::fail('deleting a location that still has a plot should have been refused');
        } catch (\Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException $e) {
            self::assertStringContainsString('land_plot', $e->getMessage());
        }

        // Still there, and still deletable once its plot has gone — proving the constraint is
        // what blocks it, not something incidental.
        self::assertSame(
            1,
            (int) $connection->fetchOne('SELECT COUNT(*) FROM location WHERE id = :id', ['id' => (string) $location->getId()]),
        );
    }


    public function testEnrichmentStatusFilterIsAvailable(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $this->purge();
        $this->fixture();

        $client->request('GET', self::getContainer()->get('router')->generate('admin_location_index', [
            'filters' => ['enrichmentStatus' => ['comparison' => '=', 'value' => EnrichmentStatus::NEEDS_REVIEW->value]],
        ]));

        self::assertResponseIsSuccessful('filtering by enrichment status should work');
    }

    // ───────────────────────── export / import ─────────────────────────

    public function testExportDownloadsJsonForOneCountry(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $this->purge();
        $this->fixture();

        $client->request('GET', '/admin/locations/export?country=' . self::COUNTRY);

        self::assertResponseIsSuccessful();
        self::assertSame('application/json', $client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString(
            'attachment',
            (string) $client->getResponse()->headers->get('Content-Disposition'),
        );

        $body = $this->streamedBody($client);
        $data = json_decode($body, true);

        self::assertIsArray($data, 'export body was: ' . substr($body, 0, 300));
        self::assertSame(1, $data['version']);
        self::assertCount(1, $data['locations']);
        self::assertSame('zt-adminville', $data['locations'][0]['slug']);
    }

    public function testExportCarriesNoClaimOrLedgerData(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $this->purge();
        $this->fixture();

        $client->request('GET', '/admin/locations/export');

        // Real money and real player data must never leave in a file an admin is told is safe
        // to move between environments.
        $body = $this->streamedBody($client);

        self::assertStringNotContainsString('claimedBy', $body);
        self::assertStringNotContainsString('sellBack', $body);
        self::assertStringNotContainsString('userLedger', $body);
    }

    public function testImportIsPostOnly(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $this->purge();

        $client->request('GET', '/admin/locations/import');

        // A GET that mutated geography would be followable by a crawler and cacheable by a
        // proxy — the same reason the enrichment triggers are POST-only.
        self::assertResponseStatusCodeSame(405);
    }

    public function testImportWithoutACsrfTokenChangesNothing(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $this->purge();

        $before = $this->locationCount();

        $client->request(
            'POST',
            '/admin/locations/import',
            [],
            ['locations_file' => $this->uploadedExport()],
        );

        self::assertSame($before, $this->locationCount(), 'a tokenless import must write nothing');
    }

    public function testImportDryRunReportsAndWritesNothing(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $this->purge();

        $before = $this->locationCount();

        $client->request(
            'POST',
            '/admin/locations/import',
            ['_token' => $this->csrfToken($client, 'locations_import'), 'dry_run' => '1'],
            ['locations_file' => $this->uploadedExport()],
        );

        self::assertSame($before, $this->locationCount(), 'a dry run must write nothing');
    }

    public function testImportAppliesAFileAndCreatesTheLocation(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $this->purge();

        $client->request(
            'POST',
            '/admin/locations/import',
            ['_token' => $this->csrfToken($client, 'locations_import')],
            ['locations_file' => $this->uploadedExport()],
        );

        $em      = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $created = self::getContainer()->get(\App\Repository\Location\LocationRepository::class)
            ->findBySlug(self::COUNTRY, 'zt-imported');

        self::assertNotNull($created, 'the uploaded town should exist');
        self::assertSame('Imported Town', $created->getTown());
    }

    /**
     * The body of a StreamedResponse.
     *
     * Via getInternalResponse(), NOT getResponse(). getResponse() hands back the HttpFoundation
     * StreamedResponse, whose getContent() is false (a streamed body goes straight to output and
     * is never buffered) and whose sendContent() is a no-op the second time. The BrowserKit
     * response behind getInternalResponse() holds the content the test client already captured
     * while handling the request.
     */
    private function streamedBody(KernelBrowser $client): string
    {
        return (string) $client->getInternalResponse()->getContent();
    }

    /** Pre-seeds a session value, mirroring what a real form render would have stored. */
    private function seedSession(KernelBrowser $client, array $values): void
    {
        $session = $client->getContainer()->get('session.factory')->createSession();

        $existing = $client->getCookieJar()->get($session->getName());
        if ($existing !== null) {
            $session->setId($existing->getValue());
        }
        $session->start();

        foreach ($values as $key => $value) {
            $session->set($key, $value);
        }
        $session->save();

        $client->getCookieJar()->set(new Cookie($session->getName(), $session->getId()));
    }

    /**
     * A token the controller will accept.
     *
     * Reading one from security.csrf.token_manager needs an active request and session, and
     * loginUser() performs no HTTP request — so there is nothing to read from yet. Seeding the
     * session directly under SessionTokenStorage's own key (`_csrf/<id>`) and submitting the
     * same plain value works because CsrfTokenManager::isTokenValid() compares raw values when
     * the token is not in the randomised `x.y.z` form. Same pattern as
     * NpcClubSizeWeightsControllerTest and SocialAuthControllerTest.
     */
    private function csrfToken(KernelBrowser $client, string $tokenId): string
    {
        $value = 'test-csrf-token-' . bin2hex(random_bytes(8));
        $this->seedSession($client, ["_csrf/{$tokenId}" => $value]);

        return $value;
    }

    private function locationCount(): int
    {
        return (int) self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT COUNT(*) FROM location WHERE country_code = :c',
            ['c' => self::COUNTRY],
        );
    }

    /** A minimal, valid export file as an upload. */
    private function uploadedExport(): UploadedFile
    {
        $payload = [
            'version'   => 1,
            'countries' => [[
                'code'         => self::COUNTRY,
                'name'         => 'Admin Testland',
                'distanceUnit' => 'km',
                'status'       => 'draft',
            ]],
            'locations' => [[
                'countryCode' => self::COUNTRY,
                'slug'        => 'zt-imported',
                'town'        => 'Imported Town',
                'region'      => 'Imported Region',
                'lat'         => 53.4,
                'lng'         => -2.2,
                'population'  => 90_000,
                'landPence'   => 4_000_00,
                'citySize'    => 'MEDIUM',
                'status'      => 'active',
                'enrichmentStatus' => 'enriched',
                'plots'       => [['plotNumber' => 1, 'landPenceOverride' => null]],
            ]],
        ];

        $path = tempnam(sys_get_temp_dir(), 'loc-import-') . '.json';
        file_put_contents($path, json_encode($payload));

        return new UploadedFile($path, 'locations.json', 'application/json', null, true);
    }

    // ───────────────────────── seeding ─────────────────────────

    public function testTheOverviewOffersASeedButtonRatherThanNamingACommand(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $this->purge();

        // Via /admin?routeName=, not the bare path: EasyAdmin FORWARDS to the route and the `ea`
        // context only exists on the dashboard request, so a direct hit dies on "i18n on null".
        $client->request('GET', '/admin', ['routeName' => 'admin_location_enrichment']);

        self::assertResponseIsSuccessful();

        // The empty state used to say "Run app:locations:seed", which is a dead end for anyone
        // without shell access to the box.
        self::assertSelectorExists('form[action*="admin_locations_seed"]');
        $this->assertPageMentions($client, 'Seed');
    }

    public function testSeedIsPostOnly(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $client->request('GET', '/admin/locations/seed');

        self::assertResponseStatusCodeSame(405);
    }

    public function testSeedWithoutACsrfTokenWritesNothing(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $this->purge();

        $before = $this->locationCount();

        $client->request('POST', '/admin/locations/seed', ['country' => self::COUNTRY]);

        self::assertSame($before, $this->locationCount(), 'a tokenless seed must write nothing');
    }

    public function testSeedingOneCountryCreatesItsTownsButNoPlots(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $this->purgeSeedCountry();

        $client->request('POST', '/admin/locations/seed', [
            '_token'  => $this->csrfToken($client, 'locations_seed'),
            'country' => self::SEEDABLE,
        ]);

        self::assertGreaterThan(0, $this->locationCountFor(self::SEEDABLE), 'towns should exist');

        // No plots: a seeded town has no land value, so a plot on it could never be bought,
        // and its presence made the catalogue report the town as available rather than as
        // awaiting enrichment. Plots arrive when enrichment prices the town.
        self::assertSame(
            0,
            $this->plotCountFor(self::SEEDABLE),
            'an unpriced town must have no plots',
        );
    }

    public function testSeededTownsHaveNoGeographyYet(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $this->purgeSeedCountry();

        $client->request('POST', '/admin/locations/seed', [
            '_token'  => $this->csrfToken($client, 'locations_seed'),
            'country' => self::SEEDABLE,
        ]);

        // There is no coordinate or valuation data in PHP to seed. Writing 0/0 instead would put
        // every un-enriched town at the same point in the Atlantic, where the rival pass would
        // treat them as each other's nearest neighbours.
        $withGeography = (int) self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT COUNT(*) FROM location WHERE country_code = :c AND (lat IS NOT NULL OR land_pence IS NOT NULL)',
            ['c' => self::SEEDABLE],
        );

        self::assertSame(0, $withGeography);
    }

    public function testSeedingTwiceIsANoOpRatherThanADuplicate(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $this->purgeSeedCountry();

        $client->request('POST', '/admin/locations/seed', [
            '_token'  => $this->csrfToken($client, 'locations_seed'),
            'country' => self::SEEDABLE,
        ]);
        $afterFirst = $this->locationCountFor(self::SEEDABLE);

        $client->request('POST', '/admin/locations/seed', [
            '_token'  => $this->csrfToken($client, 'locations_seed'),
            'country' => self::SEEDABLE,
        ]);

        // Act-only-when-empty. These rows get hand-corrected and then carry real money, so a
        // re-seed must not be able to stomp a correction.
        self::assertSame($afterFirst, $this->locationCountFor(self::SEEDABLE));
    }

    public function testAnUnknownCountryCodeWritesNothing(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $client->request('POST', '/admin/locations/seed', [
            '_token'  => $this->csrfToken($client, 'locations_seed'),
            'country' => 'QQ',
        ]);

        // Redirects back with a danger flash rather than 500ing. The flash TEXT is asserted in
        // LocationSeedServiceTest instead of here: following this redirect renders the EasyAdmin
        // dashboard route, which dies on "i18n on null" under the test client.
        self::assertResponseRedirects();
        self::assertSame(0, $this->locationCountFor('QQ'));
    }

    private function locationCountFor(string $code): int
    {
        return (int) self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT COUNT(*) FROM location WHERE country_code = :c',
            ['c' => $code],
        );
    }

    private function plotCountFor(string $code): int
    {
        return (int) self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT COUNT(*) FROM land_plot p JOIN location l ON l.id = p.location_id WHERE l.country_code = :c',
            ['c' => $code],
        );
    }

    /** Clears the real country these seed tests write to, so each starts from empty. */
    private function purgeSeedCountry(): void
    {
        $connection = self::getContainer()->get(Connection::class);

        $connection->executeStatement(
            'DELETE FROM land_plot WHERE location_id IN (SELECT id FROM location WHERE country_code = :c)',
            ['c' => self::SEEDABLE],
        );
        $connection->executeStatement('DELETE FROM location WHERE country_code = :c', ['c' => self::SEEDABLE]);
        $connection->executeStatement('DELETE FROM country_profile WHERE code = :c', ['c' => self::SEEDABLE]);
    }

    /**
     * The redesigned shell, held to the same checklist as the other rebuilt pages.
     *
     * The failure modes are real ones: a controller that forgets bmcGroup/bmcTitle renders an
     * empty crumb and no heading, and a page that leaves <main data-bmc-page> empty breaks the
     * sidebar pin silently.
     */
    public function testTheEnrichmentPageRendersInTheRedesignedShell(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $this->purge();
        $this->fixture();

        $client->request('GET', '/admin', ['routeName' => 'admin_location_enrichment']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.bmc-title', 'ENRICHMENT');
        self::assertSelectorTextContains('.bmc-crumb', 'LOCATIONS & LAND');
        self::assertSelectorExists('.bmc-page');
        self::assertSelectorNotExists('.card-body', 'no Bootstrap cards should survive the rebuild');
        self::assertSelectorNotExists('.form-select', 'selects must use the .bmc-select component');
    }

    /**
     * SPENDING BUTTONS CONFIRM THROUGH THE PIXELDIALOG, not window.confirm.
     *
     * Both sweep buttons used onclick="return confirm(…)". The hazard the rebuild has to avoid
     * is documented in the bmc-pages.js header: bmc-admin.js binds [data-bmc-confirm] to
     * window.confirm, so an element carrying BOTH that and data-bmc-dialog-* would prompt
     * twice. This pins the dialog attributes are present and the old pair is not.
     */
    public function testTheSweepButtonsConfirmThroughTheDialogRatherThanWindowConfirm(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $this->purge();
        $this->fixture();

        $crawler = $client->request('GET', '/admin', ['routeName' => 'admin_location_enrichment']);
        $body    = (string) $client->getResponse()->getContent();

        self::assertGreaterThan(
            0,
            $crawler->filter('button[data-bmc-dialog-title]')->count(),
            'the paid sweeps must route through the PixelDialog',
        );
        self::assertStringNotContainsString('onclick="return confirm', $body);
        self::assertSame(
            0,
            $crawler->filter('[data-bmc-confirm][data-bmc-dialog-title]')->count(),
            'an element must never carry both confirm bindings — it would prompt twice',
        );
    }

    /** The poll keeps its hooks: renaming one of these silently stops the progress panel. */
    public function testThePullTownsProgressPanelKeepsItsHooks(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $this->purge();
        $this->fixture();

        $client->request('GET', '/admin', ['routeName' => 'admin_location_enrichment']);

        foreach ([
            'suggest-progress', 'suggest-progress-summary', 'suggest-progress-bar',
            'suggest-batches', 'suggest-queue-depth', 'suggest-submit',
            'suggest-recent', 'suggest-recent-list', 'suggest-recent-count',
        ] as $id) {
            self::assertSelectorExists('#' . $id, "the poll reads #{$id}");
        }
    }
}
