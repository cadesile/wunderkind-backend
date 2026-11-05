<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Admin;
use App\Repository\StarterConfigRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

/**
 * The enrichment cap on the starter-config admin form.
 *
 * WHY THIS FILE EXISTS. The field is plumbed through three places that do not reference each
 * other — a Twig input `name`, a `$request->request->get()` key in the save handler, and an
 * entity setter. A typo in any one of them means the number an admin types is silently
 * discarded, the cap stays wherever it was, and nothing fails: the form redirects, the page
 * re-renders, and the only symptom is a spent API budget weeks later.
 *
 * That is the entire reason this is tested through the HTTP round trip rather than by calling
 * the setter. The setter was never the risky part.
 */
class StarterConfigEnrichmentCapTest extends WebTestCase
{
    private const TEST_ADMIN_EMAIL = 'starter-config-cap-admin@example.com';

    protected function tearDown(): void
    {
        // starter_config is a singleton row nothing purges, so a cap left behind here would
        // reach every later test file. Put it back to unlimited, which is the deployed default.
        self::getContainer()->get(Connection::class)
            ->executeStatement('UPDATE starter_config SET max_enrichment_requests_per_user = 0');

        parent::tearDown();
    }

    public function testTheFormRendersTheCurrentCap(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $this->setCap(37);

        // Custom admin pages must be reached via /admin?routeName=... — EasyAdmin only
        // populates the `ea` Twig context for the dashboard's own route, and this page's layout
        // reads it. Requesting /admin/starter-config directly fails on "i18n on null".
        $client->request('GET', '/admin?routeName=admin_starter_config');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('input[name="maxEnrichmentRequestsPerUser"][value="37"]');
    }

    public function testSavingTheFormPersistsTheCap(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $this->setCap(0);

        $this->submit($client, ['maxEnrichmentRequestsPerUser' => 12]);

        $this->assertSame(12, $this->cap(), 'the typed number must actually reach the column');
    }

    /**
     * Zero must survive the round trip as zero.
     *
     * The trap this guards: a save handler whose fallback for a missing field is a positive
     * number turns "unlimited" into a ceiling the admin never chose, and the form would then
     * show that number as though they had.
     */
    public function testZeroIsSavedAsUnlimitedRatherThanFallingBackToADefault(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $this->setCap(25);

        $this->submit($client, ['maxEnrichmentRequestsPerUser' => 0]);

        $this->assertSame(0, $this->cap());
        $this->assertTrue(
            self::getContainer()->get(StarterConfigRepository::class)->getConfig()
                ->enrichmentRequestsAreUnlimited(),
        );
    }

    /** A negative ceiling is a typo; it is clamped rather than refusing the whole form. */
    public function testANegativeCapIsClampedToUnlimited(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $this->submit($client, ['maxEnrichmentRequestsPerUser' => -5]);

        $this->assertSame(0, $this->cap());
    }

    // ───────────────────────────── plumbing ─────────────────────────────

    /**
     * Posts the starter-config form with $overrides merged over a minimal valid body.
     *
     * The save handler reads every field off the request with its own default, so a partial body
     * is accepted — but the fields below are the ones whose defaults would otherwise rewrite real
     * values, and sending them keeps this test from being a destructive one.
     */
    private function submit(KernelBrowser $client, array $overrides): void
    {
        $config = self::getContainer()->get(StarterConfigRepository::class)->getConfig();

        $token = $this->seedValidCsrfToken($client, 'save_starter_config');

        $client->request('POST', '/admin/starter-config/save', [
            '_token'                       => $token,
            'startingBalance'              => $config->getStartingBalancePounds(),
            'userStartingBalanceMinPounds'  => (int) round($config->getUserStartingBalanceMinPence() / 100),
            'userStartingBalanceMaxPounds'  => (int) round($config->getUserStartingBalanceMaxPence() / 100),
            'starterPlayerCount'           => $config->getStarterPlayerCount(),
            'starterSponsorTier'           => $config->getStarterSponsorTier(),
            'starterClubTier'              => $config->getStarterClubTier(),
        ] + $overrides);

        $this->assertResponseRedirects();
    }

    private function setCap(int $max): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE starter_config SET max_enrichment_requests_per_user = :max',
            ['max' => $max],
        );
        self::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    private function cap(): int
    {
        return (int) self::getContainer()->get(Connection::class)
            ->fetchOne('SELECT max_enrichment_requests_per_user FROM starter_config');
    }

    /**
     * app_admin_provider re-fetches the admin from the database by email on every request, so an
     * in-memory-only Admin fails that refresh and is silently treated as unauthenticated.
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

    /** @param array<string, mixed> $values */
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
     * A plain token seeded under SessionTokenStorage's own key. CsrfTokenManager::isTokenValid()
     * compares the raw value when it is not in the randomised `x.y.z` format, so this is accepted
     * without needing a rendered form to scrape. (Same pattern as TelemetryConfigControllerTest.)
     */
    private function seedValidCsrfToken(KernelBrowser $client, string $tokenId): string
    {
        $value = 'test-csrf-token-' . bin2hex(random_bytes(8));
        $this->seedSession($client, ["_csrf/{$tokenId}" => $value]);

        return $value;
    }
}
