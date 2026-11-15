<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Club;
use App\Entity\User;
use App\Service\WorldInitializationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * `packVersion` on `GET /api/initialize/leagues`.
 *
 * ── WHY THIS KEY IS HERE AT ALL, AND WHY IT IS NOT THE SAME KEY ───────────────────────────
 *
 * On a tier pack, `packVersion` is the version that pack WAS BUILT AT — a fact about the
 * payload in your hand. This endpoint is not a pack: it is an uncached list assembled live from
 * `league` rows and has no version of its own. Here the key is the version the server BUILDS
 * AT, so a client knows what the next `POST /api/initialize/league/{tier}` will stamp before it
 * fetches eight tiers rather than after.
 *
 * The two can legitimately disagree mid-regeneration — this reads 5 while a not-yet-rebuilt
 * pack still holds 4 — and that is the honest answer, not a bug. The tests below pin the
 * meaning as "the server's current version", so nobody later reads it as "the version you
 * already have" and starts comparing it against stored packs.
 *
 * SHAPE: this endpoint has always returned an object (`{"leagues": [...]}`), so the key is an
 * additive sibling and no client sees a shape change. That is asserted, because the one way
 * this addition could break a shipped build is if `leagues` stopped being where it was.
 */
class InitializeLeaguesPackVersionTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    /** @var list<object> */
    private array $cleanup = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->cleanup) as $entity) {
            $managed = $this->em->find($entity::class, $entity->getId());

            if ($managed !== null) {
                $this->em->remove($managed);
            }
        }

        $this->em->flush();
        $this->cleanup = [];

        parent::tearDown();
    }

    public function testTheLeaguesListCarriesTheServersCurrentPackVersion(): void
    {
        // THE REPORTED GAP. The key was added to the tier pack first, and a client reading the
        // league LIST during initialisation saw nothing — correctly, since it was never there.
        $payload = $this->getLeagues();

        self::assertArrayHasKey('packVersion', $payload);
        self::assertSame(WorldInitializationService::WORLD_PACK_VERSION, $payload['packVersion']);
    }

    public function testItIsAnAdditiveSiblingAndLeaguesStaysWhereItWas(): void
    {
        // The only way adding this key could break a shipped build. The endpoint has always
        // returned an object; if it had returned a bare list, a top-level key would have meant
        // wrapping the response and breaking every caller.
        $payload = $this->getLeagues();

        self::assertArrayHasKey('leagues', $payload);
        self::assertIsArray($payload['leagues']);
    }

    public function testItReportsTheServerVersionNotAStoredOne(): void
    {
        // Pins the MEANING. This endpoint reads no cache, so the figure cannot be the version
        // of anything the caller holds — it is what the next tier fetch will build at. A future
        // change that started sourcing it from `country_world_pack_cache` would be a different
        // key wearing the same name, and this is what would catch it.
        $payload = $this->getLeagues();

        self::assertSame(
            WorldInitializationService::WORLD_PACK_VERSION,
            $payload['packVersion'],
            'the constant, not whatever happens to be cached for this country',
        );
    }

    /** @return array<string, mixed> */
    private function getLeagues(): array
    {
        $club   = $this->initializedClub();
        $userId = $club->getUser()->getId();

        // Single-use login against the stateless JWT firewall — see CLAUDE.md.
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);

        $this->client->loginUser($this->em->find(User::class, $userId), 'api');
        $this->client->request('GET', '/api/initialize/leagues');

        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function initializedClub(string $country = 'EN'): Club
    {
        $user = new User('leagues-packversion-' . bin2hex(random_bytes(6)) . '@example.com');
        $user->setPassword('x');
        $user->setRoles([User::ROLE_CLUB]);
        $this->em->persist($user);
        $this->cleanup[] = $user;

        // `starterInitializedAt` is the precondition the endpoint checks — without it the call
        // is a 412 and the assertions below would be testing the error path.
        $club = new Club('Leagues PackVersion FC', $user);
        $club->setCountry($country);
        $club->setStarterInitializedAt(new \DateTimeImmutable());
        $this->em->persist($club);
        $this->cleanup[] = $club;

        $this->em->flush();

        return $club;
    }
}
