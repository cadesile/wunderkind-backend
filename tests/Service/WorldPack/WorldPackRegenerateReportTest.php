<?php

declare(strict_types=1);

namespace App\Tests\Service\WorldPack;

use App\Entity\Club;
use App\Entity\League;
use App\Entity\NpcClub;
use App\Entity\User;
use App\Entity\WorldPack\WorldPackSet;
use App\Enum\CitySize;
use App\Enum\WorldPack\WorldPackSetStatus;
use App\Repository\WorldPack\WorldPackSetRepository;
use App\Service\WorldInitializationService;
use App\Service\WorldPack\WorldPackGenerationOrchestrator;
use App\Service\WorldPack\WorldPackSetService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * What a regenerate REPORTS about the stock it just cleared.
 *
 * The rebuild spares claimed sets on purpose, and a spared set keeps its tier payload rows at
 * the `payload_version` it was built at. So after a WORLD_PACK_VERSION bump a run that read
 * COMPLETED still leaves rows at the OLD version in `country_world_pack_cache` — and on
 * 2026-11-22 that was reported as "even after rebuilding the cache via the UI, some v14
 * elements persisted", i.e. read as a partial rebuild.
 *
 * It is not partial: those rows are unreachable twice over (the claim filters on the current
 * version, and `WorldPackCacheService::readTier` filters again per row). What was missing was
 * the rebuild SAYING it spared them, which is what these tests pin.
 */
class WorldPackRegenerateReportTest extends KernelTestCase
{
    private const COUNTRY = 'EN';
    private const TIER    = 8;

    private EntityManagerInterface $em;
    private WorldPackSetRepository $setRepository;
    private array $cleanup = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em            = self::getContainer()->get(EntityManagerInterface::class);
        $this->setRepository = self::getContainer()->get(WorldPackSetRepository::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $entity) {
            if ($entity instanceof WorldPackSet) {
                $managed = $this->em->find(WorldPackSet::class, (string) $entity->getId());
                if ($managed !== null) {
                    $managed->setClaimedByClub(null);
                    $this->em->remove($managed);
                }
            }
        }
        $this->em->flush();

        foreach (array_reverse($this->cleanup) as $entity) {
            if ($entity instanceof WorldPackSet) {
                continue;
            }
            $managed = $this->em->find($entity::class, $entity->getId());
            if ($managed !== null) {
                $this->em->remove($managed);
            }
        }
        $this->em->flush();
        $this->cleanup = [];

        parent::tearDown();
    }

    /**
     * The numbers the operator is owed: how many sets the clear removed, and how many it
     * deliberately left behind.
     */
    public function testARegenerateReportsWhatItClearedAndWhatItSpared(): void
    {
        $league = new League(self::COUNTRY, self::TIER, 'Regenerate Report Test League');
        $this->em->persist($league);
        $this->cleanup[] = $league;

        $npc = new NpcClub(
            'Regenerate Report FC', self::COUNTRY, self::TIER, 20,
            '#111111', '#eeeeee', 1_000_000, [], citySize: CitySize::MEDIUM,
        );
        $npc->setLeague($league);
        $this->em->persist($npc);
        $this->cleanup[] = $npc;
        $this->em->flush();

        // One set that will be SPARED, standing in for a device mid-initialisation. Built at
        // the version BEFORE the current one, which is the state the report is about: a
        // claimed set from before a bump, whose rows the rebuild cannot touch.
        $claimed = $this->makeReadySet(WorldInitializationService::WORLD_PACK_VERSION - 1);
        $club    = $this->makeClub('spared');
        $claimed->setStatus(WorldPackSetStatus::CLAIMED);
        $claimed->setClaimedByClub($club);
        $claimed->setClaimedAt(new \DateTimeImmutable());
        $this->em->flush();

        // One set that will be CLEARED.
        $this->makeReadySet(WorldInitializationService::WORLD_PACK_VERSION);

        // Measured, not assumed: the box may hold other EN stock.
        $unclaimedBefore = $this->countUnclaimed();
        $claimedBefore   = $this->setRepository->countByStatus(self::COUNTRY, WorldPackSetStatus::CLAIMED);

        /** @var WorldPackGenerationOrchestrator $orchestrator */
        $orchestrator = self::getContainer()->get(WorldPackGenerationOrchestrator::class);
        $result       = $orchestrator->startRun(self::COUNTRY, [self::TIER], 1);

        $this->cleanup[] = $result['run'];
        foreach ($result['sets'] as $set) {
            $this->cleanup[] = $set;
        }

        $this->assertSame(
            $unclaimedBefore,
            $result['clearedSets'],
            'the run must report every unclaimed set it removed',
        );
        $this->assertSame(
            $claimedBefore,
            $result['keptClaimedSets'],
            'and every claimed set it deliberately left behind',
        );
        $this->assertGreaterThanOrEqual(1, $result['keptClaimedSets']);

        $this->em->clear();
        $survivor = $this->em->find(WorldPackSet::class, (string) $claimed->getId());
        $this->assertNotNull($survivor, 'the claimed set must survive the rebuild');
        $this->assertSame(
            WorldInitializationService::WORLD_PACK_VERSION - 1,
            $survivor->getPayloadVersion(),
            'and keep the version it was built at — which is why the rebuild has to say it is there',
        );
    }

    /**
     * The spared set is NOT a hole in the rebuild: it cannot be claimed, so nothing can be
     * served out of it. This is the fact the report's wording rests on.
     */
    public function testASparedStaleSetIsNotClaimableAfterTheRebuild(): void
    {
        $stale = $this->makeReadySet(WorldInitializationService::WORLD_PACK_VERSION - 1);
        $club  = $this->makeClub('stalespared');
        $stale->setStatus(WorldPackSetStatus::CLAIMED);
        $stale->setClaimedByClub($club);
        $stale->setClaimedAt(new \DateTimeImmutable());
        $this->em->flush();

        $this->assertFalse($stale->isClaimable(WorldInitializationService::WORLD_PACK_VERSION));
    }

    private function countUnclaimed(): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            "SELECT count(*) FROM world_pack_set WHERE country = :c AND claimed_by_club_id IS NULL",
            ['c' => self::COUNTRY],
        );
    }

    private function makeReadySet(int $payloadVersion): WorldPackSet
    {
        $set = new WorldPackSet(
            self::COUNTRY,
            $payloadVersion,
            ['players' => [['id' => 'p1']], 'staff' => [], 'scouts' => []],
            1,
        );
        $set->setStatus(WorldPackSetStatus::READY);
        $set->setReadyAt(new \DateTimeImmutable());

        $this->em->persist($set);
        $this->em->flush();
        $this->cleanup[] = $set;

        return $set;
    }

    private function makeClub(string $suffix): Club
    {
        $user = new User(sprintf('regen-report-%s@example.test', $suffix));
        $user->setPassword('not-a-real-hash');
        $this->em->persist($user);

        $club = new Club(sprintf('Regenerate Report %s', $suffix), $user);
        $club->setCountry(self::COUNTRY);
        $this->em->persist($club);
        $this->em->flush();

        $this->cleanup[] = $club;
        $this->cleanup[] = $user;

        return $club;
    }
}
