<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Repository\CountryWorldPackCacheRepository;
use App\Service\WorldInitializationService;
use App\Service\WorldPackCacheService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * WorldPackCacheService::getOrBuild() serializes a cache miss behind a Postgres advisory
 * lock and re-checks under it.
 *
 * Without that, two concurrent `POST /api/initialize/league/{tier}` calls for the same tier
 * both miss, both run the generator — which creates and then DQL-deletes a whole tier of
 * Player/Staff rows — and both insert, so one dies on `uq_country_tier` with a 500 after
 * doing all of that work. That went from theoretical to likely when the client started
 * treating an empty or truncated 2xx as a retryable network failure: a backgrounded request
 * is now retried while the first may still be in flight.
 */
class WorldPackCacheServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private WorldPackCacheService $service;
    private CountryWorldPackCacheRepository $repository;
    private string $country;

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
        $this->service->deleteByCountry($this->country);
        parent::tearDown();
    }

    public function testASecondCallIsServedFromCacheAndNeverRunsTheGenerator(): void
    {
        $runs = 0;
        $generator = function () use (&$runs): array {
            $runs++;

            return ['clubs' => [['id' => 'club-1']]];
        };

        $first  = $this->service->getOrBuild($this->country, 3, $generator);
        $second = $this->service->getOrBuild($this->country, 3, $generator);

        $this->assertSame(1, $runs, 'the generator must run exactly once for a given country+tier');
        $this->assertSame($first, $second);
        $this->assertSame(['clubs' => [['id' => 'club-1']]], $second);
    }

    /** `isCached()` is read before a getOrBuild() call so logging can say hit or build. */
    public function testIsCachedTracksWhetherACallWouldPayForABuild(): void
    {
        $this->assertFalse($this->service->isCached($this->country, 6));

        $this->service->getOrBuild($this->country, 6, fn (): array => ['clubs' => []]);

        $this->assertTrue($this->service->isCached($this->country, 6));
    }

    /**
     * The lock has to be released on the way out, not just on the happy path — a generator
     * that throws must not leave the lock held for the rest of the connection's life, or
     * every later caller for that tier blocks forever.
     */
    public function testAFailingGeneratorStillReleasesTheLock(): void
    {
        $boom = function (): array {
            throw new \RuntimeException('generation failed');
        };

        try {
            $this->service->getOrBuild($this->country, 4, $boom);
            self::fail('the generator exception should propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('generation failed', $e->getMessage());
        }

        $this->assertSame(
            0,
            (int) $this->em->getConnection()->fetchOne(
                "SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND pid = pg_backend_pid()",
            ),
            'no advisory lock may outlive the call',
        );

        // And the tier is still buildable afterwards.
        $payload = $this->service->getOrBuild($this->country, 4, fn (): array => ['clubs' => []]);
        $this->assertSame(['clubs' => []], $payload);
    }

    /**
     * A row stamped with an older payload version is a miss, and must be replaced rather
     * than served — the cache has no TTL, so a pack built before a snapshot-shape change
     * would otherwise be handed out forever.
     */
    public function testAStaleShapedRowIsRebuiltRatherThanServed(): void
    {
        $this->service->getOrBuild($this->country, 5, fn (): array => ['clubs' => ['old']]);

        $this->em->getConnection()->executeStatement(
            'UPDATE country_world_pack_cache SET payload_version = :v WHERE country = :c AND tier = 5',
            ['v' => WorldInitializationService::WORLD_PACK_VERSION - 1, 'c' => $this->country],
        );
        $this->em->clear();

        $rebuilt = $this->service->getOrBuild($this->country, 5, fn (): array => ['clubs' => ['new']]);

        $this->assertSame(['clubs' => ['new']], $rebuilt);
        $this->assertSame(
            WorldInitializationService::WORLD_PACK_VERSION,
            $this->repository->findForCountryAndTier($this->country, 5)?->getPayloadVersion(),
        );
    }

    private function unusedCountryCode(): string
    {
        for ($i = 0; $i < 50; $i++) {
            $code = chr(random_int(65, 90)) . chr(random_int(65, 90));
            if ($this->repository->findCachedTiers($code, WorldInitializationService::WORLD_PACK_VERSION) === []) {
                return $code;
            }
        }

        self::fail('could not find an unused country code');
    }
}
