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
        self::getContainer()->get(Connection::class)->executeStatement('DELETE FROM location_config');
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
}
