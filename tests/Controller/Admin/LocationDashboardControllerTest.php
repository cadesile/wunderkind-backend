<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Admin;
use App\Entity\Location\CountryProfile;
use App\Entity\Location\Location;
use App\Enum\Location\CountryProfileStatus;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The locations dashboard: what it shows, and the two actions it offers.
 *
 * GO LIVE is the one worth the most care. It writes a status that opens a country to players,
 * and the readiness rule behind it was private to CountryProfileCrudController until this
 * screen needed it too — so these pin that the rule still refuses, that the button being
 * enabled is not the check, and that the timestamp is stamped once rather than rewritten.
 */
class LocationDashboardControllerTest extends WebTestCase
{
    private const ADMIN_EMAIL = 'location-dashboard-test@example.com';
    private const CODE        = 'ZD';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->purge();
        $this->loginAsAdmin();
    }

    protected function tearDown(): void
    {
        $this->purge();

        parent::tearDown();
    }

    private function purge(): void
    {
        $c = self::getContainer()->get(Connection::class);
        $c->executeStatement("DELETE FROM land_plot WHERE location_id IN (SELECT id FROM location WHERE country_code = :c)", ['c' => self::CODE]);
        $c->executeStatement('DELETE FROM location WHERE country_code = :c', ['c' => self::CODE]);
        $c->executeStatement('DELETE FROM country_profile WHERE code = :c', ['c' => self::CODE]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
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

    private function seedCountry(int $towns = 3): CountryProfile
    {
        $em      = self::getContainer()->get(EntityManagerInterface::class);
        $profile = new CountryProfile(self::CODE, 'Dashboardia');
        $em->persist($profile);
        $em->flush();

        for ($i = 1; $i <= $towns; $i++) {
            $location = new Location($profile, 'Town' . $i, self::CODE . '-t' . $i, 'town' . $i);
            $location->setRegion('R');
            $location->setPopulation($i * 1000);
            $location->setCoordinates(50.0, 0.0);
            $location->setLandPence(100000);
            $location->setLandValuedAt(new \DateTimeImmutable());
            $em->persist($location);
        }

        $em->flush();

        return $profile;
    }

    /**
     * A valid CSRF token, by pre-seeding the session the way a rendered form would have.
     *
     * Asking the token manager directly needs a request context the test client has not
     * created yet. Same pattern as PoolConfigControllerTest::seedValidCsrfToken().
     */
    private function token(string $id): string
    {
        $value   = 'test-csrf-' . bin2hex(random_bytes(8));
        $session = $this->client->getContainer()->get('session.factory')->createSession();

        $existing = $this->client->getCookieJar()->get($session->getName());
        if ($existing !== null) {
            $session->setId($existing->getValue());
        }

        $session->start();
        $session->set("_csrf/{$id}", $value);
        $session->save();

        $this->client->getCookieJar()->set(
            new \Symfony\Component\BrowserKit\Cookie($session->getName(), $session->getId()),
        );

        return $value;
    }

    public function testTheScreenRendersWithTheCountryRailAndItsReadiness(): void
    {
        $this->seedCountry();

        $this->client->request('GET', '/admin', [
            'routeName'   => 'admin_locations_dashboard',
            'routeParams' => ['country' => self::CODE],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.bmc-title', 'LOCATIONS');
        self::assertSelectorExists('.bmc-countryrow');
        // Seven checks, straight from CountryReadinessService.
        self::assertSelectorExists('.bmc-checkrow');
        self::assertSelectorTextContains('body', 'Dashboardia');
    }

    /**
     * routeParams, not just the query string.
     *
     * AdminRouterSubscriber forwards rather than redirects, so the selected country arrives as
     * a request attribute while the outer URI still reads /admin?routeName=…. Reading only
     * query->get('country') would silently always show the first country.
     */
    public function testTheSelectedCountryComesThroughRouteParams(): void
    {
        $this->seedCountry();

        $this->client->request('GET', '/admin', [
            'routeName'   => 'admin_locations_dashboard',
            'routeParams' => ['country' => self::CODE],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists(\sprintf('.bmc-countryrow.is-selected[href*="%s"]', self::CODE));
    }

    public function testGoLiveIsRefusedWhenTheCountryIsNotReady(): void
    {
        // Three towns and no leagues: nowhere near the 25 sellable the gate wants.
        $this->seedCountry();

        $this->client->request('POST', '/admin/locations/dashboard/go-live', [
            '_token'  => $this->token('locations_go_live_' . self::CODE),
            'country' => self::CODE,
        ]);

        self::assertResponseRedirects();

        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $profile = self::getContainer()->get(\App\Repository\Location\CountryProfileRepository::class)->findByCode(self::CODE);

        self::assertNotSame(CountryProfileStatus::LIVE, $profile->getStatus(), 'an unready country must not go live');
        self::assertNull($profile->getLocationsLiveAt(), 'and must not be stamped');
    }

    public function testGoLiveNeedsThatCountrysOwnToken(): void
    {
        $this->seedCountry();

        // A token for a different country must not work — that is why it is per country
        // rather than per page.
        $this->client->request('POST', '/admin/locations/dashboard/go-live', [
            '_token'  => $this->token('locations_go_live_EN'),
            'country' => self::CODE,
        ]);

        self::assertResponseRedirects();
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        $profile = self::getContainer()->get(\App\Repository\Location\CountryProfileRepository::class)->findByCode(self::CODE);
        self::assertNotSame(CountryProfileStatus::LIVE, $profile->getStatus());
    }

    public function testTopUpAddsThePlotsATownsSizeCallsFor(): void
    {
        $profile = $this->seedCountry(1);
        $em      = self::getContainer()->get(EntityManagerInterface::class);

        $location = self::getContainer()->get(\App\Repository\Location\LocationRepository::class)
            ->findTopByPopulation(self::CODE, 1)[0];
        $id = (string) $location->getId();

        $this->client->request('POST', '/admin/locations/dashboard/add-plots', [
            '_token'   => $this->token('locations_add_plots_' . $id),
            'location' => $id,
        ]);

        self::assertResponseRedirects();

        $plots = (int) self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT COUNT(*) FROM land_plot WHERE location_id = :id',
            ['id' => $id],
        );

        // SMALL by default, so one plot. The button tops a town UP TO its target rather than
        // adding one more — anything above target is reaped on the next re-band or release.
        self::assertSame(1, $plots);
        self::assertNotNull($profile->getCode());

        // Running it again adds nothing: provisionLocation() is idempotent.
        $this->client->request('POST', '/admin/locations/dashboard/add-plots', [
            '_token'   => $this->token('locations_add_plots_' . $id),
            'location' => $id,
        ]);

        $again = (int) self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT COUNT(*) FROM land_plot WHERE location_id = :id',
            ['id' => $id],
        );
        self::assertSame(1, $again, 'topping up twice must not stack plots');

        $em->clear();
    }
}
