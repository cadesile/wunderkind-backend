<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Admin;
use App\Repository\LiveTelemetrySnapshotRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

class TelemetryConfigControllerTest extends WebTestCase
{
    private const TEST_ADMIN_EMAIL = 'telemetry-config-test-admin@example.com';

    /**
     * Logs in as a real, persisted Admin. app_admin_provider (a Doctrine
     * EntityUserProvider) re-fetches the user from the DB by email on every
     * request to refresh it — an in-memory-only Admin fails that refresh and
     * gets silently treated as unauthenticated, so it must actually exist.
     * (Same pattern as NpcClubSizeWeightsControllerTest::loginAsAdmin().)
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
     * Fetching a real token via security.csrf.token_manager requires an
     * active request/session, which doesn't exist yet at this point in a
     * test (loginUser() doesn't perform an HTTP request). Seed the session
     * directly with a plain token value under the same key
     * CsrfTokenManager's SessionTokenStorage uses (`_csrf/<tokenId>`) and
     * submit that same plain value as `_csrf_token` — CsrfTokenManager::isTokenValid()
     * falls back to comparing the raw value when it isn't in the randomized
     * `x.y.z` format, so this is accepted as valid.
     * (Same pattern as NpcClubSizeWeightsControllerTest::seedValidCsrfToken().)
     */
    private function seedValidCsrfToken(KernelBrowser $client, string $tokenId): string
    {
        $value = 'test-csrf-token-' . bin2hex(random_bytes(8));
        $this->seedSession($client, ["_csrf/{$tokenId}" => $value]);
        return $value;
    }

    public function testTelemetryConfigPageLoadsWithCurrentSnapshotAndRebuildButton(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        // Custom admin pages must be reached via /admin?routeName=... — EasyAdmin's
        // AdminRouterSubscriber only populates the `ea` Twig context for the dashboard's
        // own route, and the layout this page extends reads `ea` (see PoolConfigControllerTest).
        $client->request('GET', '/admin?routeName=admin_telemetry_config');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Telemetry Config');
        $this->assertSelectorTextContains('body', 'Rebuild Snapshot');
        $this->assertSelectorTextContains('body', 'Raw Data Uses');
    }

    public function testRebuildSnapshotRefreshesGeneratedAtAndRedirects(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $em         = self::getContainer()->get(EntityManagerInterface::class);
        $repository = self::getContainer()->get(LiveTelemetrySnapshotRepository::class);
        $repository->getSnapshot(flush: true);
        $em->clear();
        // generatedAt is a datetime_immutable column truncated to whole seconds by
        // Postgres — re-fetch after clear() so $before is truncated the same way $after
        // will be, and sleep past the second boundary so the two are distinguishable.
        $before = $repository->getSnapshot()->getGeneratedAt();
        sleep(1);

        $token = $this->seedValidCsrfToken($client, 'rebuild_telemetry');

        $client->request('POST', '/admin/telemetry-config/rebuild', [
            '_csrf_token' => $token,
        ]);

        $this->assertResponseRedirects();
        $client->followRedirect();
        $this->assertSelectorTextContains('body', 'Telemetry snapshot rebuilt.');

        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $after = $repository->getSnapshot()->getGeneratedAt();

        $this->assertGreaterThan($before, $after);
    }

    public function testRebuildSnapshotRequiresValidCsrfToken(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $em         = self::getContainer()->get(EntityManagerInterface::class);
        $repository = self::getContainer()->get(LiveTelemetrySnapshotRepository::class);
        $repository->getSnapshot(flush: true);
        $em->clear();
        // Re-fetch after clear() so this is truncated to whole seconds the same way the
        // post-request fetch below will be (Postgres datetime_immutable column precision).
        $before = $repository->getSnapshot()->getGeneratedAt();

        $client->request('POST', '/admin/telemetry-config/rebuild', [
            '_csrf_token' => 'not-a-real-token',
        ]);

        $this->assertResponseRedirects();
        $client->followRedirect();
        $this->assertSelectorTextContains('body', 'Invalid CSRF token.');

        $em->clear();
        $this->assertEquals($before, $repository->getSnapshot()->getGeneratedAt());
    }
}
