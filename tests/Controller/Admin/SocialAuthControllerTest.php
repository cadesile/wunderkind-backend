<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Admin;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

class SocialAuthControllerTest extends WebTestCase
{
    private const TEST_ADMIN_EMAIL = 'social-auth-test-admin@example.com';

    /**
     * Logs in as a real, persisted Admin. app_admin_provider (a Doctrine
     * EntityUserProvider) re-fetches the user from the DB by email on every
     * request to refresh it — an in-memory-only Admin fails that refresh and
     * gets silently treated as unauthenticated, so it must actually exist.
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

    /** Pre-seeds a session value, mirroring what the *-connect action would have stored. */
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

    private function countConnections(): int
    {
        return (int) self::getContainer()->get(EntityManagerInterface::class)
            ->getConnection()
            ->fetchOne('SELECT COUNT(*) FROM social_account_connection');
    }

    public function testAdminSocialRoutesRequireAuthentication(): void
    {
        $client = static::createClient();

        foreach ([
            '/admin/social',
            '/admin/social/facebook/connect',
            '/admin/social/facebook/callback',
            '/admin/social/twitter/connect',
            '/admin/social/twitter/callback',
        ] as $url) {
            $client->request('GET', $url);
            $this->assertTrue(
                $client->getResponse()->isRedirect() || $client->getResponse()->getStatusCode() === 403,
                "Expected {$url} to require authentication, got " . $client->getResponse()->getStatusCode(),
            );
        }
    }

    public function testFacebookCallbackRejectsTamperedState(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $this->seedSession($client, ['social_oauth_state_facebook' => 'the-real-state-value']);

        $before = $this->countConnections();

        $client->request('GET', '/admin/social/facebook/callback?state=an-attacker-supplied-state&code=some-code');

        $this->assertResponseRedirects();
        $client->followRedirect();
        $this->assertSelectorTextContains('body', 'invalid or missing state parameter');
        $this->assertSame($before, $this->countConnections(), 'No connection should be persisted when state is tampered with.');
    }

    public function testFacebookCallbackRejectsMissingSessionState(): void
    {
        // Simulates hitting the callback URL directly without ever visiting /connect first.
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $before = $this->countConnections();

        $client->request('GET', '/admin/social/facebook/callback?state=anything&code=some-code');

        $this->assertResponseRedirects();
        $client->followRedirect();
        $this->assertSelectorTextContains('body', 'invalid or missing state parameter');
        $this->assertSame($before, $this->countConnections());
    }

    public function testTwitterCallbackRejectsTamperedState(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $this->seedSession($client, [
            'social_oauth_state_twitter'        => 'the-real-state-value',
            'social_oauth_pkce_verifier_twitter' => 'the-real-pkce-verifier',
        ]);

        $before = $this->countConnections();

        $client->request('GET', '/admin/social/twitter/callback?state=an-attacker-supplied-state&code=some-code');

        $this->assertResponseRedirects();
        $client->followRedirect();
        $this->assertSelectorTextContains('body', 'invalid or missing state parameter');
        $this->assertSame($before, $this->countConnections());
    }

    public function testTwitterCallbackRejectsMissingPkceVerifier(): void
    {
        // State matches, but the PKCE verifier was never stored (or session was lost) —
        // must still be rejected even though the state check alone would pass.
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $this->seedSession($client, ['social_oauth_state_twitter' => 'the-real-state-value']);

        $before = $this->countConnections();

        $client->request('GET', '/admin/social/twitter/callback?state=the-real-state-value&code=some-code');

        $this->assertResponseRedirects();
        $client->followRedirect();
        $this->assertSelectorTextContains('body', 'missing PKCE verifier');
        $this->assertSame($before, $this->countConnections());
    }

    public function testDisconnectRequiresValidCsrfToken(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $client->request('POST', '/admin/social/00000000-0000-7000-8000-000000000000/disconnect', [
            '_token' => 'not-a-real-token',
        ]);

        $this->assertResponseRedirects();
        $client->followRedirect();
        $this->assertSelectorTextContains('body', 'Invalid CSRF token');
    }

    public function testIndexListsConnections(): void
    {
        // EasyAdmin only populates its Twig "ea" context for requests routed
        // through the dashboard's own entry point (/admin?routeName=...) —
        // the same reason custom POST actions must redirect via
        // generateUrl('admin', ['routeName' => ...]) rather than directly.
        // MenuItem::linkToRoute() already generates URLs this way for real users.
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $client->request('GET', '/admin?routeName=admin_social_connections');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h5', 'Social Account Connections');
    }
}
