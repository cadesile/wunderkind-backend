<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\WorldPack\WorldPackSet;
use App\Enum\WorldPack\WorldPackSetStatus;
use App\Repository\CountryWorldPackCacheRepository;
use App\Service\WorldInitializationService;
use App\Service\WorldPackCacheService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * WorldPackCacheService reads and writes the tier payloads that make up a WorldPackSet.
 *
 * This file used to pin the behaviour of `getOrBuild()`: a cache miss serialized behind a
 * Postgres advisory lock on (country, tier) and re-checked under it, because two concurrent
 * `POST /api/initialize/league/{tier}` calls both missed, both ran the generator and both
 * inserted, so one died on `uq_country_tier`.
 *
 * That whole mechanism is gone, and the tests with it, because the race it guarded no longer
 * exists rather than because it stopped mattering:
 *
 *   - Payload rows are now written only inside a generation run, which is single-flight per
 *     country, or by the top-up, which is single-flight per country via its own advisory lock.
 *     Neither can be entered twice concurrently for one country.
 *   - A request that finds no set builds its tier inline and writes NOTHING, so there is no
 *     insert left to collide.
 *
 * What is still worth pinning — and is, below — is that a row belongs to exactly one set, that
 * a stale-shaped row is not served, and that set-scoped reads do not leak between two sets of
 * the same country. That last one is the whole reason `findForCountryAndTier()` was removed:
 * with two sets per country it would have returned an arbitrary one of them.
 */
class WorldPackCacheServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private WorldPackCacheService $service;
    private CountryWorldPackCacheRepository $repository;
    private string $country;
    /** @var WorldPackSet[] */
    private array $sets = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em         = self::getContainer()->get(EntityManagerInterface::class);
        $this->service    = self::getContainer()->get(WorldPackCacheService::class);
        $this->repository = self::getContainer()->get(CountryWorldPackCacheRepository::class);
        $this->country    = $this->unusedCountryCode();
    }

    protected function tearDown(): void
    {
        foreach ($this->sets as $set) {
            $managed = $this->em->find(WorldPackSet::class, (string) $set->getId());
            if ($managed !== null) {
                // Its payload rows go with it via ON DELETE CASCADE.
                $this->em->remove($managed);
            }
        }
        $this->em->flush();
        $this->sets = [];

        parent::tearDown();
    }

    public function testATierIsReadBackFromTheSetItWasWrittenTo(): void
    {
        $set = $this->makeSet();

        $this->service->replaceWithPayload($set, 3, ['clubs' => [['id' => 'club-1']]]);

        $this->assertSame(['clubs' => [['id' => 'club-1']]], $this->service->readTier($set, 3));
        $this->assertTrue($this->service->hasTier($set, 3));
        $this->assertNull($this->service->readTier($set, 4), 'a tier never written must read as absent');
    }

    /**
     * The property that replaced `findForCountryAndTier()`.
     *
     * Two sets for one country hold tier 3 independently, and reading either must never
     * return the other's bytes. Before sets, both rows would have been `(country, 3)` and a
     * `findOneBy` would have picked one at random — a client served tier 3 of one world and
     * tier 4 of another, with every response a 200 and nothing in a log to see.
     */
    public function testTwoSetsForOneCountryDoNotLeakTiersIntoEachOther(): void
    {
        $a = $this->makeSet();
        $b = $this->makeSet();

        $this->service->replaceWithPayload($a, 3, ['clubs' => ['from-a']]);
        $this->service->replaceWithPayload($b, 3, ['clubs' => ['from-b']]);

        $this->assertSame(['clubs' => ['from-a']], $this->service->readTier($a, 3));
        $this->assertSame(['clubs' => ['from-b']], $this->service->readTier($b, 3));
    }

    /** Writing the same tier twice replaces, rather than colliding on uq_world_pack_set_tier. */
    public function testRewritingATierReplacesIt(): void
    {
        $set = $this->makeSet();

        $this->service->replaceWithPayload($set, 2, ['clubs' => ['old']]);
        $this->service->replaceWithPayload($set, 2, ['clubs' => ['new']]);

        $this->assertSame(['clubs' => ['new']], $this->service->readTier($set, 2));
        $this->assertCount(1, $this->repository->findTiersForSet($set));
    }

    /**
     * A row stamped with an older payload version is not served — the rows have no TTL, so a
     * pack built before a snapshot-shape change would otherwise be handed out forever.
     */
    public function testAStaleShapedRowIsNotServed(): void
    {
        $set = $this->makeSet();
        $this->service->replaceWithPayload($set, 5, ['clubs' => ['old']]);

        $this->em->getConnection()->executeStatement(
            'UPDATE country_world_pack_cache SET payload_version = :v WHERE set_id = :s AND tier = 5',
            ['v' => WorldInitializationService::WORLD_PACK_VERSION - 1, 's' => $set->getId()->toRfc4122()],
        );
        $this->em->clear();

        $set = $this->em->find(WorldPackSet::class, (string) $set->getId());

        $this->assertNull($this->service->readTier($set, 5));
        $this->assertFalse($this->service->hasTier($set, 5));
    }

    public function testAllTiersPresentAsksAboutThisSetOnly(): void
    {
        $a = $this->makeSet();
        $b = $this->makeSet();

        foreach ([1, 2, 3] as $tier) {
            $this->service->replaceWithPayload($a, $tier, ['clubs' => []]);
        }
        $this->service->replaceWithPayload($b, 1, ['clubs' => []]);

        $this->assertTrue($this->service->allTiersPresent($a, [1, 2, 3]));
        // The country has all three tiers between its two sets; B still does not have them,
        // and B is what a club claiming B would be served.
        $this->assertFalse($this->service->allTiersPresent($b, [1, 2, 3]));
    }

    public function testDeleteBySetRemovesOnlyThatSetsRows(): void
    {
        $a = $this->makeSet();
        $b = $this->makeSet();

        $this->service->replaceWithPayload($a, 1, ['clubs' => []]);
        $this->service->replaceWithPayload($b, 1, ['clubs' => []]);

        $this->assertSame(1, $this->service->deleteBySet($a));
        $this->assertSame([], $this->repository->findTiersForSet($a));
        $this->assertSame([1], $this->repository->findTiersForSet($b));
    }

    private function makeSet(): WorldPackSet
    {
        $set = new WorldPackSet(
            $this->country,
            WorldInitializationService::WORLD_PACK_VERSION,
            ['players' => [], 'staff' => [], 'scouts' => []],
            8,
        );
        $set->setStatus(WorldPackSetStatus::READY);

        $this->em->persist($set);
        $this->em->flush();

        $this->sets[] = $set;

        return $set;
    }

    private function unusedCountryCode(): string
    {
        $conn = $this->em->getConnection();

        for ($i = 0; $i < 50; $i++) {
            $code = chr(random_int(65, 90)) . chr(random_int(65, 90));
            $used = (int) $conn->fetchOne('SELECT count(*) FROM world_pack_set WHERE country = :c', ['c' => $code]);
            if ($used === 0) {
                return $code;
            }
        }

        self::fail('could not find an unused country code');
    }
}
