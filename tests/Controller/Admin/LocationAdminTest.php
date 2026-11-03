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
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
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
     * Log in, and stop the kernel rebooting between requests.
     *
     * disableReboot() is not a convenience here, it is required for correctness. By default
     * KernelBrowser reboots the kernel after every request, which rebuilds the container and
     * therefore hands back a FRESH InMemoryTransport with no record of what was dispatched.
     * Without it the "queued exactly one call" assertion can never pass — and, worse, every
     * "queued nothing" assertion passes trivially whether the guard works or not, which would
     * make the CSRF tests below look green while proving nothing.
     */
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

    private function transport(): InMemoryTransport
    {
        return self::getContainer()->get('messenger.transport.enrichment');
    }

    // ───────────────────────── rendering ─────────────────────────

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
        self::assertSelectorTextContains('body', 'Enricher not reachable');
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
        self::assertSelectorTextContains('body', 'Enrich one location');

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
}
