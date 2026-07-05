<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Admin;
use App\Entity\SocialPostTemplate;
use App\Enum\SocialPlatform;
use App\Enum\StatCategory;
use App\Enum\StatsPeriod;
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

    /**
     * There's no transactional test isolation in this suite (see the other
     * SocialPostTemplate-touching test classes, which follow the same
     * pattern), so a template persisted by one run survives into the next
     * and collides with the (category, platform) unique constraint. Clear
     * the table before creating a fresh one.
     */
    private function createTemplate(KernelBrowser $client, StatCategory $category, SocialPlatform $platform, string $body): SocialPostTemplate
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        foreach ($em->getRepository(SocialPostTemplate::class)->findBy(['category' => $category, 'platform' => $platform]) as $existing) {
            $em->remove($existing);
        }
        $em->flush();

        $template = new SocialPostTemplate($category, $platform, StatsPeriod::ALL, $body);
        $em->persist($template);
        $em->flush();
        return $template;
    }

    /**
     * Fetching a real token via security.csrf.token_manager requires an
     * active request/session, which doesn't exist yet at this point in a
     * test (loginUser() doesn't perform an HTTP request, and the in-process
     * kernel browser pops the request off the stack once a request
     * completes). Seed the session directly with a plain token value under
     * the same key CsrfTokenManager's SessionTokenStorage uses
     * (`_csrf/<tokenId>`) and submit that same plain value as `_token` —
     * CsrfTokenManager::isTokenValid() falls back to comparing the raw
     * value when it isn't in the randomized `x.y.z` format, so this is
     * accepted as valid.
     */
    private function seedValidCsrfToken(KernelBrowser $client, string $tokenId): string
    {
        $value = 'test-csrf-token-' . bin2hex(random_bytes(8));
        $this->seedSession($client, ["_csrf/{$tokenId}" => $value]);
        return $value;
    }

    public function testTestPreviewRequiresValidCsrfToken(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $template = $this->createTemplate($client, StatCategory::MOST_TROPHIES, SocialPlatform::FACEBOOK, '{{clubName}} has {{value}} titles!');

        $client->request('POST', '/admin/social/test/preview', [
            '_token'     => 'not-a-real-token',
            'templateId' => (string) $template->getId(),
        ]);

        $this->assertResponseRedirects();
        $client->followRedirect();
        $this->assertSelectorTextContains('body', 'Invalid CSRF token');
    }

    public function testTestPublishRequiresValidCsrfToken(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $template = $this->createTemplate($client, StatCategory::MOST_TROPHIES, SocialPlatform::FACEBOOK, '{{clubName}} has {{value}} titles!');

        $client->request('POST', '/admin/social/test/publish', [
            '_token'       => 'not-a-real-token',
            'templateId'   => (string) $template->getId(),
            'renderedText' => 'Some text',
        ]);

        $this->assertResponseRedirects();
        $client->followRedirect();
        $this->assertSelectorTextContains('body', 'Invalid CSRF token');
    }

    public function testTestPreviewShowsNoDataMessageWhenLeaderboardEmpty(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        // MOST_TROPHIES/ALL will almost certainly have no seeded SeasonRecord data
        // in a fresh test DB — if this ever becomes flaky because fixture data
        // exists, swap the category/period for one guaranteed empty in this suite.
        $template = $this->createTemplate($client, StatCategory::MOST_TROPHIES, SocialPlatform::FACEBOOK, '{{clubName}} has {{value}} titles!');

        $token = $this->seedValidCsrfToken($client, 'social_test_preview');
        // Must go through EasyAdmin's own entry point (/admin?routeName=...),
        // same as the real form in social_connections.html.twig — this
        // action renders that @EasyAdmin-extending template directly, and
        // the `ea` Twig context is only populated for requests routed this
        // way (see the comment above the preview <form> in the template).
        $client->request('POST', '/admin?routeName=admin_social_test_preview', [
            '_token'     => $token,
            'templateId' => (string) $template->getId(),
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'No leaderboard data');
    }

    public function testTestPublishWithMissingTemplateShowsFlashAndDoesNotThrow(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $token = $this->seedValidCsrfToken($client, 'social_test_publish');
        $client->request('POST', '/admin/social/test/publish', [
            '_token'       => $token,
            'templateId'   => '00000000-0000-7000-8000-000000000000',
            'renderedText' => 'Some text',
        ]);

        $this->assertResponseRedirects();
        $client->followRedirect();
        $this->assertSelectorTextContains('body', 'Nothing to publish');
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
