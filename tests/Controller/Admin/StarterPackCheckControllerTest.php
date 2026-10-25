<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Admin;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Render smoke test for the starter-pack check screen. It asserts the page comes back,
 * not what the numbers are: the point of the screen is to report whatever the pool
 * currently holds, and the test database holds nothing, so there is no meaningful count
 * to assert. What IS worth guarding is that the empty-pool path renders at all — the
 * funnel table, the verdict banner and the findings list all have to survive a pool with
 * zero rows, which is the state every fresh environment starts in.
 */
class StarterPackCheckControllerTest extends WebTestCase
{
    private const TEST_ADMIN_EMAIL = 'starter-pack-check-test-admin@example.com';

    /**
     * The admin firewall's Doctrine EntityUserProvider re-fetches by email on every
     * request, so an in-memory-only Admin reads as unauthenticated — persist first.
     */
    private function loginAsAdmin(KernelBrowser $client): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $admin = $em->getRepository(Admin::class)->findOneBy(['email' => self::TEST_ADMIN_EMAIL]);
        if ($admin === null) {
            $admin = new Admin(self::TEST_ADMIN_EMAIL);
            $admin->setPassword('not-used-for-login-here');
            $em->persist($admin);
            $em->flush();
        }

        $client->loginUser($admin, 'admin');
    }

    public function testScreenRendersItsCountryPickerBeforeAnyCountryIsChosen(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        // Reached through EasyAdmin's entry point, not the bare route: AdminRouterSubscriber
        // only populates the `ea` Twig context for the dashboard's own /admin route.
        $crawler = $client->request('GET', '/admin', ['routeName' => 'admin_starter_pack_check']);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('select[name="country"]'));
        self::assertCount(1, $crawler->filter('select[name="tier"]'));
    }

    public function testCheckingACountryRendersTheFunnelAndAVerdict(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $crawler = $client->request('GET', '/admin', [
            'routeName' => 'admin_starter_pack_check',
            'country'   => 'US',
            'tier'      => 8,
        ]);

        self::assertResponseIsSuccessful();

        // One funnel row per position, each ending in a readable result badge, plus a
        // verdict banner at the top.
        self::assertCount(4, $crawler->filter('#spc-funnel tbody tr'));
        // Position + Needs + four funnel steps + Can draw + status: the funnel header is
        // built from the first row's own steps, so a miscounted header means the steps
        // never reached the template.
        self::assertCount(8, $crawler->filter('#spc-funnel thead th'));
        self::assertCount(4, $crawler->filter('#spc-funnel tbody tr .badge'));
        self::assertStringContainsString('starter pack', $crawler->filter('.alert')->first()->text());
    }

    public function testTheScreenIsNotPubliclyReadable(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin', ['routeName' => 'admin_starter_pack_check']);

        self::assertResponseStatusCodeSame(302, 'the admin firewall should redirect an anonymous request to the login form');
    }

    /**
     * The warm button is the one control on this screen that writes, so it only appears once a
     * check has been run — there is nothing to warm before a country is chosen, and binding it
     * to the rendered result keeps it off a dropdown the admin changed but never submitted.
     */
    public function testWarmButtonIsAbsentUntilACountryHasBeenChecked(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $crawler = $client->request('GET', '/admin?routeName=admin_starter_pack_check');
        self::assertResponseIsSuccessful();
        $this->assertCount(0, $crawler->filter('button:contains("Generate starter pack")'));

        $crawler = $client->request('GET', '/admin?routeName=admin_starter_pack_check&country=EN&tier=8');
        self::assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('button:contains("Generate starter pack")'));
    }

    public function testWarmRejectsAPostWithoutAValidCsrfToken(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $before = $this->playerCount();

        $client->request('POST', '/admin/starter-pack-check/warm', [
            '_token'  => 'wrong',
            'country' => 'EN',
            'tier'    => 8,
        ]);

        self::assertResponseRedirects();
        $this->assertSame($before, $this->playerCount(), 'A bad token must generate nothing.');
    }

    /** An unknown code must not reach app:pool:warm, which would warm nothing and report success. */
    public function testWarmRejectsACodeThatIsNotACountry(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $crawler = $client->request('GET', '/admin?routeName=admin_starter_pack_check&country=EN&tier=8');
        $token   = $crawler->filter('input[name="_token"]')->attr('value');

        $before = $this->playerCount();

        $client->request('POST', '/admin/starter-pack-check/warm', [
            '_token'  => $token,
            'country' => 'ZZ',
            'tier'    => 8,
        ]);

        self::assertResponseRedirects();
        $this->assertSame($before, $this->playerCount());
    }

    private function playerCount(): int
    {
        return (int) self::getContainer()->get(EntityManagerInterface::class)
            ->createQuery('SELECT COUNT(p.id) FROM App\\Entity\\Player p')
            ->getSingleScalarResult();
    }
}
