<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Admin;
use App\Entity\Location\CountryProfile;
use App\Entity\Location\LandPlot;
use App\Entity\Location\Location;
use App\Enum\Location\CountryProfileStatus;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** The guarded country delete page: it renders, needs the typed code, then deletes. */
class CountryDeleteControllerTest extends WebTestCase
{
    private const CODE        = 'ZE';
    private const ADMIN_EMAIL = 'country-delete-admin@test.local';

    public function testTheConfirmPageListsWhatGoesAndNeedsTheTypedCode(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $this->purge();
        $this->seed();

        $client->request('GET', '/admin/country-profile');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href*="admin_country_delete_confirm"]');

        $crawler = $client->request('GET', '/admin', [
            'routeName'   => 'admin_country_delete_confirm',
            'routeParams' => ['code' => self::CODE],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.delete-card', 'land plot(s)');

        $form = $crawler->filter('.delete-card form')->form();

        // Wrong code: nothing deleted.
        $form['confirmCode'] = 'XX';
        $client->submit($form);
        self::assertSame(1, $this->countryRows());

        $crawler = $client->request('GET', '/admin', [
            'routeName'   => 'admin_country_delete_confirm',
            'routeParams' => ['code' => self::CODE],
        ]);
        $form = $crawler->filter('.delete-card form')->form();
        $form['confirmCode'] = strtolower(self::CODE);
        $client->submit($form);

        self::assertResponseRedirects('/admin/country-profile');
        self::assertSame(0, $this->countryRows());

        $this->purge();
    }

    private function countryRows(): int
    {
        return (int) self::getContainer()->get(Connection::class)
            ->fetchOne('SELECT COUNT(*) FROM country_profile WHERE code = :c', ['c' => self::CODE]);
    }

    private function seed(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $profile = new CountryProfile(self::CODE, 'Test ' . self::CODE);
        $profile->setStatus(CountryProfileStatus::DRAFT);
        $em->persist($profile);

        $location = new Location($profile, 'Erase Town', 'ze-erase-town', 'erase town');
        $location->setRegion('R');
        $location->setPopulation(50_000);
        $em->persist($location);
        $em->persist(new LandPlot($location, 1));

        $em->flush();
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

    private function purge(): void
    {
        $c = self::getContainer()->get(Connection::class);
        $p = ['c' => self::CODE];

        $c->executeStatement('DELETE FROM land_plot WHERE location_id IN (SELECT id FROM location WHERE country_code = :c)', $p);
        $c->executeStatement('DELETE FROM location WHERE country_code = :c', $p);
        $c->executeStatement('DELETE FROM country_profile WHERE code = :c', $p);
    }
}
