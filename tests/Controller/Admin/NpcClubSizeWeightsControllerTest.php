<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Admin;
use App\Entity\GameConfig;
use App\Repository\GameConfigRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

class NpcClubSizeWeightsControllerTest extends WebTestCase
{
    private const TEST_ADMIN_EMAIL = 'npc-size-weights-test-admin@example.com';

    /**
     * Logs in as a real, persisted Admin. app_admin_provider (a Doctrine
     * EntityUserProvider) re-fetches the user from the DB by email on every
     * request to refresh it — an in-memory-only Admin fails that refresh and
     * gets silently treated as unauthenticated, so it must actually exist.
     * (Same pattern as SocialAuthControllerTest::loginAsAdmin().)
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
     * submit that same plain value as `_token` — CsrfTokenManager::isTokenValid()
     * falls back to comparing the raw value when it isn't in the randomized
     * `x.y.z` format, so this is accepted as valid.
     * (Same pattern as SocialAuthControllerTest::seedValidCsrfToken().)
     */
    private function seedValidCsrfToken(KernelBrowser $client, string $tokenId): string
    {
        $value = 'test-csrf-token-' . bin2hex(random_bytes(8));
        $this->seedSession($client, ["_csrf/{$tokenId}" => $value]);
        return $value;
    }

    private function getGameConfig(): GameConfig
    {
        return self::getContainer()->get(GameConfigRepository::class)->getConfig();
    }

    public function testSaveSizeWeightsPersistsNewValues(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $token = $this->seedValidCsrfToken($client, 'npc_size_weights');

        $client->request('POST', '/admin/npc-clubs/save-size-weights', [
            '_token' => $token,
            'sizeWeights' => [
                'tier1' => ['big' => '80', 'medium' => '15', 'small' => '5'],
                'tier8' => ['big' => '5', 'medium' => '15', 'small' => '80'],
            ],
        ]);

        $this->assertResponseRedirects();
        $client->followRedirect();
        $this->assertSelectorTextContains('body', 'City size weights saved.');

        // Fetch fresh from the container (not a stale copy) to confirm real persistence.
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $gameConfig = $this->getGameConfig();

        $this->assertSame(
            [
                'tier1' => ['big' => 80, 'medium' => 15, 'small' => 5],
                'tier8' => ['big' => 5, 'medium' => 15, 'small' => 80],
            ],
            $gameConfig->getNpcClubSizeWeights(),
        );
    }

    public function testSaveSizeWeightsRequiresValidCsrfToken(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $gameConfig = $this->getGameConfig();
        $gameConfig->setNpcClubSizeWeights([
            'tier1' => ['big' => 70, 'medium' => 25, 'small' => 5],
            'tier8' => ['big' => 5, 'medium' => 25, 'small' => 70],
        ]);
        $em->flush();
        $before = $gameConfig->getNpcClubSizeWeights();

        $client->request('POST', '/admin/npc-clubs/save-size-weights', [
            '_token' => 'not-a-real-token',
            'sizeWeights' => [
                'tier1' => ['big' => '1', 'medium' => '1', 'small' => '1'],
                'tier8' => ['big' => '1', 'medium' => '1', 'small' => '1'],
            ],
        ]);

        $this->assertResponseRedirects();
        $client->followRedirect();
        $this->assertSelectorTextContains('body', 'Invalid CSRF token.');

        $em->clear();
        $this->assertSame($before, $this->getGameConfig()->getNpcClubSizeWeights());
    }
}
