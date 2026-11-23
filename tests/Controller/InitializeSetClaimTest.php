<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Club;
use App\Entity\CountryWorldPackCache;
use App\Entity\League;
use App\Entity\User;
use App\Entity\WorldPack\WorldPackSet;
use App\Enum\WorldPack\WorldPackSetStatus;
use App\Service\WorldInitializationService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The claim path, end to end over HTTP.
 *
 * `WorldPackSetClaimTest` pins the claim at the service level and `LegacyNewGameFlowTest` pins
 * the response shapes on the FALLBACK path. What neither reaches is the thing a player actually
 * does: initialise against a country that HAS stock, and get a world nobody else will ever be
 * given.
 *
 * Two properties, tested together because they pull in opposite directions: every club must get
 * a DIFFERENT world, and every retry by the same club must get the SAME one. Deleting a row on
 * serve satisfies the first and breaks the second — a lost response then got a tier from another
 * generation, a mixed world (Courtney, 2026-11-23: "we should never get mixed worlds"). So a
 * served row is stamped and kept; `app:worldpack:maintain` clears it an hour after the world
 * finished loading, so the pack still ends up living on the device only.
 */
class InitializeSetClaimTest extends WebTestCase
{
    private const COUNTRY = 'QX'; // not a real Country case; nothing else fixtures it
    private const TIER    = 4;

    private array $cleanup = [];

    protected function tearDown(): void
    {
        self::ensureKernelShutdown();
        static::createClient();

        $conn = self::getContainer()->get(Connection::class);

        // Order matters: a set points at a club and a club points at a set, so both links are
        // broken before anything is deleted.
        $conn->executeStatement(
            'UPDATE club SET world_pack_set_id = NULL WHERE id IN (SELECT id FROM club WHERE country = :c)',
            ['c' => self::COUNTRY],
        );
        $conn->executeStatement('DELETE FROM world_pack_set WHERE country = :c', ['c' => self::COUNTRY]);
        $conn->executeStatement('DELETE FROM country_world_pack_cache WHERE country = :c', ['c' => self::COUNTRY]);
        $conn->executeStatement("DELETE FROM club WHERE name LIKE 'SetClaim %'");
        $conn->executeStatement("DELETE FROM \"user\" WHERE email LIKE 'setclaim-%@test.local'");
        $conn->executeStatement('DELETE FROM league WHERE country = :c', ['c' => self::COUNTRY]);

        $this->cleanup = [];

        parent::tearDown();
    }

    public function testTwoClubsGetDifferentWorldsAndEachRetryReplaysItsOwn(): void
    {
        $this->seedLeague();
        $setA = $this->seedReadySet('alpha');
        $setB = $this->seedReadySet('bravo');

        // ── club A ───────────────────────────────────────────────────────────────────────
        $clubA    = $this->seedClub('a');
        $starterA = $this->call('POST', '/api/initialize/starter', $clubA);

        self::assertSame(200, $starterA['status']);
        self::assertArrayHasKey('ampStarter', $starterA['body']);
        self::assertArrayNotHasKey('replayed', $starterA['body'], 'a first claim is not a replay');

        $tierA = $this->call('POST', '/api/initialize/league/' . self::TIER, $clubA);
        self::assertSame(200, $tierA['status']);
        self::assertSame(self::TIER, $tierA['body']['tier']);

        // ── club B ───────────────────────────────────────────────────────────────────────
        $clubB    = $this->seedClub('b');
        $starterB = $this->call('POST', '/api/initialize/starter', $clubB);
        self::assertSame(200, $starterB['status']);

        $tierB = $this->call('POST', '/api/initialize/league/' . self::TIER, $clubB);
        self::assertSame(200, $tierB['status']);

        // ── 1. different worlds ──────────────────────────────────────────────────────────
        self::assertNotEquals(
            $starterA['body']['ampStarter'],
            $starterB['body']['ampStarter'],
            'two clubs must not be given the same starter squad',
        );
        self::assertNotEquals(
            $tierA['body']['data'],
            $tierB['body']['data'],
            'two clubs must not be given the same tier payload',
        );

        // Not merely different — DISJOINT. A single shared player would mean a pool row
        // consumed twice.
        $playersA = array_column($starterA['body']['ampStarter']['players'], 'id');
        $playersB = array_column($starterB['body']['ampStarter']['players'], 'id');
        self::assertNotEmpty($playersA);
        self::assertSame([], array_intersect($playersA, $playersB));

        // ── 2. each retry replays that club's OWN world ──────────────────────────────────
        $starterAgainA = $this->call('POST', '/api/initialize/starter', $clubA);
        self::assertSame(200, $starterAgainA['status']);
        self::assertTrue($starterAgainA['body']['replayed'] ?? false);
        self::assertSame($starterA['body']['ampStarter'], $starterAgainA['body']['ampStarter']);

        $tierAgainA = $this->call('POST', '/api/initialize/league/' . self::TIER, $clubA);
        self::assertSame(200, $tierAgainA['status']);
        self::assertSame(
            $tierA['body']['data'],
            $tierAgainA['body']['data'],
            'a retried tier call must return byte-identical data, never a tier from another generation',
        );

        // ── 3. a served tier is stamped, not deleted ─────────────────────────────────────
        $conn = self::getContainer()->get(Connection::class);
        self::assertSame(
            0,
            (int) $conn->fetchOne(
                'SELECT count(*) FROM country_world_pack_cache WHERE country = :c AND served_at IS NULL',
                ['c' => self::COUNTRY],
            ),
            'both clubs\' only tier has been served, so no row is unserved',
        );
        self::assertSame(
            2,
            (int) $conn->fetchOne('SELECT count(*) FROM country_world_pack_cache WHERE country = :c', ['c' => self::COUNTRY]),
            'served rows are kept for replay until the sweep clears finished worlds',
        );

        // ── and the bookkeeping underneath ───────────────────────────────────────────────
        self::ensureKernelShutdown();
        static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $claimedA = $em->find(Club::class, $clubA)->getWorldPackSet();
        $claimedB = $em->find(Club::class, $clubB)->getWorldPackSet();

        self::assertNotNull($claimedA);
        self::assertNotNull($claimedB);
        self::assertNotSame((string) $claimedA->getId(), (string) $claimedB->getId());
        self::assertEqualsCanonicalizing(
            [(string) $setA->getId(), (string) $setB->getId()],
            [(string) $claimedA->getId(), (string) $claimedB->getId()],
        );
        self::assertSame(WorldPackSetStatus::CLAIMED, $claimedA->getStatus());
        self::assertTrue(
            $em->find(Club::class, $clubA)->isWorldInitialized(),
            'a club whose set has handed out every tier is world-initialised',
        );

        // The country is now out of stock, which is the state that makes the next club fall
        // back to a live draw rather than being handed a world somebody is already playing.
        $conn = self::getContainer()->get(Connection::class);
        self::assertSame(
            0,
            (int) $conn->fetchOne(
                "SELECT count(*) FROM world_pack_set WHERE country = :c AND status = 'ready' AND claimed_by_club_id IS NULL",
                ['c' => self::COUNTRY],
            ),
        );
    }

    /**
     * A club that owns a world whose tier is gone (an abandoned load past the retention
     * backstop) is refused with 410. It must never be handed a freshly built tier, which would
     * come from another generation than the rest of its world.
     */
    public function testAMissingTierOfAClaimedWorldIsRefusedNotRebuilt(): void
    {
        $this->seedLeague();
        $this->seedReadySet('charlie');

        $club = $this->seedClub('c');
        self::assertSame(200, $this->call('POST', '/api/initialize/starter', $club)['status']);

        $conn = self::getContainer()->get(Connection::class);
        $conn->executeStatement('DELETE FROM country_world_pack_cache WHERE country = :c', ['c' => self::COUNTRY]);

        $tier = $this->call('POST', '/api/initialize/league/' . self::TIER, $club);
        self::assertSame(410, $tier['status']);
        self::assertSame('world_pack_tier_unavailable', $tier['body']['error'] ?? null);
    }

    // ── fixtures ────────────────────────────────────────────────────────────────────────────

    private function seedLeague(): void
    {
        self::ensureKernelShutdown();
        static::createClient();

        $em     = self::getContainer()->get(EntityManagerInterface::class);
        $league = new League(self::COUNTRY, self::TIER, 'Set Claim Test League');
        $em->persist($league);
        $em->flush();
    }

    /**
     * A complete, claimable set: a starter squad AND a payload for the tier.
     *
     * Both halves are needed. A set with no squad is excluded by the claim predicate (that is
     * the migration-backfilled shape), and a set with no payload row would be claimed and then
     * fail to serve its tier — which is the half-built state the BUILDING status exists to keep
     * out of the buffer.
     */
    private function seedReadySet(string $tag): WorldPackSet
    {
        self::ensureKernelShutdown();
        static::createClient();

        $em = self::getContainer()->get(EntityManagerInterface::class);

        $set = new WorldPackSet(
            self::COUNTRY,
            WorldInitializationService::WORLD_PACK_VERSION,
            [
                'players' => [
                    ['id' => $tag . '-p1', 'firstName' => 'Set', 'lastName' => ucfirst($tag) . ' One'],
                    ['id' => $tag . '-p2', 'firstName' => 'Set', 'lastName' => ucfirst($tag) . ' Two'],
                ],
                'staff'  => [['id' => $tag . '-s1']],
                'scouts' => [],
            ],
            1,
        );
        $set->setStatus(WorldPackSetStatus::READY);
        $set->setReadyAt(new \DateTimeImmutable());
        $em->persist($set);

        $em->persist(new CountryWorldPackCache(
            $set,
            self::TIER,
            [
                'packVersion' => WorldInitializationService::WORLD_PACK_VERSION,
                'tier'        => self::TIER,
                'country'     => self::COUNTRY,
                'clubs'       => [['id' => $tag . '-club', 'name' => 'NPC ' . ucfirst($tag), 'players' => [['id' => $tag . '-npc1']]]],
                'fixtures'    => [],
            ],
            WorldInitializationService::WORLD_PACK_VERSION,
        ));

        $em->flush();

        return $set;
    }

    /** @return string the club id */
    private function seedClub(string $suffix): string
    {
        self::ensureKernelShutdown();
        static::createClient();

        $em   = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User(sprintf('setclaim-%s-%s@test.local', $suffix, bin2hex(random_bytes(3))));
        $user->setPassword('x');
        $user->setRoles([User::ROLE_CLUB]);
        $em->persist($user);

        $club = new Club('SetClaim ' . strtoupper($suffix) . ' ' . bin2hex(random_bytes(3)), $user);
        $club->setCountry(self::COUNTRY);
        $em->persist($club);
        $em->flush();

        $this->cleanup[] = (string) $club->getId();

        return (string) $club->getId();
    }

    /**
     * One authenticated request as the owner of $clubId, with a fresh client.
     *
     * A fresh client per call because loginUser() authenticates exactly one request against
     * this stateless JWT firewall — and because these tests deliberately make the SAME call
     * twice and must not have the second served anything the first left in memory.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private function call(string $method, string $uri, string $clubId): array
    {
        self::ensureKernelShutdown();
        /** @var KernelBrowser $client */
        $client = static::createClient();

        $em   = self::getContainer()->get(EntityManagerInterface::class);
        $club = $em->find(Club::class, $clubId);
        $client->loginUser($club->getUser(), 'api');

        $client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json'], content: '{}');

        return [
            'status' => $client->getResponse()->getStatusCode(),
            'body'   => json_decode((string) $client->getResponse()->getContent(), true) ?? [],
        ];
    }
}
