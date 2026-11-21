<?php

declare(strict_types=1);

namespace App\Tests\Service\WorldPack;

use App\Entity\WorldPack\WorldPackSet;
use App\Enum\WorldPack\WorldPackSetStatus;
use App\Repository\WorldPack\WorldPackSetRepository;
use App\Service\WorldInitializationService;
use App\Service\WorldPack\WorldPackSetService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A set is persisted BUILDING before its first tier is generated
 * (WorldPackSetTopUpService::buildOneSet()), so anything that takes the worker out mid-build —
 * a PHP fatal, an OOM kill, a container recreate on deploy — leaves a row in that state with
 * nothing running to finish or fail it.
 *
 * 🔴 Those rows are not inert, and that is what these tests pin. deficit() counts BUILDING
 * towards the buffer, deliberately, so a burst of game inits does not queue one build per init.
 * The consequence is that a country holding NOTHING a device can claim reports a deficit of
 * zero for the whole BUILD_STALE_AFTER window — and every top-up path reads that gate: the
 * admin button, the per-init dispatch, and the hourly sweep whose entire job is to notice a
 * country running dry. Measured on the dev stack 2026-11-21: England with 0 claimable sets of
 * 2 and 2 stranded BUILDING rows, and `app:worldpack:maintain` printing "EN at target".
 *
 * On 2026-11-20 that cost about three hours of device testing, because the only report anyone
 * got was success.
 */
class WorldPackStrandedBuildTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private WorldPackSetService $service;
    private WorldPackSetRepository $repository;
    private string $country;

    /** @var WorldPackSet[] */
    private array $cleanup = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em         = self::getContainer()->get(EntityManagerInterface::class);
        $this->service    = self::getContainer()->get(WorldPackSetService::class);
        $this->repository = self::getContainer()->get(WorldPackSetRepository::class);
        $this->country    = $this->unusedCountryCode();
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $set) {
            $managed = $this->em->find(WorldPackSet::class, (string) $set->getId());
            if ($managed !== null) {
                $this->em->remove($managed);
            }
        }
        $this->em->flush();
        $this->cleanup = [];

        parent::tearDown();
    }

    /**
     * The trap itself, stated as a test so it cannot quietly come back: zero claimable stock
     * and a zero deficit at the same time.
     */
    public function testAFreshBuildingSetMakesTheDeficitZeroWhileNothingIsClaimable(): void
    {
        $this->makeSet(WorldPackSetStatus::BUILDING);
        $this->makeSet(WorldPackSetStatus::BUILDING);

        $stock = $this->service->stock($this->country, 2);

        self::assertSame(0, $stock['claimable'], 'precondition: no device can start a game here');
        self::assertSame(2, $stock['building']);
        self::assertSame(0, $stock['deficit'], 'the gate counts building sets as stock, by design');
        self::assertTrue(
            $stock['starved'],
            'a country with nothing claimable must be reported starved, not stocked',
        );
    }

    /** A country genuinely holding its buffer is NOT starved — the flag must stay specific. */
    public function testAStockedCountryIsNotReportedStarved(): void
    {
        $this->makeSet(WorldPackSetStatus::READY);
        $this->makeSet(WorldPackSetStatus::READY);

        $stock = $this->service->stock($this->country, 2);

        self::assertSame(2, $stock['claimable']);
        self::assertSame(0, $stock['deficit']);
        self::assertFalse($stock['starved']);
    }

    /** And neither is a country that is plainly short — that case already reported itself. */
    public function testAPlainlyShortCountryIsNotReportedStarved(): void
    {
        $stock = $this->service->stock($this->country, 2);

        self::assertSame(0, $stock['claimable']);
        self::assertSame(2, $stock['deficit']);
        self::assertFalse($stock['starved'], 'short is loud already; starved means the gate is lying');
    }

    /**
     * The reap: a build older than BUILD_STALE_AFTER becomes FAILED with a reason, and the
     * country is short again rather than silently stocked.
     */
    public function testAStaleBuildingSetIsReapedToFailedWithAReason(): void
    {
        $stranded = $this->makeSet(WorldPackSetStatus::BUILDING);
        $this->ageGeneratedAt($stranded, '2 hours');
        $this->em->clear();

        $reaped = $this->service->reapStaleBuilding($this->country);

        self::assertSame(1, $reaped);

        $reloaded = $this->em->find(WorldPackSet::class, (string) $stranded->getId());
        self::assertSame(WorldPackSetStatus::FAILED, $reloaded->getStatus());
        self::assertNotNull(
            $reloaded->getErrorMessage(),
            'a reaped build must carry a reason — an unexplained FAILED row is the thing this replaces',
        );
        self::assertStringContainsString('Build never completed', $reloaded->getErrorMessage());

        $stock = $this->service->stock($this->country, 2);
        self::assertSame(0, $stock['building'], 'the reaped row must stop counting as stock');
        self::assertSame(2, $stock['deficit'], 'and the country must be short again, so a top-up fires');
        self::assertFalse($stock['starved']);
    }

    /** A build that could still legitimately be running must be left alone. */
    public function testAFreshBuildingSetIsNotReaped(): void
    {
        $fresh = $this->makeSet(WorldPackSetStatus::BUILDING);

        self::assertSame(0, $this->service->reapStaleBuilding($this->country));

        $reloaded = $this->em->find(WorldPackSet::class, (string) $fresh->getId());
        self::assertSame(
            WorldPackSetStatus::BUILDING,
            $reloaded->getStatus(),
            'reaping work that is actually in flight would abandon a set a worker is still writing',
        );
    }

    /** FAILED stock is not stock, so a failed country stays visibly short. */
    public function testAFailedSetDoesNotCountTowardsTheBuffer(): void
    {
        $set = $this->makeSet(WorldPackSetStatus::BUILDING);
        $this->service->markFailed($set, 'tier generation blew up');

        $stock = $this->service->stock($this->country, 2);

        self::assertSame(0, $stock['claimable']);
        self::assertSame(0, $stock['building']);
        self::assertSame(2, $stock['deficit']);
    }

    private function makeSet(WorldPackSetStatus $status): WorldPackSet
    {
        $set = new WorldPackSet(
            $this->country,
            WorldInitializationService::WORLD_PACK_VERSION,
            ['players' => [['id' => 'p1']], 'staff' => [], 'scouts' => []],
            4,
        );
        $set->setStatus($status);
        if ($status === WorldPackSetStatus::READY) {
            $set->setReadyAt(new \DateTimeImmutable());
        }

        $this->em->persist($set);
        $this->em->flush();
        $this->cleanup[] = $set;

        return $set;
    }

    private function ageGeneratedAt(WorldPackSet $set, string $interval): void
    {
        $this->em->getConnection()->executeStatement(
            "UPDATE world_pack_set SET generated_at = NOW() - INTERVAL '{$interval}' WHERE id = :id",
            ['id' => $set->getId()->toRfc4122()],
        );
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
