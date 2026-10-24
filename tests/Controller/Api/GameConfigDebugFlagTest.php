<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Club;
use App\Entity\User;
use App\Repository\GameConfigRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * `debugLoggingEnabled` on GET /api/game-config is the one key in that payload whose value
 * depends on WHO is asking: it is the global GameConfig switch OR the requesting account's
 * own User::$debugLoggingEnabled override.
 *
 * The direction is the whole point and is what these tests pin down. The per-user flag exists
 * so one player's device can be debugged without exposing the panel (it records every API
 * request and response) to the entire player base — so it may only ever turn the panel ON,
 * while the global switch is off. It must never be able to turn the panel OFF for an account
 * that the global switch has turned it on for.
 *
 * The endpoint is PUBLIC_ACCESS, so the unauthenticated cases are real traffic (the app before
 * sign-in), not just defensive padding. That is also why `GET /api/club/status` mirrors the same
 * key: being authenticated, the per-account override always applies there, so it is the reliable
 * read post-sign-in. Both go through DebugLoggingResolver and the last test here asserts they
 * cannot disagree for the same account — two endpoints serving one flag is the drift risk.
 */
class GameConfigDebugFlagTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private mixed $currentUserId = null;
    private bool $originalGlobal = false;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);

        $this->originalGlobal = self::getContainer()->get(GameConfigRepository::class)
            ->getConfig()
            ->isDebugLoggingEnabled();
    }

    protected function tearDown(): void
    {
        // The global switch is a singleton row shared by the whole suite, and leaving it on
        // would hand every later test a debug-enabled world. Each test below also sets it
        // explicitly rather than trusting this, because a run killed mid-test never gets here.
        $this->setGlobal($this->originalGlobal);

        parent::tearDown();
    }

    private function setGlobal(bool $enabled): void
    {
        $config = self::getContainer()->get(GameConfigRepository::class)->getConfig();
        $config->setDebugLoggingEnabled($enabled);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
    }

    private function createUser(bool $debugEnabled, bool $withClub = false): User
    {
        $user = new User('game-config-debug-' . uniqid('', true) . '@example.com');
        $user->setPassword('x');
        $user->setRoles([User::ROLE_CLUB]);
        $user->setDebugLoggingEnabled($debugEnabled);

        $this->em->persist($user);

        // /api/club/status 404s without one, so only the mirror tests pay for it.
        if ($withClub) {
            $this->em->persist(new Club('Debug Flag FC ' . uniqid('', true), $user));
        }

        $this->em->flush();

        $this->currentUserId = $user->getId();

        return $user;
    }

    /**
     * loginUser() against the stateless JWT firewall authenticates exactly one request, so
     * each authenticated call reboots the kernel first.
     *
     * @see \App\Tests\Controller\Api\OwnerAvatarControllerTest::authenticatedRequest()
     */
    private function fetchAsUser(string $path = '/api/game-config'): array
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);

        $this->client->loginUser($this->em->find(User::class, $this->currentUserId), 'api');
        $this->client->request('GET', $path);

        $this->assertResponseStatusCodeSame(200);

        return json_decode($this->client->getResponse()->getContent(), true);
    }

    private function fetchAnonymously(): array
    {
        $this->client->request('GET', '/api/game-config');
        $this->assertResponseStatusCodeSame(200);

        return json_decode($this->client->getResponse()->getContent(), true);
    }

    public function testGlobalOffAndNoAccountMeansOff(): void
    {
        $this->setGlobal(false);

        $this->assertFalse($this->fetchAnonymously()['debugLoggingEnabled']);
    }

    public function testGlobalOnReachesAnUnauthenticatedClient(): void
    {
        $this->setGlobal(true);

        $this->assertTrue(
            $this->fetchAnonymously()['debugLoggingEnabled'],
            'the global switch must still work with no account involved at all',
        );
    }

    public function testPerUserFlagTurnsThePanelOnWhileTheGlobalSwitchIsOff(): void
    {
        $this->setGlobal(false);
        $this->createUser(debugEnabled: true);

        $this->assertTrue(
            $this->fetchAsUser()['debugLoggingEnabled'],
            'this is the feature: one account gets the panel, the global switch stays off',
        );
    }

    public function testAnAccountWithoutTheFlagStillSeesTheGlobalOffState(): void
    {
        $this->setGlobal(false);
        $this->createUser(debugEnabled: false);

        $this->assertFalse($this->fetchAsUser()['debugLoggingEnabled']);
    }

    public function testPerUserFlagCannotTurnThePanelOffWhenTheGlobalSwitchIsOn(): void
    {
        $this->setGlobal(true);
        $this->createUser(debugEnabled: false);

        $this->assertTrue(
            $this->fetchAsUser()['debugLoggingEnabled'],
            'the per-user flag is additive — it must never override the global switch downwards',
        );
    }

    public function testTheFlagDefaultsToOffOnANewAccount(): void
    {
        $user = new User('game-config-debug-default-' . uniqid('', true) . '@example.com');

        $this->assertFalse(
            $user->isDebugLoggingEnabled(),
            'a new account must not get a request-logging panel it never asked for',
        );
    }

    public function testTheResponseIsNotShareableBetweenAccounts(): void
    {
        $this->setGlobal(false);
        $this->fetchAnonymously();

        // One key now varies per account, so a shared cache must not be allowed to serve one
        // account's payload to another. Nothing caches this endpoint today; the header is what
        // keeps that true if a CDN ever lands in front of the API.
        $cacheControl = $this->client->getResponse()->headers->get('Cache-Control');

        $this->assertStringContainsString('private', (string) $cacheControl);
        $this->assertStringNotContainsString('public', (string) $cacheControl);
    }

    public function testClubStatusCarriesThePerUserFlagWhileTheGlobalSwitchIsOff(): void
    {
        $this->setGlobal(false);
        $this->createUser(debugEnabled: true, withClub: true);

        $status = $this->fetchAsUser('/api/club/status');

        $this->assertArrayHasKey(
            'debugLoggingEnabled',
            $status,
            'the mirror exists so a signed-in client has somewhere reliable to read this flag',
        );
        $this->assertTrue($status['debugLoggingEnabled']);
    }

    public function testClubStatusReportsOffForAnAccountWithoutTheFlag(): void
    {
        $this->setGlobal(false);
        $this->createUser(debugEnabled: false, withClub: true);

        $this->assertFalse($this->fetchAsUser('/api/club/status')['debugLoggingEnabled']);
    }

    public function testClubStatusFollowsTheGlobalSwitchToo(): void
    {
        $this->setGlobal(true);
        $this->createUser(debugEnabled: false, withClub: true);

        $this->assertTrue($this->fetchAsUser('/api/club/status')['debugLoggingEnabled']);
    }

    /**
     * The invariant that matters once one flag is served from two places: both read
     * DebugLoggingResolver, so an authenticated caller must get the same answer from either.
     * If this ever fails, one endpoint has started deriving the value on its own.
     */
    public function testBothEndpointsAgreeForTheSameAccount(): void
    {
        $this->setGlobal(false);
        $this->createUser(debugEnabled: true, withClub: true);

        $fromGameConfig = $this->fetchAsUser('/api/game-config')['debugLoggingEnabled'];
        $fromClubStatus = $this->fetchAsUser('/api/club/status')['debugLoggingEnabled'];

        $this->assertTrue($fromGameConfig);
        $this->assertSame($fromGameConfig, $fromClubStatus);
    }
}
