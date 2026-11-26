<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Admin;
use App\Entity\Location\CountryProfile;
use App\Entity\Location\LandPlot;
use App\Entity\Location\Location;
use App\Entity\Location\PlotClaim;
use App\Entity\User;
use App\Enum\Location\CountryProfileStatus;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Deleting a town from the Towns list, through EasyAdmin's own delete route.
 *
 * Courtney's rule (2026-11-26): enriched or not, a town goes unless a player has taken a plot.
 * Also guards the trap that caused the original report: the list's country column used to be
 * a link to the country's page, whose Delete deletes the whole country.
 */
class TownDeleteTest extends WebTestCase
{
    private const CODE        = 'ZF';
    private const ADMIN_EMAIL = 'town-delete-admin@test.local';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->login();
        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->purge();

        parent::tearDown();
    }

    public function testATownWithFreePlotsIsDeletedFromTheList(): void
    {
        [$location] = $this->town('Freeville');
        $id = (string) $location->getId();

        $token = $this->listAndToken();
        self::assertSelectorNotExists('table a[href*="country-profile"]', 'the Towns list must not link to the country page');

        $this->client->request('POST', "/admin/location/{$id}/delete", ['token' => $token]);

        self::assertResponseRedirects();
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM location WHERE id = :id', $id));
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM land_plot WHERE location_id = :id', $id));
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM country_profile WHERE code = :id', self::CODE));
    }

    public function testATownWithATakenPlotIsKept(): void
    {
        [$location, $plot] = $this->town('Takenville');
        $id = (string) $location->getId();

        $em   = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User('town-delete-' . bin2hex(random_bytes(5)) . '@test.local');
        $user->setPassword('x');
        $em->persist($user);
        $em->persist(new PlotClaim($plot, $user, 1_000_000));
        $em->flush();

        $token = $this->listAndToken();
        $this->client->request('POST', "/admin/location/{$id}/delete", ['token' => $token]);

        self::assertResponseRedirects();
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM location WHERE id = :id', $id));
    }

    // ───────────────────────── helpers ─────────────────────────

    private function listAndToken(): string
    {
        $crawler = $this->client->request('GET', '/admin/location');
        self::assertResponseIsSuccessful();

        return (string) $crawler->filter('#action-confirmation-form input[name="token"]')->attr('value');
    }

    /** @return array{Location, LandPlot} */
    private function town(string $name): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $profile = $em->find(CountryProfile::class, self::CODE);
        if ($profile === null) {
            $profile = new CountryProfile(self::CODE, 'Test ' . self::CODE);
            $profile->setStatus(CountryProfileStatus::DRAFT);
            $em->persist($profile);
        }

        $location = new Location($profile, $name, strtolower('zf-' . $name), strtolower($name));
        $location->setRegion('R');
        $location->setPopulation(80_000);
        $location->setCoordinates(52.0, 5.0);
        $location->setLandPence(1_000_000);
        $location->setLandValuedAt(new \DateTimeImmutable());
        $em->persist($location);

        $plot = new LandPlot($location, 1);
        $em->persist($plot);
        $em->persist(new LandPlot($location, 2));
        $em->flush();

        return [$location, $plot];
    }

    private function rows(string $sql, string $id): int
    {
        return (int) self::getContainer()->get(Connection::class)->fetchOne($sql, ['id' => $id]);
    }

    private function login(): void
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

    private function purge(): void
    {
        $c   = self::getContainer()->get(Connection::class);
        $p   = ['c' => self::CODE];
        $loc = 'SELECT id FROM location WHERE country_code = :c';

        $c->executeStatement("DELETE FROM plot_claim WHERE location_id IN ({$loc})", $p);
        $c->executeStatement("DELETE FROM \"user\" WHERE email LIKE 'town-delete-%@test.local'");
        $c->executeStatement("DELETE FROM land_plot WHERE location_id IN ({$loc})", $p);
        $c->executeStatement('DELETE FROM location WHERE country_code = :c', $p);
        $c->executeStatement('DELETE FROM country_profile WHERE code = :c', $p);
    }
}
